<?php

namespace App\Services\Ocr;

final class ProcessOutcome
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly bool $timedOut = false,
    ) {}
}
