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

    public function rules(): array
    {
        return [
            'medicine_id' => ['nullable', 'integer', 'min:1', 'max:2147483647', 'required_without:q'],
            'q' => ['nullable', 'string', 'max:100', 'required_without:medicine_id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.AvailabilityService::MAX_LIMIT],
        ];
    }
}
