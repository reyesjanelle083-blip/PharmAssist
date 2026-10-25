<?php

use App\Http\Controllers\ReservationController;
use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use PharmAssist\Common\Middleware\InternalKey;

// Every route here is internal: only the gateway (holding the internal key) may call it.
Route::middleware(InternalKey::class)->prefix('internal/v1')->group(function () {
    Route::get('/health', fn () => Envelope::success(['status' => 'ok']));

    // The service re-checks identity, role, branch and ownership from the headers the gateway forwards.
    Route::middleware('forwarded.user')->group(function () {
        Route::post('/reservations', [ReservationController::class, 'store'])
            ->middleware(['allow.roles:customer', RequireIdempotencyKey::class]);

        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::get('/reservations/{id}', [ReservationController::class, 'show'])->whereNumber('id');

        Route::patch('/reservations/{id}/status', [ReservationController::class, 'status'])
            ->whereNumber('id')
            ->middleware('allow.roles:customer,pharmacy_technician,pharmacy_assistant,branch_manager'); // the CEO cannot change status

        Route::get('/reservations/{id}/prescription', [ReservationController::class, 'prescription'])->whereNumber('id');
    });
});
