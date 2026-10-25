<?php

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventorySyncController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\StockHoldController;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use PharmAssist\Common\Middleware\InternalKey;

// Every route here is internal: only the gateway (holding the internal key) may call it.
Route::middleware(InternalKey::class)->prefix('internal/v1')->group(function () {
    Route::get('/health', fn () => Envelope::success(['status' => 'ok']));

    // Public catalog (no user identity needed).
    Route::get('/medicines', [MedicineController::class, 'index']);
    Route::get('/medicines/{id}', [MedicineController::class, 'show'])->whereNumber('id');
    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/branches/{id}', [BranchController::class, 'show'])->whereNumber('id');

    // Where is a medicine in stock (public data; the gateway adds distances).
    Route::get('/inventory/availability', [AvailabilityController::class, 'index']);

    // Stock holds: called only by reservation-service (plan I-4 / I-5). Deliberately not exposed through the gateway.
    Route::post('/stock/holds', [StockHoldController::class, 'store']);
    Route::get('/stock/holds/{reservationRef}', [StockHoldController::class, 'show'])->where('reservationRef', '[A-Za-z0-9._:-]{1,64}');
    Route::post('/stock/holds/{reservationRef}/commit', [StockHoldController::class, 'commit'])->where('reservationRef', '[A-Za-z0-9._:-]{1,64}');
    Route::post('/stock/holds/{reservationRef}/release', [StockHoldController::class, 'release'])->where('reservationRef', '[A-Za-z0-9._:-]{1,64}');

    // Staff routes: the service re-checks identity, role and branch from the headers the gateway forwards.
    Route::middleware('forwarded.user')->group(function () {
        Route::get('/branches/{id}/inventory', [InventoryController::class, 'index'])
            ->whereNumber('id')
            ->middleware(['allow.roles:pharmacy_technician,pharmacy_assistant,branch_manager,ceo', 'branch.scope']);

        // Branch feed (already translated by integration-service). Only a branch manager (own branch) or the CEO.
        Route::post('/inventory/sync', [InventorySyncController::class, 'sync'])
            ->middleware('allow.roles:branch_manager,ceo');

        Route::put('/branches/{id}/inventory/{medicineId}', [InventoryController::class, 'update'])
            ->whereNumber(['id', 'medicineId'])
            ->middleware(['allow.roles:pharmacy_technician,branch_manager', 'branch.scope']);
    });
});
