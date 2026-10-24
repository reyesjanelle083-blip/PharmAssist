<?php

use App\Http\Controllers\DistanceController;
use App\Http\Controllers\InventoryFeedController;
use App\Http\Controllers\OcrController;
use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use PharmAssist\Common\Middleware\InternalKey;

// Every route here is internal: only other services (holding the internal key) may call it.
Route::middleware(InternalKey::class)->prefix('internal/v1')->group(function () {
    Route::get('/health', fn () => Envelope::success(['status' => 'ok']));

    Route::post('/distance', [DistanceController::class, 'calculate']);

    // Branch inventory feed (XML or JSON) to normalised JSON. Reached through the gateway's POST /api/v1/integrations/inventory/sync.
    Route::post('/inventory-feed/translate', [InventoryFeedController::class, 'translate']);

    // Prescription image to text (local Tesseract). Reached through the gateway's POST /api/v1/prescriptions/ocr.
    Route::post('/ocr/prescription', [OcrController::class, 'prescription']);
});
