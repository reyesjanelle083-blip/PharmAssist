<?php

namespace App\Services;

use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Response as HttpResponse;
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

    /** The only client-sent header that may ever be passed on (see send()). Everything else is built from verified data. */
    private const FORWARDABLE_CLIENT_HEADERS = ['Idempotency-Key'];

    /** Image types a binary stream may carry, with the extension used in the file name. */
    private const STREAM_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

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
    public function postJson(string $service, string $path, array $json = [], ?float $timeoutSeconds = null, array $clientHeaders = []): JsonResponse
    {
        return $this->send($service, 'post', $path, fn (PendingRequest $http, string $url) => $http->asJson()->post($url, $json), $timeoutSeconds, $clientHeaders);
    }

    /** @param array<string, mixed> $json */
    public function patchJson(string $service, string $path, array $json = [], ?float $timeoutSeconds = null): JsonResponse
    {
        return $this->send($service, 'patch', $path, fn (PendingRequest $http, string $url) => $http->asJson()->patch($url, $json), $timeoutSeconds);
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
    public function postMultipart(string $service, string $path, array $fields = [], array $files = [], ?float $timeoutSeconds = null, bool $anonymiseFileNames = false, array $clientHeaders = []): JsonResponse
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

            // Without a file there is nothing to make multipart; as a plain form the bracket names (items[0][quantity]) still
            // reach the service as nested fields. (The default body format would send them as literal JSON keys.)
            if ($files === []) {
                $http = $http->asForm();
            }

            return $http->post($url, array_filter($fields, fn ($v) => $v !== null));
        }, $timeoutSeconds, $clientHeaders);
    }

    /**
     * GET for a binary answer (a stored image). Only a 200 with a png or jpeg body is streamed back, with the type and
     * safety headers; any other answer (an error envelope) is relayed like every other call, and a 200 that is not an
     * image is a 502. The downstream's own file name or path is never passed on: the name is always prescription.<ext>.
     */
    public function stream(string $service, string $path, ?float $timeoutSeconds = null): JsonResponse|HttpResponse
    {
        $outcome = $this->dispatch($service, 'get', $path, fn (PendingRequest $http, string $url) => $http->get($url), $timeoutSeconds, []);
        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        if ($outcome->status() !== 200) {
            return $this->relay($service, $path, $outcome->status(), $outcome->json(), $outcome->header('Retry-After'));
        }

        $type = strtolower(trim(explode(';', (string) $outcome->header('Content-Type'))[0]));
        if (! isset(self::STREAM_TYPES[$type]) || $outcome->body() === '') {
            Log::warning('Downstream returned a non-image answer for a binary route', ['service' => $service, 'path' => $path]);

            return $this->failure('BAD_UPSTREAM_RESPONSE', 'The service returned an unexpected response.', 502);
        }

        $extension = self::STREAM_TYPES[$type];
        $disposition = (string) $outcome->header('Content-Disposition');
        $cache = (string) $outcome->header('Cache-Control');

        return response($outcome->body(), 200, [
            'Content-Type' => $type,
            // relayed when it is the expected shape; never anything that could carry a path or another name
            'Content-Disposition' => preg_match('/^inline; filename=prescription\.(png|jpg|jpeg)$/', $disposition) ? $disposition : "inline; filename=prescription.{$extension}",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => (str_contains($cache, 'no-store') && str_contains($cache, 'private')) ? $cache : 'private, no-store',
            'X-Request-Id' => (string) request()->attributes->get('request_id'),
        ]);
    }

    private function send(string $service, string $method, string $path, callable $call, ?float $timeoutSeconds = null, array $clientHeaders = []): JsonResponse
    {
        $outcome = $this->dispatch($service, $method, $path, $call, $timeoutSeconds, $clientHeaders);

        return $outcome instanceof JsonResponse
            ? $outcome
            : $this->relay($service, $path, $outcome->status(), $outcome->json(), $outcome->header('Retry-After'));
    }

    /**
     * Makes the call. Returns the downstream response, or a gateway error envelope when the call could not be made
     * (no URL, no connection, timeout, unexpected failure).
     *
     * @param array<string, string> $clientHeaders only the headers in FORWARDABLE_CLIENT_HEADERS are used
     */
    private function dispatch(string $service, string $method, string $path, callable $call, ?float $timeoutSeconds, array $clientHeaders): JsonResponse|Response
    {
        $baseUrl = config("services.{$service}.url");
        if (! is_string($baseUrl) || $baseUrl === '') {
            Log::error('Downstream service URL is not configured', ['service' => $service]);

            return $this->failure('SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.', 503);
        }

        $http = $this->pending($timeoutSeconds ?? self::TIMEOUT_SECONDS);

        // A whitelist, so a caller can never smuggle X-User-*, X-Internal-Key or Authorization through this argument.
        foreach (self::FORWARDABLE_CLIENT_HEADERS as $name) {
            $value = $clientHeaders[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $http = $http->withHeaders([$name => $value]);
            }
        }

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

        return $response;
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
