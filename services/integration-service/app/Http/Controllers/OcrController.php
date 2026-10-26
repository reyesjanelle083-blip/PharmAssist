<?php

namespace App\Http\Controllers;

use App\Http\Requests\OcrRequest;
use App\Services\Ocr\MedicineLabelParser;
use App\Services\Ocr\OcrService;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

/** Service-to-service (through the gateway). Protected by InternalKey. */
class OcrController extends Controller
{
    public function __construct(
        private readonly OcrService $ocr,
        private readonly MedicineLabelParser $labels,
    ) {}

    public function prescription(OcrRequest $request): JsonResponse
    {
        $result = $this->ocr->process(
            $request->file('image'),
            $request->attributes->get('request_id'),
        );

        // Always 200 once the file is acceptable: an OCR problem is a status, never a 5xx.
        return Envelope::success([
            'ocr_text' => $result['ocr_text'],
            'confidence' => $result['confidence'],
            'ocr_status' => $result['ocr_status'],
            'source' => $result['source'],
        ], 200, $result['warnings']);
    }

    /**
     * Medicine-package OCR.
     *
     * Uses the same local OCR engine as prescriptions, then performs
     * rule-based extraction of medicine-label fields.
     */
    public function label(OcrRequest $request): JsonResponse
    {
        $result = $this->ocr->process(
            $request->file('image'),
            $request->attributes->get('request_id'),
        );

        $parsed = $this->labels->parse($result['ocr_text']);

        $warnings = array_values(array_unique([
            ...$result['warnings'],
            ...$parsed['warnings'],
        ]));

        return Envelope::success([
            'medicine_name' => $parsed['medicine_name'],
            'dosage' => $parsed['dosage'],
            'batch_no' => $parsed['batch_no'],
            'expiration_date' => $parsed['expiration_date'],
            'ocr_text' => $result['ocr_text'],
            'confidence' => $result['confidence'],
            'ocr_status' => $result['ocr_status'],
            'source' => $result['source'],
        ], 200, $warnings);
    }
}