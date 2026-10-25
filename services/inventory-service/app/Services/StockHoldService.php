<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockHold;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Atomic stock holds (plan 10.3): hold -> commit | release | expire.
 *
 * - available = stock_quantity - reserved_quantity (never stored).
 * - A hold only raises reserved_quantity; commit lowers stock and reserved together; release lowers reserved only.
 * - Every write to branch_inventory is a guarded UPDATE, so reserved_quantity can never exceed stock_quantity
 *   or drop below 0 even where row locks do not exist (SQLite) and on top of the MySQL CHECK constraint.
 * - Lock order is always: branch_inventory rows by id, then stock_holds rows. That keeps concurrent requests deadlock-free.
 * - A reservation_ref is the handle for the whole reservation; it has one stock_holds row per item.
 */
class StockHoldService
{
    /**
     * @param list<array{medicine_id: int, quantity: int}> $items
     * @return array{replayed: bool, data: array<string, mixed>}
     */
    public function create(string $reservationRef, int $branchId, array $items, ?int $ttlMinutes = null): array
    {
        $wanted = $this->normalize($items);

        // Idempotency first: the same reservation_ref again must never reserve twice.
        if ($existing = $this->holdsOf($reservationRef)) {
            return $this->replay($reservationRef, $existing, $branchId, $wanted);
        }

        Branch::where('is_active', true)->find($branchId)
            ?? throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);

        $expiresAt = now()->addMinutes($ttlMinutes ?? (int) config('inventory.hold_ttl_hours', 48) * 60);

        try {
            DB::transaction(fn () => $this->reserve($reservationRef, $branchId, $wanted, $expiresAt));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same reservation_ref won the race: treat this one as its replay.
            $existing = $this->holdsOf($reservationRef);
            if ($existing === null) {
                throw new ApiException('CONFLICT', 'The hold could not be created. Try again.', 409);
            }

            return $this->replay($reservationRef, $existing, $branchId, $wanted);
        }

        return ['replayed' => false, 'data' => $this->present($reservationRef)];
    }

    /** Reservation completed: stock leaves the shelf. All holds must still be held. */
    public function commit(string $reservationRef, ?string $requestId = null): array
    {
        DB::transaction(function () use ($reservationRef, $requestId) {
            [$holds, $rows] = $this->lockForChange($reservationRef);

            if ($holds->contains(fn (StockHold $h) => $h->status !== 'held')) {
                throw $this->invalidState($holds);
            }

            foreach ($holds as $hold) {
                $row = $rows[$hold->branch_inventory_id];
                $qty = $hold->quantity;
                $newStock = $row->stock_quantity - $qty;

                $changed = BranchInventory::whereKey($row->id)
                    ->where('stock_quantity', '>=', $qty)
                    ->where('reserved_quantity', '>=', $qty)
                    ->update([
                        'stock_quantity' => DB::raw('stock_quantity - '.$qty),
                        'reserved_quantity' => DB::raw('reserved_quantity - '.$qty),
                        'status' => BranchInventory::statusFor($newStock, $row->min_threshold),
                        // stock_quantity changed, so staff holding an older copy must reload (optimistic lock)
                        'version' => DB::raw('version + 1'),
                    ]);
                $this->assertGuardedUpdate($changed, 'commit', $hold);

                StockMovement::create([
                    'branch_inventory_id' => $row->id,
                    'delta' => -$qty,
                    'reason' => 'hold_commit',
                    'actor_id' => null,
                    'request_id' => $requestId,
                ]);

                $hold->update(['status' => 'committed']);
            }
        });

        return $this->present($reservationRef);
    }

    /**
     * Reservation cancelled or rejected: reserved stock goes back. Safe to call again:
     * holds that are already released or expired are left alone and answered with 200.
     */
    public function release(string $reservationRef): array
    {
        DB::transaction(function () use ($reservationRef) {
            [$holds, $rows] = $this->lockForChange($reservationRef);

            if ($holds->contains(fn (StockHold $h) => $h->status === 'committed')) {
                throw $this->invalidState($holds);
            }

            foreach ($holds->where('status', 'held') as $hold) {
                $this->giveBack($rows[$hold->branch_inventory_id], $hold, 'released');
            }
        });

        return $this->present($reservationRef);
    }

    /** @return array<string, mixed> */
    public function show(string $reservationRef): array
    {
        if ($this->holdsOf($reservationRef) === null) {
            throw $this->notFound();
        }

        return $this->present($reservationRef);
    }

    /**
     * Releases every held hold whose expires_at has passed (status expired). Safe to run repeatedly.
     *
     * @return array{reservations: int, holds: int}
     */
    public function expireDue(): array
    {
        $refs = StockHold::where('status', 'held')
            ->where('expires_at', '<=', now())
            ->distinct()
            ->orderBy('reservation_ref')
            ->pluck('reservation_ref');

        $reservations = 0;
        $holdCount = 0;

        foreach ($refs as $ref) {
            $count = DB::transaction(function () use ($ref) {
                $ids = StockHold::where('reservation_ref', $ref)->pluck('branch_inventory_id')->unique()->sort()->values();
                $rows = BranchInventory::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                // Re-read under the lock: another worker may have released or committed it meanwhile.
                $due = StockHold::where('reservation_ref', $ref)
                    ->where('status', 'held')
                    ->where('expires_at', '<=', now())
                    ->orderBy('branch_inventory_id')
                    ->lockForUpdate()
                    ->get();

                foreach ($due as $hold) {
                    $this->giveBack($rows[$hold->branch_inventory_id], $hold, 'expired');
                }

                return $due->count();
            });

            if ($count > 0) {
                $reservations++;
                $holdCount += $count;
            }
        }

        return ['reservations' => $reservations, 'holds' => $holdCount];
    }

    // ------------------------------------------------------------------ internals

    /**
     * All or nothing: lock every affected row (ordered by id), check availability for every item,
     * and only then reserve. Any shortage rolls the whole transaction back.
     *
     * @param array<int, int> $wanted medicine_id => quantity
     */
    private function reserve(string $reservationRef, int $branchId, array $wanted, \DateTimeInterface $expiresAt): void
    {
        $rows = BranchInventory::where('branch_id', $branchId)
            ->whereIn('medicine_id', array_keys($wanted))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('medicine_id');

        $missing = array_values(array_diff(array_keys($wanted), $rows->keys()->all()));
        if ($missing !== []) {
            throw new ApiException('NOT_FOUND', 'One or more medicines are not stocked at this branch.', 404, ['medicine_ids' => $missing]);
        }

        $report = [];
        $short = false;
        foreach ($wanted as $medicineId => $quantity) {
            $row = $rows[$medicineId];
            $available = $row->stock_quantity - $row->reserved_quantity;
            $report[] = [
                'medicine_id' => $medicineId,
                'requested' => $quantity,
                'available' => max(0, $available),
                'sufficient' => $available >= $quantity,
            ];
            $short = $short || $available < $quantity;
        }
        if ($short) {
            throw $this->insufficient($report);
        }

        foreach ($rows->sortBy('id') as $medicineId => $row) {
            $quantity = $wanted[$medicineId];

            // Guarded: can never push reserved_quantity above stock_quantity.
            $changed = BranchInventory::whereKey($row->id)
                ->whereRaw('reserved_quantity + ? <= stock_quantity', [$quantity])
                ->update(['reserved_quantity' => DB::raw('reserved_quantity + '.$quantity)]);
            if ($changed !== 1) {
                throw $this->insufficient($report);
            }

            StockHold::create([
                'hold_ref' => (string) Str::uuid(),
                'reservation_ref' => $reservationRef,
                'branch_inventory_id' => $row->id,
                'quantity' => $quantity,
                'status' => 'held',
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /** reserved_quantity down, never below 0; the hold becomes released or expired. */
    private function giveBack(BranchInventory $row, StockHold $hold, string $newStatus): void
    {
        $changed = BranchInventory::whereKey($row->id)
            ->where('reserved_quantity', '>=', $hold->quantity)
            ->update(['reserved_quantity' => DB::raw('reserved_quantity - '.$hold->quantity)]);
        $this->assertGuardedUpdate($changed, $newStatus, $hold);

        $hold->update(['status' => $newStatus]);
    }

    /**
     * Locks inventory rows (ordered by id) and then the reservation's holds, in the same order everywhere.
     *
     * @return array{0: Collection<int, StockHold>, 1: \Illuminate\Support\Collection<int, BranchInventory>}
     */
    private function lockForChange(string $reservationRef): array
    {
        $ids = StockHold::where('reservation_ref', $reservationRef)->pluck('branch_inventory_id')->unique()->sort()->values();
        if ($ids->isEmpty()) {
            throw $this->notFound();
        }

        $rows = BranchInventory::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $holds = StockHold::where('reservation_ref', $reservationRef)->orderBy('branch_inventory_id')->lockForUpdate()->get();

        return [$holds, $rows];
    }

    /** @return Collection<int, StockHold>|null */
    private function holdsOf(string $reservationRef): ?Collection
    {
        $holds = StockHold::with('inventory')->where('reservation_ref', $reservationRef)->orderBy('branch_inventory_id')->get();

        return $holds->isEmpty() ? null : $holds;
    }

    /**
     * Same reservation_ref again: the same body replays the stored result (nothing reserved twice);
     * a different body is a client bug and is refused.
     *
     * @param Collection<int, StockHold> $existing
     * @param array<int, int> $wanted
     * @return array{replayed: bool, data: array<string, mixed>}
     */
    private function replay(string $reservationRef, Collection $existing, int $branchId, array $wanted): array
    {
        $storedBranch = $existing->first()->inventory->branch_id;
        $stored = $existing->mapWithKeys(fn (StockHold $h) => [$h->inventory->medicine_id => $h->quantity])->sortKeys()->all();

        if ($storedBranch !== $branchId || $stored !== $wanted) {
            throw new ApiException('DUPLICATE_REQUEST', 'This reservation_ref was already used with a different request.', 409, [
                'reservation_ref' => $reservationRef,
            ]);
        }

        return ['replayed' => true, 'data' => $this->present($reservationRef)];
    }

    /**
     * @param list<array{medicine_id: int, quantity: int}> $items
     * @return array<int, int> medicine_id => quantity, sorted by medicine_id
     */
    private function normalize(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[(int) $item['medicine_id']] = (int) $item['quantity'];
        }
        ksort($map);

        return $map;
    }

    /** @return array<string, mixed> */
    private function present(string $reservationRef): array
    {
        $holds = StockHold::with('inventory.medicine')->where('reservation_ref', $reservationRef)->orderBy('branch_inventory_id')->get();
        $statuses = $holds->pluck('status')->unique();

        return [
            'reservation_ref' => $reservationRef,
            'branch_id' => $holds->first()->inventory->branch_id,
            'status' => $statuses->count() === 1 ? $statuses->first() : 'mixed',
            'expires_at' => $holds->sortBy('expires_at')->first()->expires_at->toIso8601String(),
            'items' => $holds->map(fn (StockHold $h) => [
                'medicine_id' => $h->inventory->medicine_id,
                // Added for reservation-service, which snapshots them (the price is the branch's current price).
                'medicine_name' => $this->medicineName($h->inventory->medicine),
                'unit_price' => (string) $h->inventory->price,
                'prescription_required' => (bool) $h->inventory->medicine->prescription_required,
                'quantity' => $h->quantity,
                'status' => $h->status,
            ])->sortBy('medicine_id')->values()->all(),
        ];
    }

    /** For example "Paracetamol (Biogesic) 500mg Tablet"; the brand is left out for unbranded medicines. */
    private function medicineName(Medicine $medicine): string
    {
        $brand = $medicine->brand_name === '' ? '' : " ({$medicine->brand_name})";

        return "{$medicine->generic_name}{$brand} {$medicine->strength} {$medicine->dosage_form}";
    }

    /** @param array<int, array<string, mixed>> $report */
    private function insufficient(array $report): ApiException
    {
        return new ApiException('STOCK_INSUFFICIENT', 'Not enough stock available for every requested item.', 409, ['items' => $report]);
    }

    private function notFound(): ApiException
    {
        return new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
    }

    /** @param Collection<int, StockHold> $holds */
    private function invalidState(Collection $holds): ApiException
    {
        return new ApiException('INVALID_HOLD_STATE', 'The holds of this reservation are not in a state that allows this action.', 409, [
            'statuses' => $holds->pluck('status', 'id')->all(),
        ]);
    }

    /** A guarded update that matched no row means an invariant would have been broken: abort and roll back. */
    private function assertGuardedUpdate(int $changed, string $action, StockHold $hold): void
    {
        if ($changed !== 1) {
            throw new RuntimeException("Stock invariant would be violated while trying to {$action} hold #{$hold->id}.");
        }
    }
}
