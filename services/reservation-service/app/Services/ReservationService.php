<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Support\UserContext;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Plan F3, the core transaction. In order:
 *   1. idempotency key row (the unique key decides races);  2. medicine lookup (prescription needed?);
 *   3. stock hold in inventory-service;  4. one DB transaction: reservation, items, first history row, prescription file.
 * There is no distributed transaction: if step 4 fails after step 3, the hold is released (compensation, plan section 14).
 */
class ReservationService
{
    /** No I, L, O, 0 or 1: easy to read out loud and to type from a phone screen. 31 symbols, 8 characters. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_ATTEMPTS = 5;

    public function __construct(private readonly InventoryClient $inventory) {}

    /**
     * @param array{branch_id: int|string, items: list<array<string, mixed>>, notes?: ?string} $data validated input
     * @return array{0: int, 1: array<string, mixed>} HTTP status and the `data` of the answer
     */
    public function create(UserContext $user, array $data, ?UploadedFile $file, string $key, ?string $requestId): array
    {
        if ($user->role !== 'customer') {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        $branchId = (int) $data['branch_id'];
        $items = array_map(fn (array $i) => ['medicine_id' => (int) $i['medicine_id'], 'quantity' => (int) $i['quantity']], array_values($data['items']));
        $notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null;
        $hash = $this->requestHash($branchId, $items, $notes, $file);

        // The key row goes in first. Two simultaneous requests with the same key cannot both insert it.
        try {
            $record = IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $key,
                'request_hash' => $hash,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($user->id, $key, $hash);
        }

        try {
            [$status, $payload] = $this->place($user, $branchId, $items, $notes, $file, $key, $requestId);
        } catch (Throwable $e) {
            // A failed attempt must not block a retry with the same key (for example after restocking).
            IdempotencyKey::whereKey($record->id)->delete();
            throw $e;
        }

        try {
            $record->update(['status_code' => $status, 'response_json' => $payload]);
        } catch (Throwable $e) {
            Log::error('Idempotency record could not be completed', ['request_id' => $requestId, 'exception' => $e::class]);
        }

        return [$status, $payload];
    }

    /**
     * Owner, staff of the same branch, or the CEO. Anyone else gets 403 (for every existing reservation);
     * an id that does not exist is 404.
     *
     * @return array<string, mixed>
     */
    public function show(UserContext $user, int $id): array
    {
        $reservation = Reservation::with(['items', 'history'])->find($id)
            ?? throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);

        $allowed = $reservation->user_id === $user->id
            || $user->role === 'ceo'
            || ($user->hasRole(['pharmacy_technician', 'pharmacy_assistant', 'branch_manager']) && $user->branchId === $reservation->branch_id);

        if (! $allowed) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        return $this->present($reservation, true);
    }

    // ------------------------------------------------------------------ the create flow

    /**
     * @param list<array{medicine_id: int, quantity: int}> $items
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function place(UserContext $user, int $branchId, array $items, ?string $notes, ?UploadedFile $file, string $key, ?string $requestId): array
    {
        // 1. What are these medicines, and is a prescription needed? (404 for an unknown medicine.) Before any stock is touched.
        $medicines = [];
        foreach ($items as $item) {
            $medicines[$item['medicine_id']] = $this->inventory->medicine($item['medicine_id']);
        }
        $needsPrescription = array_values(array_keys(array_filter($medicines, fn (array $m) => ($m['prescription_required'] ?? false) === true)));
        if ($needsPrescription !== [] && $file === null) {
            throw new ApiException('PRESCRIPTION_REQUIRED', 'A prescription image is required for one or more of these medicines.', 422, ['medicine_ids' => $needsPrescription]);
        }

        // 2. Hold the stock. The hold and the reservation expire together (same ttl).
        $ttlHours = (int) config('reservation.ttl_hours', 48);
        $holdRef = (string) Str::uuid();
        try {
            $hold = $this->inventory->createHold($holdRef, $branchId, $items, $ttlHours * 60);
        } catch (ApiException $e) {
            // After a timeout or an unreadable answer the hold may exist: give it back (best effort, one attempt).
            if (in_array($e->errorCode, ['UPSTREAM_TIMEOUT', 'BAD_UPSTREAM_RESPONSE'], true)) {
                $this->releaseOnce($holdRef, $requestId);
            }
            throw $e;
        }

        // 3. Everything from here on must give the hold back if it fails.
        $path = null;
        try {
            $lines = $this->snapshotLines($items, $hold);
            $expiresAt = $this->expiry($hold, $ttlHours);

            $reservation = DB::transaction(function () use ($user, $branchId, $notes, $file, $key, $requestId, $holdRef, $lines, $expiresAt, $needsPrescription, &$path) {
                $reservation = $this->insertReservation([
                    'user_id' => $user->id,
                    'branch_id' => $branchId,
                    'status' => 'pending',
                    'ocr_status' => $needsPrescription === [] ? 'not_required' : 'not_processed',
                    'hold_ref' => $holdRef,
                    'notes' => $notes,
                    'expires_at' => $expiresAt,
                    'idempotency_key' => $key,
                ]);

                $reservation->items()->createMany($lines);

                ReservationStatusHistory::create([
                    'reservation_id' => $reservation->id,
                    'from_status' => null,
                    'to_status' => 'pending',
                    'actor_id' => $user->id,
                    'request_id' => $requestId,
                    'created_at' => now(),
                ]);

                // The file is saved last, once the rows are written; a failure here rolls the rows back.
                if ($file !== null) {
                    $stored = $file->store('prescriptions', config('reservation.files_disk')); // random file name
                    if (! is_string($stored) || $stored === '') {
                        throw new RuntimeException('The prescription file could not be stored.');
                    }
                    $path = $stored;
                    $reservation->update(['prescription_path' => $path]);
                }

                return $reservation;
            });
        } catch (Throwable $e) {
            if ($path !== null) {
                try {
                    Storage::disk(config('reservation.files_disk'))->delete($path);
                } catch (Throwable) {
                    // best effort: an orphan file is harmless (private disk), a failing cleanup must not hide the real error
                }
            }
            $this->compensate($holdRef, $requestId);
            Log::error('Reservation could not be saved; stock hold given back', [
                'request_id' => $requestId,
                'reservation_ref' => $holdRef,
                'exception' => $e::class,
            ]);

            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
        }

        return [201, $this->present($reservation->load(['items']), false)];
    }

    /**
     * Prices and names come from the hold answer (the branch's current price), not from the client.
     *
     * @param list<array{medicine_id: int, quantity: int}> $items
     * @param array<string, mixed> $hold
     * @return list<array<string, mixed>>
     */
    private function snapshotLines(array $items, array $hold): array
    {
        $held = collect($hold['items'] ?? [])->filter(fn ($h) => is_array($h))->keyBy(fn (array $h) => (int) ($h['medicine_id'] ?? 0));

        $lines = [];
        foreach ($items as $item) {
            $line = $held->get($item['medicine_id']);
            if (! is_array($line) || ! is_string($line['medicine_name'] ?? null) || ! is_numeric($line['unit_price'] ?? null)) {
                throw new ApiException('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
            }
            $lines[] = [
                'medicine_id' => $item['medicine_id'],
                'medicine_name' => mb_substr($line['medicine_name'], 0, 255),
                'unit_price' => $line['unit_price'],
                'quantity' => $item['quantity'],
            ];
        }

        return $lines;
    }

    /** The hold's own expiry, so reservation and hold agree to the second; computed from the ttl if it is missing. */
    private function expiry(array $hold, int $ttlHours): Carbon
    {
        try {
            if (is_string($hold['expires_at'] ?? null)) {
                return Carbon::parse($hold['expires_at']);
            }
        } catch (Throwable) {
            // fall through
        }

        return now()->addHours($ttlHours);
    }

    /**
     * claim_code is random (CSPRNG) and UNIQUE. On the (very unlikely) clash a new code is drawn.
     *
     * @param array<string, mixed> $attributes
     */
    private function insertReservation(array $attributes): Reservation
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // A savepoint inside the outer transaction: a failed insert does not poison the rest of it.
                return DB::transaction(fn () => Reservation::create($attributes + ['claim_code' => $this->newClaimCode()]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /** "PA-" followed by 8 characters from CODE_ALPHABET, e.g. PA-7K3Q9X2M. */
    protected function newClaimCode(): string
    {
        $code = '';
        $last = strlen(self::CODE_ALPHABET) - 1;
        for ($i = 0; $i < 8; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $last)];
        }

        return 'PA-'.$code;
    }

    // ------------------------------------------------------------------ idempotency

    /**
     * The same user, the same key, the same request: the stored answer comes back unchanged and nothing new is created.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function replay(int $userId, string $key, string $hash): array
    {
        $existing = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();

        if ($existing === null) {
            // The first attempt failed and removed its key between our insert and this read: ask the client to try again.
            throw new ApiException('CONFLICT', 'The request could not be completed. Try again.', 409);
        }
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new ApiException('DUPLICATE_REQUEST', 'This Idempotency-Key was already used with a different request.', 409);
        }
        if ($existing->response_json === null || $existing->status_code === null) {
            throw new ApiException('DUPLICATE_REQUEST', 'A request with this Idempotency-Key is still being processed.', 409, ['reason' => 'in_progress']);
        }

        return [$existing->status_code, $existing->response_json];
    }

    /** @param list<array{medicine_id: int, quantity: int}> $items */
    private function requestHash(int $branchId, array $items, ?string $notes, ?UploadedFile $file): string
    {
        usort($items, fn (array $a, array $b) => $a['medicine_id'] <=> $b['medicine_id']);

        return hash('sha256', json_encode([
            'branch_id' => $branchId,
            'items' => $items,
            'notes' => $notes,
            'prescription' => $file !== null ? hash_file('sha256', $file->getRealPath()) : null,
        ], JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------------ compensation

    /** Gives the hold back, trying up to `release_attempts` times with a short pause. A failure is logged, never thrown. */
    private function compensate(string $holdRef, ?string $requestId): void
    {
        $attempts = max(1, (int) config('reservation.release_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->inventory->release($holdRef);

                return;
            } catch (Throwable) {
                if ($attempt < $attempts) {
                    usleep(max(0, (int) config('reservation.release_pause_ms', 200)) * 1000);
                }
            }
        }

        // Needs a manual fix: the hold will still be released by inventory-service when it expires.
        Log::error('Stock hold could not be released', ['request_id' => $requestId, 'reservation_ref' => $holdRef, 'attempts' => $attempts]);
    }

    private function releaseOnce(string $holdRef, ?string $requestId): void
    {
        try {
            $this->inventory->release($holdRef);
        } catch (Throwable) {
            Log::error('Stock hold could not be released', ['request_id' => $requestId, 'reservation_ref' => $holdRef, 'attempts' => 1]);
        }
    }

    // ------------------------------------------------------------------ presentation

    /**
     * Never includes prescription_path.
     *
     * @return array<string, mixed>
     */
    private function present(Reservation $reservation, bool $withHistory): array
    {
        $data = [
            'id' => $reservation->id,
            'claim_code' => $reservation->claim_code,
            'user_id' => $reservation->user_id,
            'branch_id' => $reservation->branch_id,
            'status' => $reservation->status,
            'prescription_uploaded' => $reservation->prescription_path !== null,
            'ocr_text' => $reservation->ocr_text,
            'ocr_status' => $reservation->ocr_status,
            'items' => $reservation->items->map(fn ($i) => [
                'medicine_id' => $i->medicine_id,
                'medicine_name' => $i->medicine_name,
                'unit_price' => (string) $i->unit_price,
                'quantity' => $i->quantity,
            ])->all(),
            'notes' => $reservation->notes,
            'expires_at' => $reservation->expires_at->toIso8601String(),
            'created_at' => $reservation->created_at->toIso8601String(),
        ];

        if ($withHistory) {
            $data['history'] = $reservation->history->map(fn ($h) => [
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'reason' => $h->reason,
                'created_at' => $h->created_at->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}
