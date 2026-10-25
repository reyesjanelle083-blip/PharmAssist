<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use PharmAssist\Common\Http\Envelope;

/**
 * An expected, client-facing failure. Rendered as an error envelope.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return Envelope::error($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
