<?php

namespace Tests\Support;

use App\Services\Ocr\ProcessOutcome;
use App\Services\Ocr\ProcessRunner;
use Closure;

/**
 * Stands in for the Tesseract process. Records every command and, at the moment the "process" runs,
 * what the image file passed to it looked like (it exists, its pixel size, its pixels are gray).
 */
class FakeProcessRunner implements ProcessRunner
{
    public ?string $executable = '/fake/tesseract';

    public ProcessOutcome $outcome;

    /** @var list<array{command: list<string>, timeout: float, image_exists: bool, width: int, height: int, gray: bool}> */
    public array $runs = [];

    /** Optional hook run inside run(): may throw to simulate a crash. */
    public ?Closure $onRun = null;

    public function __construct()
    {
        $this->outcome = new ProcessOutcome(0, self::tsv([['Take 1 capsule', 95, 1], ['three times a day', 91, 2]]));
    }

    public function findExecutable(string $binary): ?string
    {
        return $this->executable;
    }

    public function run(array $command, float $timeoutSeconds): ProcessOutcome
    {
        $image = $command[1] ?? '';
        $size = is_file($image) ? @getimagesize($image) : false;
        $gray = false;
        if ($size !== false && ($resource = @imagecreatefrompng($image))) {
            $rgb = imagecolorat($resource, (int) ($size[0] / 2), (int) ($size[1] / 2));
            $gray = (($rgb >> 16) & 255) === (($rgb >> 8) & 255) && (($rgb >> 8) & 255) === ($rgb & 255);
        }

        $this->runs[] = [
            'command' => $command,
            'timeout' => $timeoutSeconds,
            'image_exists' => is_file($image),
            'width' => $size ? $size[0] : 0,
            'height' => $size ? $size[1] : 0,
            'gray' => $gray,
        ];

        if ($this->onRun) {
            ($this->onRun)($command);
        }

        return $this->outcome;
    }

    public function count(): int
    {
        return count($this->runs);
    }

    /**
     * Builds Tesseract-style TSV. Each word is [text, confidence 0..100, line number].
     * Adds the structural rows Tesseract writes (levels 1 to 4 with conf -1) and one empty word with conf -1.
     *
     * @param list<array{0: string, 1: int|float, 2: int}> $words
     */
    public static function tsv(array $words): string
    {
        $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
        $rows[] = "1\t1\t0\t0\t0\t0\t0\t0\t800\t600\t-1\t";
        $rows[] = "2\t1\t1\t0\t0\t0\t10\t10\t700\t500\t-1\t";
        $rows[] = "3\t1\t1\t1\t0\t0\t10\t10\t700\t500\t-1\t";

        $number = [];
        $announced = [];
        foreach ($words as [$text, $conf, $line]) {
            if (! isset($announced[$line])) {
                $rows[] = "4\t1\t1\t1\t{$line}\t0\t10\t10\t700\t40\t-1\t";
                $announced[$line] = true;
            }
            $number[$line] = ($number[$line] ?? 0) + 1;
            $rows[] = "5\t1\t1\t1\t{$line}\t{$number[$line]}\t10\t10\t100\t40\t{$conf}\t{$text}";
        }
        $rows[] = "5\t1\t1\t1\t9\t1\t0\t0\t0\t0\t-1\t"; // a "word" without text or confidence: must be ignored

        return implode("\n", $rows)."\n";
    }
}
