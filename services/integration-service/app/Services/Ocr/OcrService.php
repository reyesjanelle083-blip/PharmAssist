<?php

namespace App\Services\Ocr;

use App\Models\IntegrationLog;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Prescription OCR: validate the upload, preprocess it with GD, run the OCR provider, build a safe result.
 *
 * Privacy: the image is processed on this machine only. It is never persisted (the preprocessed copy is a random temp
 * file deleted in a finally block), never sent to a third party, and neither the image, its name nor the recognised text
 * is ever logged or stored. A provider failure is never a 5xx: the answer is ocr_status "unavailable".
 */
class OcrService
{
    private const ALLOWED_MIME = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG];

    public function __construct(
        private readonly OcrProvider $provider,
        private readonly OcrCircuitBreaker $circuit,
    ) {}

    /**
     * @return array{ocr_text: ?string, confidence: ?float, ocr_status: string, source: string, warnings: list<string>}
     *
     * @throws ValidationException when the upload is not an acceptable image (422)
     */
    public function process(UploadedFile $file, ?string $requestId = null): array
    {
        $this->guardMode();
        $this->assertAcceptable($file);

        if (config('integration.ocr.force_fail')) {
            return $this->failed($requestId, 'forced_failure', false, 0);
        }

        // Stub: deterministic sample text. No process, no temp file, no lock, no log row.
        if ($this->provider->name() === 'stub') {
            return $this->result($this->provider->recognize('', 0.0));
        }

        if (! $this->gdAvailable()) {
            $this->writeLog($requestId, 'failure', null, 0, 'image_processing_unavailable');

            return $this->unavailable(['IMAGE_PROCESSING_UNAVAILABLE']);
        }

        if ($this->circuit->isOpen()) {
            $this->writeLog($requestId, 'fallback', null, 0, 'circuit_open');

            return $this->unavailable();
        }

        $lock = $this->acquireSlot();
        if ($lock === false) {
            $this->writeLog($requestId, 'fallback', null, 0, 'busy');

            return $this->unavailable(['OCR_BUSY']);
        }

        $tempPath = null;
        $started = microtime(true);

        try {
            $tempPath = $this->preprocess($file);
            $reading = $this->provider->recognize($tempPath, (float) config('integration.ocr.timeout_seconds', 15));
        } catch (OcrException $e) {
            return $this->failed($requestId, $e->reason(), $e->timedOut, $this->elapsedMs($started));
        } finally {
            if ($tempPath !== null) {
                @unlink($tempPath); // every temp file is removed, on success and on failure
            }
            if ($lock instanceof Lock) {
                $lock->release();
            }
        }

        $this->circuit->recordSuccess();
        $result = $this->result($reading);
        $this->writeLog($requestId, 'success', 200, $this->elapsedMs($started), null);

        return $result;
    }

    // ------------------------------------------------------------------ validation

    /** Real content checks, not the file name: real MIME type, size and pixel dimensions. */
    private function assertAcceptable(UploadedFile $file): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['image' => [$message]]);

        if (! $file->isValid() || ! is_file((string) $file->getRealPath())) {
            $fail('The image could not be uploaded.');
        }

        $maxBytes = (int) config('integration.ocr.max_upload_bytes', 5 * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            $fail('The image must not be larger than '.round($maxBytes / 1048576).' MB.');
        }

        $type = self::ALLOWED_MIME[$file->getMimeType()] ?? null; // sniffed from the content
        $info = $type === null ? false : @getimagesize($file->getRealPath());
        if ($type === null || $info === false || $info[2] !== $type) {
            $fail('The image must be a JPEG or PNG file.');
        }

        $maxPixels = (int) config('integration.ocr.max_pixels', 4000);
        if ($info[0] > $maxPixels || $info[1] > $maxPixels || $info[0] < 1 || $info[1] < 1) {
            $fail("The image must not be larger than {$maxPixels} x {$maxPixels} pixels.");
        }
    }

    // ------------------------------------------------------------------ preprocessing (GD)

    protected function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagecopyresampled') && function_exists('imagepng');
    }

    /**
     * Downscale (longest side <= max_side_px), flatten onto white, grayscale, boost contrast, write a temp PNG
     * with a random name. Returns the path; the caller deletes it.
     */
    private function preprocess(UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            throw ValidationException::withMessages(['image' => ['The image file is corrupt or cannot be read.']]);
        }

        $sourceW = imagesx($source);
        $sourceH = imagesy($source);
        $scale = min(1.0, (int) config('integration.ocr.max_side_px', 2000) / max($sourceW, $sourceH));
        $width = max(1, (int) round($sourceW * $scale));
        $height = max(1, (int) round($sourceH * $scale));

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // transparent PNGs become white, not black
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceW, $sourceH);
        unset($source);

        imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        imagefilter($canvas, IMG_FILTER_CONTRAST, -40); // in GD a negative value increases contrast

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pharmassist-ocr-'.bin2hex(random_bytes(16)).'.png';
        $written = @imagepng($canvas, $path, 3);
        @chmod($path, 0600);
        unset($canvas);

        if (! $written) {
            @unlink($path);
            throw new OcrException('image_processing_failed');
        }

        return $path;
    }

    // ------------------------------------------------------------------ concurrency

    /**
     * At most OCR_MAX_CONCURRENT jobs at once: one cache lock per slot.
     *
     * @return Lock|false|null the held lock, false when every slot is busy, null when locks are unavailable (fail open)
     */
    private function acquireSlot(): Lock|false|null
    {
        $slots = max(1, (int) config('integration.ocr.max_concurrent', 2));
        $ttl = (int) ceil((float) config('integration.ocr.timeout_seconds', 15)) + 10; // a crashed job frees its slot

        try {
            for ($i = 1; $i <= $slots; $i++) {
                $lock = Cache::lock("integration:ocr:slot:{$i}", $ttl);
                if ($lock->get()) {
                    return $lock;
                }
            }

            return false;
        } catch (Throwable) {
            Log::warning('OCR concurrency lock unavailable; continuing without a limit.');

            return null;
        }
    }

    // ------------------------------------------------------------------ results

    /** @return array{ocr_text: ?string, confidence: ?float, ocr_status: string, source: string, warnings: list<string>} */
    private function result(OcrReading $reading): array
    {
        $text = $this->normalise($reading->text);
        $length = mb_strlen($text);
        $minConfidence = (float) config('integration.ocr.min_confidence', 0.6);
        $confidence = $reading->confidence === null ? null : round($reading->confidence, 2);

        $warnings = [];
        if ($reading->wordCount === 0 || $text === '') {
            $warnings[] = 'NO_TEXT_FOUND';
        } elseif ($length < (int) config('integration.ocr.min_text_chars', 15)) {
            $warnings[] = 'TEXT_TOO_SHORT';
        }
        if ($confidence !== null && $confidence < $minConfidence) {
            $warnings[] = 'LOW_CONFIDENCE_HANDWRITING_LIKELY';
        }

        return [
            'ocr_text' => $text,
            'confidence' => $confidence,
            'ocr_status' => $warnings === [] ? 'success' : 'low_confidence',
            'source' => $this->provider->name(),
            'warnings' => $warnings,
        ];
    }

    /**
     * Trim, remove control characters (newlines are kept), collapse spaces, cap the length.
     * The cap protects the response and the caller; the text itself is never stored.
     */
    public function normalise(string $text): string
    {
        $text = preg_replace('/\r\n?/', "\n", $text) ?? '';
        $text = str_replace(["\t", "\x0B", "\x0C"], ' ', $text); // tab, vertical tab and form feed are whitespace, not noise
        $clean = preg_replace('/[^\P{C}\n]+/u', '', $text);
        if ($clean === null) { // invalid UTF-8: drop the bad bytes (no "?" substitution) and retry
            $previous = mb_substitute_character();
            mb_substitute_character('none');
            $valid = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            mb_substitute_character($previous);
            $clean = preg_replace('/[^\P{C}\n]+/u', '', $valid) ?? '';
        }

        $clean = preg_replace('/[^\S\n]+/u', ' ', $clean) ?? '';   // runs of spaces, tabs, NBSP become one space
        $clean = preg_replace('/^ +| +$/m', '', $clean) ?? '';      // no spaces at line ends
        $clean = preg_replace('/\n{3,}/', "\n\n", $clean) ?? '';

        return mb_substr(trim($clean), 0, (int) config('integration.ocr.max_text_chars', 5000));
    }

    /** @param list<string> $extraWarnings */
    private function unavailable(array $extraWarnings = []): array
    {
        return [
            'ocr_text' => null,
            'confidence' => null,
            'ocr_status' => 'unavailable',
            'source' => $this->provider->name(),
            'warnings' => array_values(array_unique(['OCR_UNAVAILABLE', ...$extraWarnings])),
        ];
    }

    /** A provider failure: log one row, count it for the circuit breaker, answer "unavailable". */
    private function failed(?string $requestId, string $reason, bool $timedOut, int $latencyMs): array
    {
        $this->writeLog($requestId, $timedOut ? 'timeout' : 'failure', null, $latencyMs, $reason);
        $this->circuit->recordFailure();

        return $this->unavailable();
    }

    // ------------------------------------------------------------------ guards and logging

    /** Stub mode and forced failures are test tools: refused in production unless explicitly allowed. */
    private function guardMode(): void
    {
        $provider = config('integration.ocr.provider');
        if (! in_array($provider, ['tesseract', 'stub'], true)) {
            throw new RuntimeException('OCR_PROVIDER must be "tesseract" or "stub".');
        }

        $testTool = config('integration.mode') === 'stub' || $provider === 'stub' || config('integration.ocr.force_fail');
        if ($testTool && app()->environment('production') && ! config('integration.allow_stub_in_production')) {
            Log::critical('Stub OCR or OCR_FORCE_FAIL is set in production and ALLOW_STUB_IN_PRODUCTION is not.');

            throw new RuntimeException('Stub OCR and forced failures are refused in production.');
        }
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * One row per OCR attempt. Only fixed reason codes: never the text, the file name or any file content.
     * A failing log write never changes the answer.
     */
    private function writeLog(?string $requestId, string $outcome, ?int $status, int $latencyMs, ?string $reason): void
    {
        try {
            IntegrationLog::create([
                'request_id' => $requestId,
                'provider' => 'ocr',
                'operation' => 'parse_image',
                'status_code' => $status,
                'outcome' => $outcome,
                'latency_ms' => $latencyMs,
                'error_message' => $reason,
            ]);
        } catch (Throwable) {
            Log::warning('integration_logs write failed.');
        }
    }
}
