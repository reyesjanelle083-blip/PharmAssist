<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MedicineIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query strings carry "true"/"false"; Laravel's boolean rule only knows 1/0/true/false booleans.
        if (is_string($this->query('rx'))) {
            $rx = filter_var($this->query('rx'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($rx !== null) {
                $this->merge(['rx' => $rx]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'rx' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'include' => ['nullable', 'string', 'in:availability'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
