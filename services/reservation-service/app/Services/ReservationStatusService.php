<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Support\ReservationPresenter;
use App\Support\UserContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan F4 (staff and customer actions) and F5 (auto-expiry).
 *
 *   pending --approve--> approved --complete--> completed        (complete: commit the hold)
 *      |                    |
 *      +--reject--> rejected (release)      pending or approved --cancel--> cancelled (release)
 *      +-------- expiry: pending or approved --> expired (release) --------+
 *
 * Order of work for every action: (1) lock-read the reservation and validate the move, (2) call inventory-service
 * OUTSIDE any DB transaction, (3) update the status in a transaction guarded by WHERE status = <expected>, together
 * with the history row. Inventory is therefore never called while a row lock is held, and a crash between (2) and (3)
 * is repaired by simply repeating the action: release is idempotent, and a commit that finds the holds already
 * committed counts as done.
 */
class ReservationStatusService
{
    /** action => [allowed current statuses, new status, roles that may do it] */
    private const ACTIONS = [
        'approve' => ['from' => ['pending'], 'to' => 'approved', 'roles' => ['pharmacy_technician', 'branch_manager']],
        'reject' => ['from' => ['pending'], 'to' => 'rejected', 'roles' => ['pharmacy_technician', 'branch_manager']],
        'cancel' => ['from' => ['pending', 'approved'], 'to' => 'cancelled', 'roles' => ['customer']],
        'complete' => ['from' => ['approved'], 'to' => 'completed', 'roles' => ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager']],
    ];

    public function __construct(private readonly InventoryClient $inventory) {}

    /** @return list<string> roles that may ever perform the action; empty for an unknown action */
    public static function rolesFor(string $action): array
    {
        return self::ACTIONS[$action]['roles'] ?? [];
    }

    /**
     * @return array<string, mixed> the updated reservation (with history)
     *
     * @throws ApiException 403, 404, 409 INVALID_TRANSITION / RESERVATION_EXPIRED / HOLD_NOT_AVAILABLE, 422 INVALID_CLAIM_CODE, 5xx
     */
    public function change(UserContext $user, int $id, string $action, ?string $reason, ?string $claimCode, ?string $requestId): array
    {
        $rule = self::ACTIONS[$action] ?? throw new ApiException('VALIDATION_FAILED', 'The given data was invalid.', 422, ['action' => ['The selected action is invalid.']]);

        // Role first: a role that can never do this (the CEO, a customer approving, ...) is refused before anything is looked up.
        if (! $user->hasRole($rule['roles'])) {
            throw $this->forbidden();
        }

        // (1) validate under a lock; the lock is released before inventory-service is called
        $expected = DB::transaction(function () use ($user, $id, $action, $rule, $claimCode) {
            $reservation = Reservation::whereKey($id)->lockForUpdate()->first()
                ?? throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);

            $this->assertMayAct($user, $action, $reservation);

            if (! in_array($reservation->status, $rule['from'], true)) {
                throw new ApiException('INVALID_TRANSITION', "A {$reservation->status} reservation cannot be changed with '{$action}'.", 409, ['current_status' => $reservation->status]);
            }

            if ($action === 'complete') {
                $given = strtoupper(trim((string) $claimCode));
                if (! hash_equals(strtoupper($reservation->claim_code), $given)) {
                    throw new ApiException('INVALID_CLAIM_CODE', 'The claim code does not match this reservation.', 422);
                }
                if ($reservation->expires_at->lte(now())) {
                    throw new ApiException('RESERVATION_EXPIRED', 'This reservation has expired.', 409, ['current_status' => $reservation->status]);
                }
            }

            return ['status' => $reservation->status, 'hold_ref' => $reservation->hold_ref];
        });

        // (2) inventory-service. Any failure here changes nothing.
        $this->callInventory($action, $expected['hold_ref'], $id, $requestId);

        // (3) status + history, guarded
        $this->apply($id, $expected['status'], $rule['to'], $user->id, $reason, $requestId, $action);

        return ReservationPresenter::detail(Reservation::with(['items', 'history'])->findOrFail($id), true);
    }

    /**
     * Expires every pending or approved reservation whose expires_at has passed. Safe to run repeatedly and in parallel;
     * one failing reservation does not stop the others.
     *
     * @return array{expired: int, failed: int}
     */
    public function expireDue(int $chunk = 50): array
    {
        $expired = 0;
        $failed = 0;

        Reservation::whereIn('status', ['pending', 'approved'])
            ->where('expires_at', '<', now())
            ->chunkById($chunk, function ($reservations) use (&$expired, &$failed) {
                foreach ($reservations as $reservation) {
                    try {
                        $this->expireOne($reservation->id) && $expired++;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error('Reservation could not be expired', [
                            'reservation_id' => $reservation->id,
                            'request_id' => request()->attributes->get('request_id'),
                            'exception' => $e::class,
                            'code' => $e instanceof ApiException ? $e->errorCode : null,
                        ]);
                    }
                }
            });

        return ['expired' => $expired, 'failed' => $failed];
    }

    /** @return bool true when this call expired it, false when someone else got there first */
    private function expireOne(int $id): bool
    {
        $current = DB::transaction(function () use ($id) {
            $reservation = Reservation::whereKey($id)->lockForUpdate()->first();

            return ($reservation !== null && in_array($reservation->status, ['pending', 'approved'], true) && $reservation->expires_at->lt(now()))
                ? ['status' => $reservation->status, 'hold_ref' => $reservation->hold_ref]
                : null;
        });

        if ($current === null) {
            return false;
        }

        // inventory-service answers 200 when the hold is already released or expired
        $this->callInventory('expire', $current['hold_ref'], $id, request()->attributes->get('request_id'));

        return $this->apply($id, $current['status'], 'expired', null, 'auto-expiry', request()->attributes->get('request_id'), 'expire', false);
    }

    // ------------------------------------------------------------------ internals

    private function assertMayAct(UserContext $user, string $action, Reservation $reservation): void
    {
        $allowed = $action === 'cancel'
            ? $reservation->user_id === $user->id                                   // the owner only
            : $user->branchId !== null && $user->branchId === $reservation->branch_id; // staff of this branch

        if (! $allowed) {
            throw $this->forbidden();
        }
    }

    /**
     * approve calls nothing; reject, cancel and expire release the hold; complete commits it.
     *
     * @throws ApiException
     */
    private function callInventory(string $action, string $holdRef, int $reservationId, ?string $requestId): void
    {
        try {
            match ($action) {
                'reject', 'cancel', 'expire' => $this->inventory->release($holdRef),
                'complete' => $this->inventory->commit($holdRef),
                default => null,
            };
        } catch (ApiException $e) {
            if ($action === 'complete' && $e->errorCode === 'INVALID_HOLD_STATE' && $this->allCommitted($e->details)) {
                return; // an earlier attempt committed it and then crashed before updating the status: carry on
            }

            // A 404 on release is already handled by the client (nothing to give back). What is left that is a
            // genuine answer about the hold's state is a conflict the caller cannot fix by retrying.
            if (in_array($e->errorCode, ['INVALID_HOLD_STATE', 'NOT_FOUND'], true)) {
                Log::error('Stock hold not available for this action', [
                    'request_id' => $requestId,
                    'reservation_id' => $reservationId,
                    'action' => $action,
                    'inventory_code' => $e->errorCode,
                ]);
                throw new ApiException('HOLD_NOT_AVAILABLE', 'The stock held for this reservation is no longer available for this action.', 409);
            }

            throw $e; // 503, 504, 502 or an inventory error envelope, exactly as in step 6A: nothing was changed here
        }
    }

    private function allCommitted(mixed $details): bool
    {
        $statuses = is_array($details) ? ($details['statuses'] ?? null) : null;

        return is_array($statuses) && $statuses !== [] && array_unique(array_values($statuses)) === ['committed'];
    }

    /**
     * The guarded update. `WHERE status = <expected>` means two racing actions cannot both win.
     *
     * @return bool true when this call changed the status
     */
    private function apply(int $id, string $expected, string $to, ?int $actorId, ?string $reason, ?string $requestId, string $action, bool $failIfLost = true): bool
    {
        try {
            $changed = DB::transaction(function () use ($id, $expected, $to, $actorId, $reason, $requestId) {
                $rows = Reservation::whereKey($id)->where('status', $expected)->update(['status' => $to, 'updated_at' => now()]);
                if ($rows !== 1) {
                    return false;
                }

                ReservationStatusHistory::create([
                    'reservation_id' => $id,
                    'from_status' => $expected,
                    'to_status' => $to,
                    'actor_id' => $actorId,
                    'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    'request_id' => $requestId,
                    'created_at' => now(),
                ]);

                return true;
            });
        } catch (Throwable $e) {
            // Inventory has already acted but the status did not change. Repeating the same action repairs it.
            Log::critical('Reservation status update failed after the inventory call succeeded', [
                'request_id' => $requestId,
                'reservation_id' => $id,
                'action' => $action,
                'expected_status' => $expected,
                'exception' => $e::class,
            ]);
            throw new ApiException('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
        }

        if ($changed) {
            return true;
        }

        // Someone else changed the status between the check and the update.
        $current = Reservation::whereKey($id)->value('status');
        if ($current === $to) {
            return false; // the same action was done by a concurrent request: nothing left to do
        }
        if ($failIfLost) {
            Log::warning('Reservation status changed concurrently', ['request_id' => $requestId, 'reservation_id' => $id, 'action' => $action, 'current_status' => $current]);
            throw new ApiException('INVALID_TRANSITION', 'The reservation was changed by another request.', 409, ['current_status' => $current]);
        }

        return false;
    }

    private function forbidden(): ApiException
    {
        return new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
    }
}
