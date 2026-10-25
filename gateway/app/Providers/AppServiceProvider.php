<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Per client IP. Exceeding a limit returns 429 TOO_MANY_REQUESTS with Retry-After.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('ocr', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('reserve', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('reservation-status', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
