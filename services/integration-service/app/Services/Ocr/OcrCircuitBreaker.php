<?php

namespace App\Services\Ocr;

use App\Services\Support\CircuitBreaker;

/** Circuit breaker for the OCR engine: 3 consecutive failures open it for 60 seconds (integration.ocr.circuit). */
class OcrCircuitBreaker extends CircuitBreaker
{
    public function __construct()
    {
        parent::__construct('ocr', 'integration.ocr.circuit');
    }
}
