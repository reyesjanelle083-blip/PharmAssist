<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use PharmAssist\Common\Middleware\InternalKey;

// Every route here is internal: only the gateway (holding the internal key) may call it.
Route::middleware(InternalKey::class)->group(function () {
    Route::get('/internal/v1/health', fn () => Envelope::success(['status' => 'ok']));

    Route::post('/internal/v1/auth/register', [AuthController::class, 'register']);
    Route::post('/internal/v1/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/internal/v1/auth/login', [AuthController::class, 'login']);

    Route::get('/internal/v1/users/{id}', [UserController::class, 'show'])->whereNumber('id');
});
