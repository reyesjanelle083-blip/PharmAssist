<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

trait RefusesProduction
{
    /** Demo data must never be written to a production database. */
    protected function refuseInProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(static::class.' refuses to run when APP_ENV=production.');
        }
    }
}
