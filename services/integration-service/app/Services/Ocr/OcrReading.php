<?php

namespace App\Services\Ocr;

/** What a provider read from one image: raw text and the mean word confidence (0 to 1, null if no words). */
final class OcrReading
{
    public function __construct(
        public readonly string $text,
        public readonly ?float $confidence,
        public readonly int $wordCount,
    ) {}
}
