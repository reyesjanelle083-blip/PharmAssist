<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MedicineController;
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

    // Staff routes: the service re-checks identity, role and branch from the headers the gateway forwards.
    Route::middleware('forwarded.user')->group(function () {
        Route::get('/branches/{id}/inventory', [InventoryController::class, 'index'])
            ->whereNumber('id')
            ->middleware(['allow.roles:pharmacy_technician,pharmacy_assistant,branch_manager,ceo', 'branch.scope']);

        Route::put('/branches/{id}/inventory/{medicineId}', [InventoryController::class, 'update'])
            ->whereNumber(['id', 'medicineId'])
            ->middleware(['allow.roles:pharmacy_technician,branch_manager', 'branch.scope']);
    });
});
