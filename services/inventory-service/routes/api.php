<?php

use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;
use PharmAssist\Common\Middleware\InternalKey;

Route::get('/internal/v1/health', fn () => Envelope::success(['status' => 'ok']))
    ->middleware(InternalKey::class);
