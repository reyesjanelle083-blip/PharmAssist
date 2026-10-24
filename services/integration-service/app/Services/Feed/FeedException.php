<?php

namespace App\Services\Feed;

use App\Exceptions\ApiException;

/**
 * A feed was refused. Carries a fixed reason code for the integration log (never the content of the feed).
 */
class FeedException extends ApiException
{
    public function __construct(
        string $errorCode,
        string $message,
        int $status,
        public readonly string $reason,
        public readonly string $operation = 'translate',
        mixed $details = null,
    ) {
        parent::__construct($errorCode, $message, $status, $details);
    }
}
