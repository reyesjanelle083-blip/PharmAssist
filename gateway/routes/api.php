<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventorySyncController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\MedicineLabelController;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;

/*
| Default deny: a route is reachable only if it is listed in the "public" section below
| or sits inside the auth.jwt group. tests/Feature/GatewaySecurityTest.php enforces this.
*/

// ---- public ---------------------------------------------------------------
Route::get('/internal/v1/health', fn () => Envelope::success(['status' => 'ok']));

Route::prefix('api/v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

    Route::get('/medicines', [CatalogController::class, 'medicines']);
    Route::get('/medicines/categories', [CatalogController::class, 'categories']);
    Route::get('/medicines/{id}', [CatalogController::class, 'medicine'])->whereNumber('id');
    Route::get('/medicines/{id}/counseling', [CatalogController::class, 'counseling'])->whereNumber('id');
    Route::get('/branches', [CatalogController::class, 'branches']);

    // Stock + distance mashup (public: browsing needs no account). 60 per minute per IP.
    Route::get('/availability', [AvailabilityController::class, 'index'])->middleware('throttle:availability');

    // ---- protected (valid JWT required) ------------------------------------
    Route::middleware('auth.jwt')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/branches/{id}/inventory', [InventoryController::class, 'index'])
            ->whereNumber('id')
            ->middleware('role:pharmacy_technician,pharmacy_assistant,branch_manager,ceo');

        Route::put('/branches/{id}/inventory/{medicineId}', [InventoryController::class, 'update'])
            ->whereNumber(['id', 'medicineId'])
            ->middleware('role:pharmacy_technician,branch_manager');

        // Branch inventory feed (XML or JSON): 5 per minute per IP, counted only after the token and role checks.
        Route::post('/integrations/inventory/sync', [InventorySyncController::class, 'sync'])
            ->middleware(['role:branch_manager,ceo', 'throttle:sync']);

        // Reservations. Roles are checked here; reservation-service re-checks role, branch and ownership itself.
        // Rate limits count only requests that passed the token and role checks.
        Route::post('/reservations', [ReservationController::class, 'store'])
            ->middleware(['role:customer', 'throttle:reserve']);
        Route::get('/reservations', [ReservationController::class, 'index'])
            ->middleware('role:customer,pharmacy_technician,pharmacy_assistant,branch_manager,ceo');
        Route::get('/reservations/{id}', [ReservationController::class, 'show'])
            ->whereNumber('id')
            ->middleware('role:customer,pharmacy_technician,pharmacy_assistant,branch_manager,ceo');
        Route::patch('/reservations/{id}/status', [ReservationController::class, 'status'])
            ->whereNumber('id')
            ->middleware(['role:customer,pharmacy_technician,pharmacy_assistant,branch_manager', 'throttle:reservation-status']);
        Route::get('/reservations/{id}/prescription', [ReservationController::class, 'prescription'])
            ->whereNumber('id')
            ->middleware('role:customer,pharmacy_technician,pharmacy_assistant,branch_manager,ceo');

        // Prescription image to text: 10 requests a minute per IP, counted only after the token and role checks pass.
        Route::post('/prescriptions/ocr', [PrescriptionController::class, 'ocr'])
            ->middleware(['role:customer,pharmacy_technician,pharmacy_assistant,branch_manager', 'throttle:ocr']);

            // Medicine package label OCR: same protected/rate-limited OCR budget.
        Route::post('/labels/ocr', [MedicineLabelController::class, 'ocr'])
            ->middleware([
                'role:customer,pharmacy_technician,pharmacy_assistant,branch_manager', 'throttle:ocr',]);
    });
});
