<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plan I-4 / I-5: reservation-service to inventory-service (REST, internal key, forwarded X-Request-Id).
 * Every call has a short timeout and is made once: a POST is never retried here (a retry could double-hold stock).
 * Failures become ApiException with the codes of plan section 12:
 *   no connection -> 503 SERVICE_UNAVAILABLE, timeout -> 504 UPSTREAM_TIMEOUT, not an envelope -> 502 BAD_UPSTREAM_RESPONSE,
 *   an error envelope from inventory-service (404, 409 STOCK_INSUFFICIENT, ...) -> passed through with its details.
 */
class InventoryClient
{
    private const ENVELOPE_KEYS = ['status', 'service', 'request_id', 'data', 'error', 'warnings'];

    /** @return array<string, mixed> the medicine record (id, generic_name, prescription_required, ...) */
    public function medicine(int $id): array
    {
        return $this->data($this->send('get', "/internal/v1/medicines/{$id}"));
    }

    /**
     * @param list<array{medicine_id: int, quantity: int}> $items
     * @return array<string, mixed> the hold: reservation_ref, branch_id, status, expires_at, items[]
     */
    public function createHold(string $reservationRef, int $branchId, array $items, int $ttlMinutes): array
    {
        return $this->data($this->send('post', '/internal/v1/stock/holds', [
            'reservation_ref' => $reservationRef,
            'branch_id' => $branchId,
            'items' => $items,
            'ttl_minutes' => $ttlMinutes,
        ]));
    }

    /**
     * One release attempt. Releasing is safe to repeat (inventory-service answers 200 for holds already released).
     * A 404 means no hold exists for the reference, so there is nothing to give back.
     *
     * @throws ApiException when the attempt failed and may be tried again
     */
    public function release(string $reservationRef): void
    {
        try {
            $this->data($this->send('post', '/internal/v1/stock/holds/'.rawurlencode($reservationRef).'/release'));
        } catch (ApiException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, mixed> $json */
    private function send(string $method, string $path, array $json = []): Response
    {
        $baseUrl = config('reservation.inventory_url');
        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::error('INVENTORY_URL is not configured');
            throw new ApiException('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 503);
        }

        $url = rtrim($baseUrl, '/').$path;

        try {
            $http = $this->pending();

            return $method === 'get' ? $http->get($url) : $http->asJson()->post($url, $json);
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
            Log::warning('inventory-service call failed', ['path' => $path, 'timeout' => $timedOut]);

            throw $timedOut
                ? new ApiException('UPSTREAM_TIMEOUT', 'The service took too long to respond.', 504)
                : new ApiException('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 503);
        } catch (Throwable $e) {
            Log::error('inventory-service call error', ['path' => $path, 'exception' => $e::class]);
            throw new ApiException('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }
    }

    private function pending(): PendingRequest
    {
        $timeout = (float) config('reservation.inventory_timeout', 3);

        return Http::withHeaders([
            'X-Internal-Key' => (string) config('pharmassist.internal_key'),
            'X-Request-Id' => (string) request()->attributes->get('request_id'),
        ])->acceptJson()->timeout($timeout)->connectTimeout($timeout);
    }

    /** The `data` of a success envelope; an error envelope is passed through, anything else is a 502. */
    private function data(Response $response): array
    {
        $body = $response->json();
        $isEnvelope = is_array($body) && array_diff(self::ENVELOPE_KEYS, array_keys($body)) === [];

        if (! $isEnvelope) {
            Log::warning('inventory-service returned a non-envelope response', ['status' => $response->status()]);
            throw new ApiException('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        if ($response->successful()) {
            if (! is_array($body['data'] ?? null)) {
                throw new ApiException('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
            }

            return $body['data'];
        }

        $error = $body['error'];
        if (! is_array($error) || ! is_string($error['code'] ?? null) || $response->status() < 400) {
            throw new ApiException('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        throw new ApiException($error['code'], (string) ($error['message'] ?? 'The request failed.'), $response->status(), $error['details'] ?? null);
    }
}
