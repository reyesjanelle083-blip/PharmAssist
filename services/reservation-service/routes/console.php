<?php

use Illuminate\Support\Facades\Schedule;

// Auto-expiry (plan F5). The scheduler must be running: `php artisan schedule:work`
// (or a cron entry that calls `php artisan schedule:run` every minute).
Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping();
