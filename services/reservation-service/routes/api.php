<?php

use Illuminate\Support\Facades\Route;

Route::get('/internal/v1/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'reservation-service',
]));
