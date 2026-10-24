<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Medicine;
use App\Models\StockMovement;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies a branch inventory feed (already translated to JSON by integration-service) with ABSOLUTE values.
 *
 * - A medicine is found by its natural key (generic name, brand name, strength, dosage form), case ignored.
 *   Medicines are never created here.
 * - Each row is checked on its own and rejected with a code; good rows are not affected by bad ones (unless strict).
 * - Everything runs in one transaction, with the affected stock rows locked (ordered by id).
 * - Idempotent: a row that already has exactly these values is left alone (no version bump, no movement), so sending
 *   the same feed again changes nothing.
 *
 * Row codes: UNKNOWN_MEDICINE, INVALID_QUANTITY, INVALID_PRICE, PAST_EXPIRATION, BELOW_RESERVED, DUPLICATE_ITEM,
 * and two extra guards for direct callers: INVALID_MIN_THRESHOLD, INVALID_BATCH_NO.
 */
class InventorySyncService
{
    private const DEFAULT_MIN_THRESHOLD = 10;
    private const MAX_QUANTITY = 1000000;

    /**
     * @param list<array<string, mixed>> $items
     * @return array{accepted: int, rejected: int, unchanged: int, errors: list<array{row: int, code: string, message: string}>}
     */
    public function sync(int $branchId, array $items, bool $strict, int $actorId, ?string $requestId): array
    {
        Branch::findOrFail($branchId);

        [$candidates, $errors] = $this->prepare(array_values($items));

        try {
            return DB::transaction(function () use ($branchId, $candidates, $errors, $strict, $actorId, $requestId) {
                $rows = BranchInventory::where('branch_id', $branchId)
                    ->whereIn('medicine_id', array_map(fn (array $c) => $c['medicine']->id, $candidates))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('medicine_id');

                // Checked under the lock: the reserved quantity can change at any moment.
                $valid = [];
                foreach ($candidates as $candidate) {
                    $existing = $rows->get($candidate['medicine']->id);
                    if ($existing !== null && $candidate['quantity'] < $existing->reserved_quantity) {
                        $errors[] = $this->error($candidate['row'], 'BELOW_RESERVED', "The new quantity is below the {$existing->reserved_quantity} units currently reserved.");

                        continue;
                    }
                    $valid[] = $candidate;
                }

                usort($errors, fn (array $a, array $b) => $a['row'] <=> $b['row']);

                if ($strict && $errors !== []) {
                    // Nothing has been written yet, so "roll back everything" is simply: write nothing.
                    throw new ApiException('FEED_REJECTED', 'The feed was rejected: strict mode applies all rows or none.', 422, [
                        'accepted' => 0,
                        'rejected' => count($errors),
                        'errors' => $errors,
                    ]);
                }

                // Existing rows in lock order (by id), then new rows by medicine id: a stable order avoids deadlocks.
                usort($valid, fn (array $a, array $b) => $this->applyOrder($a, $b, $rows));

                $unchanged = 0;
                foreach ($valid as $candidate) {
                    $unchanged += $this->apply($branchId, $candidate, $rows->get($candidate['medicine']->id), $actorId, $requestId) ? 0 : 1;
                }

                return [
                    'accepted' => count($valid),
                    'rejected' => count($errors),
                    'unchanged' => $unchanged,
                    'errors' => $errors,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created the same (branch, medicine) stock row at the same moment.
            throw new ApiException('CONFLICT', 'A stock row was created by someone else at the same time. Send the feed again.', 409);
        }
    }

    // ------------------------------------------------------------------ row checks (no database writes)

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: list<array<string, mixed>>, 1: list<array{row: int, code: string, message: string}>}
     */
    private function prepare(array $items): array
    {
        $medicines = $this->medicinesByKey($items);
        $seen = [];
        $candidates = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $row = (int) ($item['row'] ?? $index + 1);
            $key = $this->key($item['generic_name'] ?? '', $item['brand_name'] ?? '', $item['strength'] ?? '', $item['dosage_form'] ?? '');

            if (isset($seen[$key])) {
                $errors[] = $this->error($row, 'DUPLICATE_ITEM', "This medicine already appears in row {$seen[$key]}.");

                continue;
            }
            $seen[$key] = $row;

            $medicine = $medicines[$key] ?? null;
            if ($medicine === null) {
                $errors[] = $this->error($row, 'UNKNOWN_MEDICINE', 'No medicine with this generic name, brand, strength and dosage form exists.');

                continue;
            }

            $quantity = $this->quantity($item['quantity'] ?? null);
            if ($quantity === null) {
                $errors[] = $this->error($row, 'INVALID_QUANTITY', 'The quantity must be a whole number from 0 to '.self::MAX_QUANTITY.'.');

                continue;
            }

            $price = $this->price($item['price'] ?? null);
            if ($price === null) {
                $errors[] = $this->error($row, 'INVALID_PRICE', 'The price must be above 0 with at most 2 decimals.');

                continue;
            }

            $expiration = $this->futureDate($item['expiration_date'] ?? null);
            if ($expiration === null) {
                $errors[] = $this->error($row, 'PAST_EXPIRATION', 'The expiration date must be a valid date in the future.');

                continue;
            }

            $batch = $item['batch_no'] ?? null;
            if ($batch !== null && (! is_scalar($batch) || mb_strlen(trim((string) $batch)) > 50)) {
                $errors[] = $this->error($row, 'INVALID_BATCH_NO', 'The batch number must be at most 50 characters.');

                continue;
            }

            $threshold = $item['min_threshold'] ?? null;
            if ($threshold !== null && (! is_int($threshold) || $threshold < 0 || $threshold > self::MAX_QUANTITY)) {
                $errors[] = $this->error($row, 'INVALID_MIN_THRESHOLD', 'The minimum threshold must be a whole number of at least 0.');

                continue;
            }

            $candidates[] = [
                'row' => $row,
                'medicine' => $medicine,
                'quantity' => $quantity,
                'price' => $price,
                'batch_no' => $batch === null || trim((string) $batch) === '' ? null : trim((string) $batch),
                'expiration_date' => $expiration,
                'min_threshold' => $threshold,
            ];
        }

        return [$candidates, $errors];
    }

    /**
     * Looks every medicine up by natural key with exact matching that ignores case.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, Medicine>
     */
    private function medicinesByKey(array $items): array
    {
        $names = [];
        foreach ($items as $item) {
            $name = mb_strtolower(trim((string) ($item['generic_name'] ?? '')));
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        if ($names === []) {
            return [];
        }

        $found = [];
        foreach (Medicine::whereIn(DB::raw('LOWER(generic_name)'), array_keys($names))->get() as $medicine) {
            // An empty brand in the feed matches the stored empty string.
            $found[$this->key($medicine->generic_name, $medicine->brand_name, $medicine->strength, $medicine->dosage_form)] = $medicine;
        }

        return $found;
    }

    private function key(mixed $generic, mixed $brand, mixed $strength, mixed $form): string
    {
        return implode("\x1F", array_map(fn ($part) => mb_strtolower(trim((string) $part)), [$generic, $brand, $strength, $form]));
    }

    private function quantity(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,9}$/', trim($value))) {
            $value = (int) trim($value);
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX_QUANTITY ? $value : null;
    }

    private function price(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $text = trim((string) $value);

        return preg_match('/^\d{1,8}(\.\d{1,2})?$/', $text) && (float) $text > 0 ? number_format((float) $text, 2, '.', '') : null;
    }

    /** Returns the date as Y-m-d when it is valid and after today. */
    private function futureDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $valid = $date !== false && $date->format('Y-m-d') === trim($value);

        return $valid && $date->format('Y-m-d') > now()->toDateString() ? $date->format('Y-m-d') : null;
    }

    // ------------------------------------------------------------------ writing

    /**
     * Writes one row. Returns false when the row already had exactly these values (nothing was written).
     *
     * @param array<string, mixed> $c
     */
    private function apply(int $branchId, array $c, ?BranchInventory $existing, int $actorId, ?string $requestId): bool
    {
        /** @var Medicine $medicine */
        $medicine = $c['medicine'];

        if ($existing === null) { // a first stock entry, created the same way the PUT endpoint does it
            $threshold = $c['min_threshold'] ?? self::DEFAULT_MIN_THRESHOLD;
            $row = BranchInventory::create([
                'branch_id' => $branchId,
                'medicine_id' => $medicine->id,
                'price' => $c['price'],
                'stock_quantity' => $c['quantity'],
                'reserved_quantity' => 0,
                'min_threshold' => $threshold,
                'batch_no' => $c['batch_no'],
                'expiration_date' => $c['expiration_date'],
                'status' => BranchInventory::statusFor($c['quantity'], $threshold),
                'version' => 1,
            ]);
            $this->movement($row, $c['quantity'], $actorId, $requestId);

            return true;
        }

        $threshold = $c['min_threshold'] ?? $existing->min_threshold;
        $same = $existing->stock_quantity === $c['quantity']
            && number_format((float) $existing->price, 2, '.', '') === $c['price']
            && $existing->batch_no === $c['batch_no']
            && $existing->expiration_date?->toDateString() === $c['expiration_date']
            && $existing->min_threshold === $threshold;

        if ($same) {
            return false; // idempotent: no version bump, no movement
        }

        $delta = $c['quantity'] - $existing->stock_quantity;
        $existing->fill([
            'price' => $c['price'],
            'stock_quantity' => $c['quantity'],
            'min_threshold' => $threshold,
            'batch_no' => $c['batch_no'],
            'expiration_date' => $c['expiration_date'],
            'status' => BranchInventory::statusFor($c['quantity'], $threshold),
            'version' => $existing->version + 1,
        ])->save();
        $this->movement($existing, $delta, $actorId, $requestId);

        return true;
    }

    private function movement(BranchInventory $row, int $delta, int $actorId, ?string $requestId): void
    {
        StockMovement::create([
            'branch_inventory_id' => $row->id,
            'delta' => $delta,
            'reason' => 'sync',
            'actor_id' => $actorId,
            'request_id' => $requestId,
        ]);
    }

    /** Existing rows first, in the order they were locked (by id); then new rows, by medicine id. */
    private function applyOrder(array $a, array $b, $rows): int
    {
        $rowA = $rows->get($a['medicine']->id);
        $rowB = $rows->get($b['medicine']->id);

        return match (true) {
            $rowA && $rowB => $rowA->id <=> $rowB->id,
            (bool) $rowA => -1,
            (bool) $rowB => 1,
            default => $a['medicine']->id <=> $b['medicine']->id,
        };
    }

    /** @return array{row: int, code: string, message: string} */
    private function error(int $row, string $code, string $message): array
    {
        return ['row' => $row, 'code' => $code, 'message' => $message];
    }
}
