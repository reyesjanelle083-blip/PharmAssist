<?php

namespace App\Services\Ocr;

/**
 * Local OCR with the Tesseract binary, output as TSV so word-level confidence is available.
 *
 * Command (an argument array, no user-supplied value in it):
 *   tesseract <random temp png> stdout -l eng --psm <int> tsv
 */
class TesseractProvider implements OcrProvider
{
    public function __construct(private readonly ProcessRunner $runner) {}

    public function name(): string
    {
        return 'tesseract';
    }

    public function recognize(string $imagePath, float $timeoutSeconds): OcrReading
    {
        $binary = $this->runner->findExecutable((string) config('integration.ocr.tesseract_bin', 'tesseract'));
        if ($binary === null) {
            throw new OcrException('binary_not_found');
        }

        $psm = (int) config('integration.ocr.psm', 6);
        $outcome = $this->runner->run([
            $binary,
            $imagePath,
            'stdout',
            '-l', (string) config('integration.ocr.language', 'eng'),
            '--psm', (string) ($psm >= 0 && $psm <= 13 ? $psm : 6),
            'tsv',
        ], $timeoutSeconds);

        if ($outcome->timedOut) {
            throw new OcrException('timeout', true);
        }
        if ($outcome->exitCode !== 0) {
            throw new OcrException('nonzero_exit'); // stderr is never copied into the error
        }

        return $this->parseTsv($outcome->stdout);
    }

    /**
     * Reads Tesseract's TSV: level page block par line word left top width height conf text.
     * Only word rows (level 5) with text count. conf -1 means "not a word" and is ignored.
     * Confidence is the mean word confidence (0 to 100) scaled to 0 to 1.
     */
    public function parseTsv(string $tsv): OcrReading
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($tsv)) ?: [];
        $header = array_shift($rows);

        if (! is_string($header) || ! str_starts_with($header, 'level')) {
            throw new OcrException('parse_error');
        }

        $lines = [];        // "block-par-line" => list of words, in reading order
        $confidences = [];

        foreach ($rows as $row) {
            $cols = explode("\t", $row, 12);
            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }

            $word = trim($cols[11]);
            $conf = (float) $cols[10];
            if ($word === '' || $conf < 0) { // -1 = no confidence value
                continue;
            }

            $lines["{$cols[2]}-{$cols[3]}-{$cols[4]}"][] = $word;
            $confidences[] = $conf;
        }

        if ($confidences === []) {
            return new OcrReading('', null, 0);
        }

        return new OcrReading(
            implode("\n", array_map(fn (array $words) => implode(' ', $words), $lines)),
            round(array_sum($confidences) / count($confidences) / 100, 4),
            count($confidences),
        );
    }
}
