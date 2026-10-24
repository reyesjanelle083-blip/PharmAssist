<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only presence is checked here. The real checks (content-sniffed MIME type, size, pixel dimensions)
     * happen in OcrService, where the file name and extension are never trusted.
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file'],
        ];
    }
}
