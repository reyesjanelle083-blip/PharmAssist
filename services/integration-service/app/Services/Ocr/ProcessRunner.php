<?php

namespace App\Services\Ocr;

/**
 * Runs an external program. A seam so tests can fake the process: the real implementation uses Symfony Process
 * with an argument array (never a shell string).
 */
interface ProcessRunner
{
    /** Full path of the executable, or null when it does not exist or is not executable. */
    public function findExecutable(string $binary): ?string;

    /** @param list<string> $command executable first, then its arguments, one array element each */
    public function run(array $command, float $timeoutSeconds): ProcessOutcome;
}
