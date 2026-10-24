<?php

namespace App\Services;

use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PharmAssist\Common\Http\Envelope;
use Throwable;

/**
 * The only way the gateway calls a downstream service.
 *
 * - Builds headers from scratch: it never copies headers from the client request, so a client
 *   cannot spoof X-Internal-Key, X-User-* or Authorization.
 * - X-User-* come only from the verified JWT claims (set by the auth.jwt middleware).
 * - Relays the downstream envelope and status unchanged; otherwise returns a gateway error envelope
 *   that never contains downstream details.
 * - Never retries (POSTs are not idempotent).
 */
class ServiceClient
{
    private const TIMEOUT_SECONDS = 3;

    private const ENVELOPE_KEYS = ['status', 'service', 'request_id', 'data', 'error', 'warnings'];

    /**
     * Every method takes an optional per-call timeout in seconds (default 3). Only routes that really need longer,
     * such as OCR, pass one; everything else keeps the 3 second budget.
     *
     * @param array<string, mixed> $query
     */
    public function get(string $service, string $path, array $query = [], ?float $timeoutSeconds = null): JsonResponse
    {
        return $this->send($service, 'get', $path, fn (PendingRequest $http, string $url) => $http->get($url, $query), $timeoutSeconds);
    }

    /** @param array<string, mixed> $json */
    public function postJson(string $service, string $path, array $json = [], ?float $timeoutSeconds = null): JsonResponse
    {
        return $this->send($service, 'post', $path, fn (PendingRequest $http, string $url) => $http->asJson()->post($url, $json), $timeoutSeconds);
    }

    /** Sends a raw body (for example an XML feed) with the given content type. */
    public function postRaw(string $service, string $path, string $body, string $contentType, ?float $timeoutSeconds = null): JsonResponse
    {
        return $this->send($service, 'post', $path, fn (PendingRequest $http, string $url) => $http->withBody($body, $contentType)->post($url), $timeoutSeconds);
    }

    /** @param array<string, mixed> $json */
    public function putJson(string $service, string $path, array $json = [], ?float $timeoutSeconds = null): JsonResponse
    {
        return $this->send($service, 'put', $path, fn (PendingRequest $http, string $url) => $http->asJson()->put($url, $json), $timeoutSeconds);
    }

    /**
     * @param array<string, scalar|null> $fields
     * @param array<string, UploadedFile> $files
     * @param bool $anonymiseFileNames send a neutral file name (upload.png) instead of the uploader's own, for sensitive uploads
     */
    public function postMultipart(string $service, string $path, array $fields = [], array $files = [], ?float $timeoutSeconds = null, bool $anonymiseFileNames = false): JsonResponse
    {
        return $this->send($service, 'post', $path, function (PendingRequest $http, string $url) use ($fields, $files, $anonymiseFileNames) {
            foreach ($files as $name => $file) {
                $http = $http->attach(
                    $name,
                    (string) file_get_contents($file->getRealPath()),
                    $anonymiseFileNames ? 'upload.'.($file->guessExtension() ?: 'bin') : $file->getClientOriginalName(),
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
                );
            }

            return $http->post($url, array_filter($fields, fn ($v) => $v !== null));
        }, $timeoutSeconds);
    }

    private function send(string $service, string $method, string $path, callable $call, ?float $timeoutSeconds = null): JsonResponse
    {
        $baseUrl = config("services.{$service}.url");
        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::error('Downstream service URL is not configured', ['service' => $service]);

            return $this->failure('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 503);
        }

        $http = $this->pending($timeoutSeconds ?? self::TIMEOUT_SECONDS);

        try {
            $response = $call($http, rtrim($baseUrl, '/').$path);
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
            Log::warning('Downstream call failed', ['service' => $service, 'method' => $method, 'path' => $path, 'timeout' => $timedOut]);

            return $timedOut
                ? $this->failure('UPSTREAM_TIMEOUT', 'The service took too long to respond.', 504)
                : $this->failure('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 503);
        } catch (Throwable $e) {
            Log::error('Downstream call error', ['service' => $service, 'path' => $path, 'exception' => $e::class]);

            return $this->failure('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        return $this->relay($service, $path, $response->status(), $response->json(), $response->header('Retry-After'));
    }

    /**
     * The outgoing request with its timeout. A seam: tests replace it to see which timeout a route asked for.
     * Connecting must stay quick even when the whole call may take long, so the connect timeout is capped at 3 seconds.
     */
    protected function pending(float $timeoutSeconds): PendingRequest
    {
        return Http::withHeaders($this->headers())
            ->acceptJson()
            ->timeout($timeoutSeconds)
            ->connectTimeout(min($timeoutSeconds, (float) self::TIMEOUT_SECONDS));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $request = request();

        $headers = [
            'X-Internal-Key' => (string) config('pharmassist.internal_key'),
            'X-Request-Id' => (string) $request->attributes->get('request_id'),
        ];

        // Only a verified JWT can introduce a user identity.
        $claims = $request->attributes->get(AuthenticateJwt::ATTRIBUTE);
        if (is_array($claims)) {
            $headers['X-User-Id'] = (string) $claims['sub'];
            $headers['X-User-Role'] = (string) $claims['role'];
            $headers['X-User-Branch'] = $claims['branch_id'] === null ? '' : (string) $claims['branch_id'];
        }

        return $headers;
    }

    private function relay(string $service, string $path, int $status, mixed $body, ?string $retryAfter): JsonResponse
    {
        $isEnvelope = is_array($body)
            && array_diff(self::ENVELOPE_KEYS, array_keys($body)) === []
            && $status >= 200 && $status <= 599;

        if (! $isEnvelope) {
            Log::warning('Downstream returned a non-envelope response', ['service' => $service, 'path' => $path, 'status' => $status]);

            return $this->failure('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        $response = response()->json($body, $status);
        if ($retryAfter !== null && $retryAfter !== '') {
            $response->headers->set('Retry-After', $retryAfter);
        }

        return $response;
    }

    private function failure(string $code, string $message, int $status): JsonResponse
    {
        return Envelope::error($code, $message, $status);
    }
}
