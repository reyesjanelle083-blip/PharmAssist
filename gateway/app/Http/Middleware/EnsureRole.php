<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: role. Usage: role:ceo,branch_manager. Authorization = what the role may do.
 * Must run after auth.jwt; without verified claims it refuses (default deny).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $claims = $request->attributes->get(AuthenticateJwt::ATTRIBUTE);

        if (! is_array($claims)) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if (! in_array($claims['role'], $roles, true)) {
            return Envelope::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        return $next($request);
    }
}
