<?php

use Illuminate\Support\Facades\Route;
use PharmAssist\Common\Http\Envelope;

// Gateway health stays public.
Route::get('/internal/v1/health', fn () => Envelope::success(['status' => 'ok']));
