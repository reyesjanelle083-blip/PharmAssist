<?php

namespace App\Http\Controllers;

use App\Http\Requests\OcrRequest;
use App\Services\Ocr\OcrService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

/** Service-to-service (through the gateway). Protected by InternalKey. */
class OcrController extends Controller
{
    public function __construct(private readonly OcrService $ocr) {}

    public function prescription(OcrRequest $request): JsonResponse
    {
        $result = $this->ocr->process($request->file('image'), $request->attributes->get('request_id'));

        // Always 200 once the file is acceptable: an OCR problem is a status, never a 5xx.
        return Envelope::success([
            'ocr_text' => $result['ocr_text'],
            'confidence' => $result['confidence'],
            'ocr_status' => $result['ocr_status'],
            'source' => $result['source'],
        ], 200, $result['warnings']);
    }
}
