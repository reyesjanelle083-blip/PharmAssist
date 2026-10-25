<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Alias-free route middleware: POST /reservations needs an Idempotency-Key (8 to 128 characters, openapi). */
class RequireIdempotencyKey
{
    public const ATTRIBUTE = 'idempotency_key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'The Idempotency-Key header is required.', 400);
        }

        $key = trim($key);
        if (strlen($key) < 8 || strlen($key) > 128 || ! preg_match('/^[\x21-\x7E]+$/', $key)) {
            throw new ApiException('VALIDATION_FAILED', 'The given data was invalid.', 422, [
                'Idempotency-Key' => ['The Idempotency-Key must be 8 to 128 printable characters without spaces.'],
            ]);
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        return $next($request);
    }
}
