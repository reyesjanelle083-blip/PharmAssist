<?php

namespace App\Services\Distance;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Simple circuit breaker on Laravel's cache. After N consecutive failed OSRM calls the circuit opens and OSRM is
 * skipped for a while; then one call is allowed through again ("half open"): a success closes the circuit,
 * a failure opens it again immediately.
 */
class OsrmCircuitBreaker
{
    private const FAILURES_KEY = 'integration:osrm:consecutive_failures';
    private const OPEN_UNTIL_KEY = 'integration:osrm:open_until';

    public function isOpen(): bool
    {
        try {
            $until = Cache::get(self::OPEN_UNTIL_KEY);

            return is_int($until) && $until > now()->getTimestamp();
        } catch (Throwable) {
            return false; // a broken cache must not block OSRM calls
        }
    }

    public function recordFailure(): void
    {
        try {
            $threshold = (int) config('integration.distance.circuit.failure_threshold', 3);
            $openSeconds = (int) config('integration.distance.circuit.open_seconds', 60);

            Cache::add(self::FAILURES_KEY, 0, 3600);
            $failures = (int) Cache::increment(self::FAILURES_KEY);

            if ($failures >= $threshold) {
                Cache::put(self::OPEN_UNTIL_KEY, now()->getTimestamp() + $openSeconds, $openSeconds);
            }
        } catch (Throwable) {
            // best effort
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_UNTIL_KEY);
        } catch (Throwable) {
            // best effort
        }
    }
}
