<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Field names follow docs/openapi.yaml (stock_quantity, not "quantity").
        return [
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'min_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'batch_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'expiration_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:today'],
            // Required when the row already exists; the service enforces that (a new row has no version yet).
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
