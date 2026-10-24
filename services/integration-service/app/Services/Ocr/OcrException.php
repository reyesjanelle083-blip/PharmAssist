<?php

namespace App\Services\Ocr;

use RuntimeException;

/**
 * The OCR engine could not produce a result. The message is always a short fixed reason code
 * (binary_not_found, timeout, nonzero_exit, parse_error, forced_failure ...), never process output:
 * stderr or stdout could contain text read from the image.
 */
class OcrException extends RuntimeException
{
    public function __construct(string $reason, public readonly bool $timedOut = false)
    {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
