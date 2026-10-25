<?php

namespace App\Support;

use App\Models\Reservation;

/** Who may look at a reservation (and its prescription): the owner, staff of the same branch, and the CEO. */
final class ReservationAccess
{
    public const STAFF = ['pharmacy_technician', 'pharmacy_assistant', 'branch_manager'];

    public static function canView(UserContext $user, Reservation $reservation): bool
    {
        return $reservation->user_id === $user->id
            || $user->role === 'ceo'
            || ($user->hasRole(self::STAFF) && $user->branchId !== null && $user->branchId === $reservation->branch_id);
    }
}
