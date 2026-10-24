<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\StockHoldService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Internal helper of inventory:race-test. One OS process = one concurrent client.
 * Waits at a start barrier (a shared wall-clock timestamp), then asks for 1 unit and prints one JSON line.
 */
class RaceWorker extends Command
{
    protected $signature = 'inventory:race-worker {reservation_ref} {branch_id} {medicine_id} {start_at : unix time with microseconds}';

    protected $description = 'Internal: one competing client of inventory:race-test.';

    protected $hidden = true;

    public function handle(StockHoldService $holds): int
    {
        if (app()->environment('production')) {
            return self::FAILURE;
        }

        $startAt = (float) $this->argument('start_at');
        $late = microtime(true) > $startAt; // this process booted too slowly to join the barrier

        while (microtime(true) < $startAt) {
            usleep(100);
        }

        $began = microtime(true);
        try {
            $holds->create($this->argument('reservation_ref'), (int) $this->argument('branch_id'), [
                ['medicine_id' => (int) $this->argument('medicine_id'), 'quantity' => 1],
            ]);
            $outcome = ['outcome' => 'success'];
        } catch (ApiException $e) {
            $outcome = ['outcome' => $e->errorCode === 'STOCK_INSUFFICIENT' ? 'insufficient' : 'error', 'code' => $e->errorCode];
        } catch (Throwable $e) {
            $outcome = ['outcome' => 'error', 'code' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 160)];
        }

        $this->line(json_encode($outcome + [
            'ref' => $this->argument('reservation_ref'),
            'late' => $late,
            'ms' => (int) round((microtime(true) - $began) * 1000),
        ]));

        return self::SUCCESS;
    }
}
