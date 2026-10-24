<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PharmAssist\Common\Exceptions\RenderEnvelope;
use PharmAssist\Common\Middleware\RequestId;

// No web routes and no framework "/up" page: the gateway is an API only (default deny).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(RequestId::class);

        $middleware->alias([
            'auth.jwt' => AuthenticateJwt::class,
            'role' => EnsureRole::class,
        ]);

        // Laravel sorts its throttle middleware ahead of custom middleware. Put the token and role checks before it,
        // so a route's rate limit (for example the OCR one) only counts requests that are authenticated and allowed.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateJwt::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: EnsureRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        RenderEnvelope::register($exceptions);
    })->create();
