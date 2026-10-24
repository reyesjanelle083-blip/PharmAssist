<?php

namespace App\Http\Middleware;

use App\Support\UserContext;
use Closure;
use Illuminate\Http\Request;
use PharmAssist\Common\Http\Envelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: branch.scope. Every role except ceo may only touch the branch in X-User-Branch.
 * Runs before validation and before the branch lookup, so another branch's existence is never revealed (403, not 404).
 */
class EnsureBranchAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(UserContext::ATTRIBUTE);
        $branchId = $request->route('id');

        if (! $context instanceof UserContext) {
            return Envelope::error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        if (! is_numeric($branchId) || ! $context->canAccessBranch((int) $branchId)) {
            return Envelope::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        return $next($request);
    }
}
