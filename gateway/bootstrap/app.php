<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        RenderEnvelope::register($exceptions);
    })->create();
