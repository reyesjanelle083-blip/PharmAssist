<?php

namespace App\Http\Controllers;

use App\Services\ServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PharmAssist\Common\Http\Envelope;

/**
 * Medicine-package label OCR.
 *
 * The gateway authenticates/rate-limits the request and forwards only
 * the uploaded image to integration-service. The original file name
 * is not forwarded.
 */
class MedicineLabelController extends Controller
{
    public function __construct(private readonly ServiceClient $services) {}

    public function ocr(Request $request): JsonResponse
    {
        $image = $request->file('image');

        if (! $image instanceof UploadedFile || ! $image->isValid()) {
            return Envelope::error(
                'VALIDATION_FAILED',
                'The given data was invalid.',
                422,
                [
                    'image' => ['An image file is required.'],
                ],
            );
        }

        return $this->services->postMultipart(
            'integration',
            '/internal/v1/ocr/label',
            [],
            ['image' => $image],
            (float) config('services.integration.ocr_timeout'),
            anonymiseFileNames: true,
        );
    }
}