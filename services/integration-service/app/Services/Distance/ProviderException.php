<?php

namespace App\Services\Distance;

use RuntimeException;

/**
 * A provider call failed. The message is always a short fixed reason code
 * (timeout, http_5xx, http_429, http_4xx, malformed_response, connection_error, forced_failure),
 * never the raw HTTP or cURL error, because those contain the request URL and therefore coordinates.
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly ?int $httpStatus = null,
        public readonly bool $retryable = false,
        public readonly bool $timedOut = false,
    ) {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }
}
