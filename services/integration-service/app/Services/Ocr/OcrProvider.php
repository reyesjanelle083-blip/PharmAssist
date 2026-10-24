<?php

namespace App\Services\Ocr;

/**
 * Adapter interface for an OCR engine. Today: the local Tesseract binary (and a stub for tests and demos).
 * Another engine can be added by implementing this interface and selecting it with OCR_PROVIDER.
 */
interface OcrProvider
{
    /** Short name reported in the response as "source" (for example "tesseract" or "stub"). */
    public function name(): string;

    /**
     * @param string $imagePath path of an already preprocessed image file (a random temp file made by OcrService)
     *
     * @throws OcrException when the engine could not read the image (missing binary, timeout, bad exit code ...)
     */
    public function recognize(string $imagePath, float $timeoutSeconds): OcrReading;
}
