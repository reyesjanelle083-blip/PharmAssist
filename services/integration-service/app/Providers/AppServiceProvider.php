<?php

namespace App\Providers;

use App\Services\Ocr\OcrProvider;
use App\Services\Ocr\ProcessRunner;
use App\Services\Ocr\StubOcrProvider;
use App\Services\Ocr\SymfonyProcessRunner;
use App\Services\Ocr\TesseractProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessRunner::class, SymfonyProcessRunner::class);

        // The OCR engine is chosen when it is needed (not at boot) so configuration can change in tests.
        // INTEGRATION_MODE=stub always means the stub; OCR_PROVIDER picks the engine otherwise.
        $this->app->bind(OcrProvider::class, function ($app) {
            return config('integration.mode') === 'stub' || config('integration.ocr.provider') === 'stub'
                ? new StubOcrProvider
                : new TesseractProvider($app->make(ProcessRunner::class));
        });
    }

    public function boot(): void
    {
        //
    }
}
