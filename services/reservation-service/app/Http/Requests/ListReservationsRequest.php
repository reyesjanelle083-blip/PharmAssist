<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending,approved,completed,rejected,cancelled,expired'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'], // used by the CEO only
        ];
    }
}
