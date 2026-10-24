<?php

namespace App\Services\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Simple circuit breaker on Laravel's cache, one instance per provider name.
 * After N consecutive failed calls the circuit opens and the provider is skipped for a while; then one call is
 * allowed through again ("half open"): a success closes the circuit, a failure opens it again immediately.
 *
 * Cache keys: integration:{name}:consecutive_failures and integration:{name}:open_until
 * (for "osrm" these are the same keys the distance code has always used).
 */
class CircuitBreaker
{
    /**
     * @param string $name         provider name used in the cache keys
     * @param string $configPath   config path holding failure_threshold and open_seconds, for example integration.ocr.circuit
     */
    public function __construct(
        private readonly string $name,
        private readonly string $configPath,
    ) {}

    public function isOpen(): bool
    {
        try {
            $until = Cache::get($this->key('open_until'));

            return is_int($until) && $until > now()->getTimestamp();
        } catch (Throwable) {
            return false; // a broken cache must not block calls
        }
    }

    public function recordFailure(): void
    {
        try {
            $threshold = (int) config($this->configPath.'.failure_threshold', 3);
            $openSeconds = (int) config($this->configPath.'.open_seconds', 60);

            Cache::add($this->key('consecutive_failures'), 0, 3600);
            $failures = (int) Cache::increment($this->key('consecutive_failures'));

            if ($failures >= $threshold) {
                Cache::put($this->key('open_until'), now()->getTimestamp() + $openSeconds, $openSeconds);
            }
        } catch (Throwable) {
            // best effort
        }
    }

    public function recordSuccess(): void
    {
        try {
            Cache::forget($this->key('consecutive_failures'));
            Cache::forget($this->key('open_until'));
        } catch (Throwable) {
            // best effort
        }
    }

    private function key(string $suffix): string
    {
        return "integration:{$this->name}:{$suffix}";
    }
}
