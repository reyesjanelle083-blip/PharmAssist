<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class DistanceRequest extends FormRequest
{
    // Philippines bounding box (rough): latitude 4.5 to 21.5, longitude 116 to 127.
    private const LAT_MIN = 4.5;
    private const LAT_MAX = 21.5;
    private const LNG_MIN = 116;
    private const LNG_MAX = 127;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lat = ['required', $this->mustBeNumber(), 'numeric', 'between:'.self::LAT_MIN.','.self::LAT_MAX];
        $lng = ['required', $this->mustBeNumber(), 'numeric', 'between:'.self::LNG_MIN.','.self::LNG_MAX];

        return [
            'origin' => ['required', 'array'],
            'origin.lat' => $lat,
            'origin.lng' => $lng,
            'destinations' => ['required', 'array', 'min:1', 'max:25'],
            'destinations.*.branch_id' => ['required', 'integer', 'min:1', 'distinct'],
            'destinations.*.lat' => $lat,
            'destinations.*.lng' => $lng,
        ];
    }

    /** JSON numbers only: "14.5" as a string is rejected. */
    private function mustBeNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_int($value) && ! is_float($value)) {
                $fail("The {$attribute} field must be a number.");
            }
        };
    }
}
