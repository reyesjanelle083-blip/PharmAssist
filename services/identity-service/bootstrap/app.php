<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use PharmAssist\Common\Exceptions\RenderEnvelope;
use PharmAssist\Common\Middleware\RequestId;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        RenderEnvelope::register($exceptions);
    })->create();
