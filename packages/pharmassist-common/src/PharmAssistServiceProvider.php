<?php

namespace PharmAssist\Common;

use Illuminate\Support\ServiceProvider;

class PharmAssistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pharmassist.php', 'pharmassist');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pharmassist.php' => config_path('pharmassist.php'),
        ], 'pharmassist-config');
    }
}
