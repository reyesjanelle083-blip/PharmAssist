<?php

namespace PharmAssist\Common\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * Renders every exception as a JSON envelope. Never exposes stack traces,
 * SQL or file paths, regardless of APP_DEBUG. Full details go to the log
 * (which carries the request_id) through Laravel's normal reporting.
 */
class RenderEnvelope
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(function (Throwable $e, Request $request) {
            return self::toEnvelope($e);
        });
    }

    private static function toEnvelope(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return Envelope::error('VALIDATION_FAILED', 'The given data was invalid.', 422, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return Envelope::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return Envelope::error('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $response = Envelope::error(self::codeFor($status), self::messageFor($status), $status);

            if ($e instanceof MethodNotAllowedHttpException) {
                $response->headers->set('Allow', (string) ($e->getHeaders()['Allow'] ?? ''));
            }
            if ($status === 429 && isset($e->getHeaders()['Retry-After'])) {
                $response->headers->set('Retry-After', (string) $e->getHeaders()['Retry-After']);
            }

            return $response;
        }

        return Envelope::error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'MALFORMED_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            413 => 'PAYLOAD_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            422 => 'VALIDATION_FAILED',
            429 => 'TOO_MANY_REQUESTS',
            502 => 'BAD_UPSTREAM_RESPONSE',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'UPSTREAM_TIMEOUT',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
        };
    }

    // Fixed texts: never echo the exception message (it may contain internals).
    private static function messageFor(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Authentication is required.',
            403 => 'You are not allowed to perform this action.',
            404 => 'The requested resource was not found.',
            405 => 'This HTTP method is not allowed for the resource.',
            429 => 'Too many requests. Please retry later.',
            503 => 'The service is temporarily unavailable.',
            default => Response::$statusTexts[$status] ?? 'Request failed.',
        };
    }
}
