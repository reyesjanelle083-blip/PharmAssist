<?php

namespace App\Http\Requests;

use App\Services\AvailabilityService;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Query strings carry "true"/"false"; Laravel's boolean rule only knows true/false booleans and 1/0.
        if (is_string($this->query('rx'))) {
            $rx = filter_var($this->query('rx'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($rx !== null) {
                $this->merge(['rx' => $rx]);
            }
        }
    }

    public function rules(): array
    {
        // A search needs a medicine id, a name or a category; rx only narrows it down.
        return [
            'medicine_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'required_without_all:q,category'],
            'q' => ['nullable', 'string', 'max:100', 'required_without_all:medicine_id,category'],
            'category' => ['nullable', 'string', 'max:100', 'required_without_all:medicine_id,q'],
            'rx' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.AvailabilityService::MAX_LIMIT],
        ];
    }
}
