<?php

namespace PharmAssist\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Request-Id');

        // Reuse the caller's id only if it is a safe token (prevents log injection).
        $id = (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming))
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        $request->headers->set('X-Request-Id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
