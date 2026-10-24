<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One real OCR on a local image file. Run it by hand; never from tests.
 * Prints only a short preview of the text and never writes the text anywhere.
 */
class OcrSmoke extends Command
{
    protected $signature = 'integration:ocr-smoke {path : path of a local JPEG or PNG image}';

    protected $description = 'Run ONE real OCR (local Tesseract) on an image file; prints status, confidence, length and the first 80 characters.';

    public function handle(OcrService $ocr): int
    {
        if (config('integration.mode') === 'stub' || config('integration.ocr.provider') === 'stub') {
            $this->error('integration:ocr-smoke refuses to run in stub mode (INTEGRATION_MODE=stub or OCR_PROVIDER=stub): it must make a real OCR.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('File not found or not readable.');

            return self::FAILURE;
        }

        // Wrap the local file like an upload (the "test" flag skips PHP's is_uploaded_file check).
        $file = new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);

        $started = microtime(true);
        try {
            $result = $ocr->process($file, 'smoke-'.Str::uuid());
        } catch (ValidationException $e) {
            $this->error('The image was rejected: '.implode(' ', $e->errors()['image'] ?? ['invalid']));

            return self::FAILURE;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        $text = (string) ($result['ocr_text'] ?? '');
        $this->table(['Field', 'Value'], [
            ['ocr_status', $result['ocr_status']],
            ['source', $result['source']],
            ['confidence', $result['confidence'] === null ? 'null' : $result['confidence']],
            ['characters', mb_strlen($text)],
            ['first 80 characters', str_replace("\n", ' / ', mb_substr($text, 0, 80))],
            ['warnings', implode(', ', $result['warnings']) ?: '-'],
            ['duration', "{$ms} ms"],
        ]);

        if ($result['ocr_status'] === 'unavailable') {
            $this->warn('OCR was unavailable. See the warnings above (is TESSERACT_BIN correct, is the GD extension enabled?).');

            return self::FAILURE;
        }

        $this->info('One integration_logs row was written (provider=ocr, operation=parse_image).');

        return self::SUCCESS;
    }
}
