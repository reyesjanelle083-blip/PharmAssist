<?php

namespace App\Support;

use App\Models\Reservation;

final class ReservationPresenter
{
    /**
     * The reservation as the API shows it. Never includes prescription_path.
     *
     * @return array<string, mixed>
     */
    public static function detail(Reservation $reservation, bool $withHistory): array
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
