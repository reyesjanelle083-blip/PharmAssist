<?php

namespace App\Services\Ocr;

/**
 * Deterministic sample text for tests and demos. Starts no process and reads no file.
 * Used when INTEGRATION_MODE=stub or OCR_PROVIDER=stub.
 */
class StubOcrProvider implements OcrProvider
{
    public const SAMPLE_TEXT = "Sample prescription (stub)\nAmoxicillin 500 mg capsule\nTake 1 capsule 3 times a day for 7 days";

    public function name(): string
    {
        return 'stub';
    }

    public function recognize(string $imagePath, float $timeoutSeconds): OcrReading
    {
        return new OcrReading(self::SAMPLE_TEXT, 0.93, 17);
    }
}
