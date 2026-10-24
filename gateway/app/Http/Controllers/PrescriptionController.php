<?php

namespace App\Http\Controllers;

use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PharmAssist\Common\Http\Envelope;

/**
 * Prescription image to text. The gateway authenticates, checks the role, rate limits and forwards the image to
 * integration-service, which validates and reads it. Nothing is stored here and the file name is not forwarded.
 */
class PrescriptionController extends Controller
{
    public function __construct(private readonly ServiceClient $services) {}

    public function ocr(Request $request): JsonResponse
    {
        $image = $request->file('image');

        if (! $image instanceof UploadedFile || ! $image->isValid()) {
            return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, [
                'image' => ['An image file is required.'],
            ]);
        }

        // OCR may legitimately take longer than the 3 s every other route gets: it has its own, configurable timeout.
        return $this->services->postMultipart(
            'integration',
            '/internal/v1/ocr/prescription',
            [],
            ['image' => $image],
            (float) config('services.integration.ocr_timeout'),
            anonymiseFileNames: true,
        );
    }
}
