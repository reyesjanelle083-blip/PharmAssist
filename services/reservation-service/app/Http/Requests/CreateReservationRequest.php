<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * JSON, or multipart with the prescription image (items then arrive as items[0][medicine_id], items[0][quantity]).
 * Role and Idempotency-Key are checked earlier, by route middleware and ReservationService.
 */
class CreateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.medicine_id' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            // mimes checks the real content, not the client's file name or declared type
            'prescription' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.(int) config('reservation.prescription_max_kb', 4096)],
        ];
    }
}
