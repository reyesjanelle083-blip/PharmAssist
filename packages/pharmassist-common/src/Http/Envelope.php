<?php

namespace PharmAssist\Common\Http;

use Illuminate\Http\JsonResponse;

/**
 * Standard response envelope (plan section 9.1):
 * { status, service, request_id, data, error, warnings }
 */
class Envelope
{
    public static function success(mixed $data = null, int $status = 200, array $warnings = []): JsonResponse
    {
        return self::build($status, $data, null, $warnings);
    }

    public static function error(string $code, string $message, int $status, mixed $details = null): JsonResponse
    {
        return self::build($status, null, [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ], []);
    }

    private static function build(int $status, mixed $data, ?array $error, array $warnings): JsonResponse
    {
        $requestId = request()->attributes->get('request_id');

        $response = response()->json([
            'status' => $status,
            'service' => config('pharmassist.service'),
            'request_id' => $requestId,
            'data' => $data,
            'error' => $error,
            'warnings' => array_values($warnings),
        ], $status);

        if ($requestId) {
            $response->headers->set('X-Request-Id', $requestId);
        }

        return $response;
    }
}
