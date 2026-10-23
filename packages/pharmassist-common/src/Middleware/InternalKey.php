<?php

namespace PharmAssist\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

class InternalKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('pharmassist.internal_key');
        $provided = $request->header('X-Internal-Key');

        // Fail closed: no configured key means nothing is allowed through.
        if (! is_string($expected) || $expected === ''
            || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return Envelope::error(
                'INVALID_INTERNAL_KEY',
                'Missing or invalid internal service key.',
                401
            );
        }

        return $next($request);
    }
}
