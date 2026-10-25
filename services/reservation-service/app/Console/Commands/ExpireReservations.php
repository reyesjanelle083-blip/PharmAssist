<?php

namespace App\Console\Commands;

use App\Services\ReservationStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire pending and approved reservations whose expires_at has passed and give their stock back';

    public function handle(ReservationStatusService $statuses): int
    {
        // One id for the whole run, so log lines, history rows and inventory-service logs can be matched.
        request()->attributes->set('request_id', 'expire-'.Str::uuid());

        $result = $statuses->expireDue(50);

        $this->info("Expired {$result['expired']} reservation(s); {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
