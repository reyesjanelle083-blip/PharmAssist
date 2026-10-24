<?php

namespace App\Console\Commands;

use App\Services\Distance\DistanceService;
use App\Services\Distance\ProviderException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Evidence of a real external API call: ONE request to the OSRM Table API.
 * Run it by hand. Never from tests or loops: the public OSRM demo server has no SLA and must not be stressed.
 */
class OsrmSmoke extends Command
{
    protected $signature = 'integration:osrm-smoke';

    protected $description = 'Make ONE real OSRM request (Manila city center to the three seeded branches), print the result and log it.';

    public function handle(DistanceService $distance): int
    {
        if (config('integration.mode') === 'stub') {
            $this->error('integration:osrm-smoke refuses to run while INTEGRATION_MODE=stub (it must make a real request).');

            return self::FAILURE;
        }

        $origin = ['lat' => 14.5995, 'lng' => 120.9842]; // Manila city center
        $branches = [ // the seeded branch coordinates (approximate)
            ['branch_id' => 1, 'lat' => 14.6507, 'lng' => 120.983],
            ['branch_id' => 2, 'lat' => 14.583, 'lng' => 121.005],
            ['branch_id' => 3, 'lat' => 14.723, 'lng' => 121.037],
        ];

        $this->line('OSRM base URL: '.config('integration.distance.osrm.base_url'));
        $this->line('Making one request ...');

        $started = microtime(true);
        try {
            $items = $distance->callOsrm($origin, $branches, 'smoke-'.Str::uuid());
        } catch (ProviderException $e) {
            $this->error('OSRM request failed: '.$e->reason().($e->httpStatus ? " (HTTP {$e->httpStatus})" : ''));
            $this->line('A log row with this outcome was written (if the integration_logs table exists).');

            return self::FAILURE;
        }
        $latency = (int) round((microtime(true) - $started) * 1000);

        $this->table(
            ['branch_id', 'distance_km', 'travel_min', 'source'],
            array_map(fn (array $i) => [$i['branch_id'], $i['distance_km'] ?? 'null', $i['travel_min'] ?? 'null', $i['source']], $items),
        );
        $this->info("OK in {$latency} ms. One integration_logs row was written (if the table exists).");

        return self::SUCCESS;
    }
}
