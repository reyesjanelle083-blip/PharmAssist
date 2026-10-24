<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;

/*
| Default deny: a route is reachable only if it is listed in the "public" section below
| or sits inside the auth.jwt group. tests/Feature/RouteAuditTest.php enforces this.
*/

// ---- public ---------------------------------------------------------------
Route::get('/internal/v1/health', fn () => Envelope::success(['status' => 'ok']));

Route::prefix('api/v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // ---- protected (valid JWT required) ------------------------------------
    Route::middleware('auth.jwt')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
    });
});
