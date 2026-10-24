<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InventoryController;
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

    Route::get('/medicines', [CatalogController::class, 'medicines']);
    Route::get('/medicines/{id}', [CatalogController::class, 'medicine'])->whereNumber('id');
    Route::get('/branches', [CatalogController::class, 'branches']);

    // ---- protected (valid JWT required) ------------------------------------
    Route::middleware('auth.jwt')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/branches/{id}/inventory', [InventoryController::class, 'index'])
            ->whereNumber('id')
            ->middleware('role:pharmacy_technician,pharmacy_assistant,branch_manager,ceo');

        Route::put('/branches/{id}/inventory/{medicineId}', [InventoryController::class, 'update'])
            ->whereNumber(['id', 'medicineId'])
            ->middleware('role:pharmacy_technician,branch_manager');
    });
});
