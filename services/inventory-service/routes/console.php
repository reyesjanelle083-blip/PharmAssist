<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reconcile holds nobody committed or released (plan limitation 12). Needs `php artisan schedule:work` (or a cron) to run.
Schedule::command('inventory:expire-holds')->everyMinute()->withoutOverlapping();
