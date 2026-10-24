<?php

namespace App\Http\Middleware;

use App\Support\UserContext;
use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

/** Alias: forwarded.user. Requires the identity headers the gateway forwards; otherwise 401. */
class ForwardedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = UserContext::fromRequest($request);

        if ($context === null) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $request->attributes->set(UserContext::ATTRIBUTE, $context);

        return $next($request);
    }
}
