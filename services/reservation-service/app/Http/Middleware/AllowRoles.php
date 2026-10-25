<?php

namespace App\Http\Middleware;

use App\Support\UserContext;
use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

/** Alias: allow.roles. Usage: allow.roles:pharmacy_technician,branch_manager. Must follow forwarded.user. */
class AllowRoles
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $context = $request->attributes->get(UserContext::ATTRIBUTE);

        if (! $context instanceof UserContext) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if (! $context->hasRole($roles)) {
            return Envelope::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        return $next($request);
    }
}
