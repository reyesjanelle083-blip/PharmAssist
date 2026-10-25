<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * reservation-service to integration-service (REST, internal key, forwarded X-Request-Id), for prescription OCR only
 * (plan M7). Unlike InventoryClient, nothing here is ever allowed to fail the reservation: integration-service being
 * unreachable, too slow, or answering with something unusable, and OCR genuinely finding no usable text, are all the
 * same from the caller's side - the safe result below. Nothing here is ever thrown; a hold already taken from
 * inventory-service must never be given back just because the prescription could not be read automatically.
 */
class IntegrationClient
{
    private const ENVELOPE_KEYS = ['status', 'service', 'request_id', 'data', 'error', 'warnings'];

    private const UNAVAILABLE = ['ocr_text' => null, 'confidence' => null, 'ocr_status' => 'unavailable'];

    /** @return array{ocr_text: ?string, confidence: ?float, ocr_status: string} */
    public function ocr(UploadedFile $file, ?string $requestId): array
    {
        $baseUrl = config('reservation.integration_url');
        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::warning('INTEGRATION_URL is not configured; prescription OCR skipped.');

            return self::UNAVAILABLE;
        }

        try {
            $response = $this->pending()->attach(
                'image',
                (string) file_get_contents($file->getRealPath()),
                // the file's own name is never forwarded (nothing in integration-service needs it, and it may contain
                // a patient name); the extension alone is enough for the content-sniffed check on the other side
                'upload.'.($file->guessExtension() ?: 'bin'),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            )->post(rtrim($baseUrl, '/').'/internal/v1/ocr/prescription');
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
            Log::warning('integration-service OCR call failed', ['request_id' => $requestId, 'timeout' => $timedOut]);

            return self::UNAVAILABLE;
        } catch (Throwable $e) {
            Log::error('integration-service OCR call error', ['request_id' => $requestId, 'exception' => $e::class]);

            return self::UNAVAILABLE;
        }

        return $this->read($response, $requestId);
    }

    private function pending(): PendingRequest
    {
        $timeout = (float) config('reservation.ocr_timeout', 20);

        return Http::withHeaders([
            'X-Internal-Key' => (string) config('pharmassist.internal_key'),
            'X-Request-Id' => (string) request()->attributes->get('request_id'),
        ])->acceptJson()->timeout($timeout)->connectTimeout(min(3.0, $timeout));
    }

    /** @return array{ocr_text: ?string, confidence: ?float, ocr_status: string} */
    private function read(Response $response, ?string $requestId): array
    {
        $body = $response->json();
        $isEnvelope = is_array($body) && array_diff(self::ENVELOPE_KEYS, array_keys($body)) === [];

        if (! $isEnvelope || ! $response->successful() || ! is_array($body['data'] ?? null)) {
            Log::warning('integration-service OCR returned an unusable response', ['request_id' => $requestId, 'status' => $response->status()]);

            return self::UNAVAILABLE;
        }

        $data = $body['data'];
        $status = $data['ocr_status'] ?? null;
        if (! in_array($status, ['success', 'low_confidence', 'unavailable'], true)) {
            return self::UNAVAILABLE;
        }

        return [
            'ocr_text' => is_string($data['ocr_text'] ?? null) && $data['ocr_text'] !== '' ? $data['ocr_text'] : null,
            'confidence' => is_numeric($data['confidence'] ?? null) ? round((float) $data['confidence'], 3) : null,
            'ocr_status' => $status,
        ];
    }
}
