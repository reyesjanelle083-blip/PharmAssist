<?php

namespace App\Console\Commands;

use App\Services\StockHoldService;
use Illuminate\Console\Command;

class ExpireHolds extends Command
{
    protected $signature = 'inventory:expire-holds';

    protected $description = 'Release every stock hold whose expires_at has passed (status expired, reserved quantity restored). Safe to repeat.';

    public function handle(StockHoldService $holds): int
    {
        $result = $holds->expireDue();

        $this->info("Expired {$result['holds']} hold(s) of {$result['reservations']} reservation(s).");

        return self::SUCCESS;
    }
}
