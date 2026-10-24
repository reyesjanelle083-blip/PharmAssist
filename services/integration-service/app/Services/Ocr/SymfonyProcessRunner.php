<?php

namespace App\Services\Ocr;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class SymfonyProcessRunner implements ProcessRunner
{
    public function findExecutable(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        // A configured path (contains a separator) must exist as a file; a bare name is looked up on the PATH.
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary);
    }

    public function run(array $command, float $timeoutSeconds): ProcessOutcome
    {
        // An argument array: nothing goes through a shell, so no value can be interpreted as a command.
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->setEnv(['OMP_THREAD_LIMIT' => '1']); // one thread per OCR job: predictable CPU use

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new ProcessOutcome(-1, '', '', true);
        } catch (Throwable) {
            return new ProcessOutcome(127); // could not even start
        }

        return new ProcessOutcome((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }
}
