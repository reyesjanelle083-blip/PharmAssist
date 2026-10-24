<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reservation_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.medicine_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            // Optional; the default comes from INVENTORY_HOLD_TTL_HOURS (48 hours). Max 7 days.
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ];
    }
}
