<?php

namespace App\Services\Distance;

use App\Models\ApiCache;
use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Distance from one origin to many branches. Never fails because OSRM failed:
 *   cache -> (circuit closed) OSRM with retry and a hard deadline -> Haversine estimate.
 *
 * Privacy: coordinates are sent to OSRM (that is the purpose of the call) but are never stored.
 * The cache key is a hash, the cached payload holds only distances and times, and log rows hold only fixed reason codes.
 */
class DistanceService
{
    private const MIN_ATTEMPT_SECONDS = 0.1;

    public function __construct(
        private readonly OsrmProvider $osrm,
        private readonly OsrmCircuitBreaker $circuit,
    ) {}

    /**
     * @param array{lat: float, lng: float} $origin
     * @param list<array{branch_id: int, lat: float, lng: float}> $destinations
     * @return array{items: list<array{branch_id: int, distance_km: float, travel_min: int, source: string}>, fallback: bool}
     */
    public function distances(array $origin, array $destinations, ?string $requestId = null): array
    {
        $this->guardMode();

        if (config('integration.mode') === 'stub') {
            // Deterministic and offline: never touches the network, the cache or OSRM.
            return ['items' => HaversineProvider::fromConfig('stub')->estimate($origin, $destinations), 'fallback' => false];
        }

        $cacheKey = $this->cacheKey($origin, $destinations);
        if ($cached = $this->readCache($cacheKey, $destinations)) {
            return ['items' => $cached, 'fallback' => false];
        }

        $estimates = HaversineProvider::fromConfig()->estimate($origin, $destinations);

        if ($this->circuit->isOpen()) {
            $this->writeLog($requestId, 'fallback', null, 0, 'circuit_open');

            return ['items' => $estimates, 'fallback' => true];
        }

        try {
            $osrmItems = $this->callOsrm($origin, $destinations, $requestId);
        } catch (ProviderException) {
            return ['items' => $estimates, 'fallback' => true]; // whole request falls back
        }

        // An item OSRM could not route falls back on its own; the others keep their OSRM values.
        $fallback = false;
        $items = [];
        foreach ($osrmItems as $i => $item) {
            if ($item['distance_km'] === null || $item['travel_min'] === null) {
                $items[] = $estimates[$i];
                $fallback = true;
            } else {
                $items[] = $item;
            }
        }

        if (! $fallback) {
            $this->writeCache($cacheKey, $items); // only complete OSRM answers are cached
        }

        return ['items' => $items, 'fallback' => $fallback];
    }

    /**
     * One OSRM "attempt group": the first try plus at most OSRM_RETRIES retries (timeouts, connection errors and
     * 5xx only), all inside one hard deadline. Writes one integration_logs row and updates the circuit breaker.
     * Used by distances() and by the integration:osrm-smoke command.
     *
     * @return list<array{branch_id: int, distance_km: float|null, travel_min: int|null, source: string}>
     *
     * @throws ProviderException
     */
    public function callOsrm(array $origin, array $destinations, ?string $requestId = null): array
    {
        $config = config('integration.distance.osrm');
        $perAttempt = max(self::MIN_ATTEMPT_SECONDS, (float) $config['timeout_seconds']);
        $retries = max(0, (int) $config['retries']);
        $backoff = max(0, (int) $config['backoff_ms']) / 1000;

        $started = microtime(true);
        $deadline = $started + max(0.2, (float) $config['deadline_seconds']);

        $items = null;
        $last = null;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($attempt > 0 && $remaining < self::MIN_ATTEMPT_SECONDS) {
                break; // out of time: do not start another attempt
            }

            try {
                $items = $this->osrm->estimate($origin, $destinations, max(self::MIN_ATTEMPT_SECONDS, min($perAttempt, $remaining)));
                $last = null;
                break;
            } catch (ProviderException $e) {
                $last = $e;
                if (! $e->retryable || $attempt === $retries) {
                    break;
                }
                $pause = min($backoff, max(0, $deadline - microtime(true) - self::MIN_ATTEMPT_SECONDS));
                if ($pause > 0) {
                    usleep((int) round($pause * 1_000_000));
                }
            }
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);

        if ($items === null) {
            $last ??= new ProviderException('deadline_exceeded', null, false, true);
            $this->writeLog($requestId, $last->timedOut ? 'timeout' : 'failure', $last->httpStatus, $latencyMs, $last->reason());
            $this->circuit->recordFailure();

            throw $last;
        }

        $partial = count(array_filter($items, fn (array $i) => $i['distance_km'] === null)) > 0;
        $this->writeLog($requestId, $partial ? 'fallback' : 'success', 200, $latencyMs, $partial ? 'partial_null_values' : null);
        $this->circuit->recordSuccess();

        return $items;
    }

    // ------------------------------------------------------------------ guards

    /** Stub mode and forced failures are test tools: refuse them in production unless explicitly allowed. */
    private function guardMode(): void
    {
        $mode = config('integration.mode');
        if (! in_array($mode, ['live', 'stub'], true)) {
            throw new RuntimeException('INTEGRATION_MODE must be "live" or "stub".');
        }

        $testTool = $mode === 'stub' || config('integration.distance.force_fail');
        if ($testTool && app()->environment('production') && ! config('integration.allow_stub_in_production')) {
            Log::critical('INTEGRATION_MODE=stub or DISTANCE_FORCE_FAIL is set in production and ALLOW_STUB_IN_PRODUCTION is not.');

            throw new RuntimeException('Stub mode and forced failures are refused in production.');
        }
    }

    // ------------------------------------------------------------------ cache

    /** Hash of the origin rounded to 3 decimals (about 100 m) and the sorted destinations. Never the raw coordinates. */
    private function cacheKey(array $origin, array $destinations): string
    {
        usort($destinations, fn (array $a, array $b) => $a['branch_id'] <=> $b['branch_id']);

        $parts = ['distance-v1', sprintf('%.3F,%.3F', $origin['lat'], $origin['lng'])];
        foreach ($destinations as $d) {
            $parts[] = sprintf('%d:%.6F,%.6F', $d['branch_id'], $d['lat'], $d['lng']);
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return list<array{branch_id: int, distance_km: float, travel_min: int, source: string}>|null */
    private function readCache(string $key, array $destinations): ?array
    {
        try {
            $row = ApiCache::where('cache_key', $key)->where('expires_at', '>', now())->first();
            $byBranch = collect($row?->payload['items'] ?? [])->keyBy('branch_id');

            $items = [];
            foreach ($destinations as $d) {
                $hit = $byBranch->get($d['branch_id']);
                if ($hit === null) {
                    return null;
                }
                $items[] = [
                    'branch_id' => $d['branch_id'],
                    'distance_km' => (float) $hit['distance_km'],
                    'travel_min' => (int) $hit['travel_min'],
                    'source' => 'osrm',
                ];
            }

            return $items;
        } catch (Throwable) {
            return null; // an unavailable cache just means a cache miss
        }
    }

    /** @param list<array{branch_id: int, distance_km: float, travel_min: int, source: string}> $items */
    private function writeCache(string $key, array $items): void
    {
        try {
            ApiCache::where('expires_at', '<', now())->delete(); // keep the table small

            ApiCache::updateOrCreate(['cache_key' => $key], [
                // Distances and times only: no origin, no coordinates.
                'payload' => ['items' => array_map(fn (array $i) => [
                    'branch_id' => $i['branch_id'],
                    'distance_km' => $i['distance_km'],
                    'travel_min' => $i['travel_min'],
                ], $items)],
                'expires_at' => now()->addMinutes((int) config('integration.distance.cache_ttl_minutes', 10)),
            ]);
        } catch (Throwable) {
            Log::warning('Distance cache write failed.');
        }
    }

    // ------------------------------------------------------------------ logging

    /** A log failure must never break a distance answer. */
    private function writeLog(?string $requestId, string $outcome, ?int $status, int $latencyMs, ?string $reason): void
    {
        try {
            IntegrationLog::create([
                'request_id' => $requestId,
                'provider' => 'osrm',
                'operation' => 'table',
                'status_code' => $status,
                'outcome' => $outcome,
                'latency_ms' => $latencyMs,
                'error_message' => $reason, // fixed reason code only
            ]);
        } catch (Throwable) {
            Log::warning('integration_logs write failed.');
        }
    }
}
