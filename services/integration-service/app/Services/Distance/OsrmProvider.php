<?php

namespace App\Services\Distance;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OSRM Table API: one request, the origin as the single source and every branch as a destination.
 * Coordinates go in the URL in lon,lat order. Public demo server: be gentle, it has no SLA.
 */
class OsrmProvider implements DistanceProvider
{
    public function estimate(array $origin, array $destinations, ?float $timeoutSeconds = null): array
    {
        if (config('integration.distance.force_fail')) {
            throw new ProviderException('forced_failure');
        }

        $timeout = max(0.1, $timeoutSeconds ?? (float) config('integration.distance.osrm.timeout_seconds'));

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('integration.distance.osrm.user_agent')])
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->get($this->url($origin, $destinations));
        } catch (ConnectionException $e) {
            // The exception message contains the URL (and so the coordinates): only a fixed reason leaves this class.
            throw $this->connectionFailure($e->getMessage());
        } catch (Throwable) {
            throw new ProviderException('connection_error', null, true);
        }

        $status = $response->status();
        if ($status === 429) {
            throw new ProviderException('http_429', 429); // rate limited: do not hammer the server with retries
        }
        if ($status >= 500) {
            throw new ProviderException('http_5xx', $status, true);
        }
        if ($status >= 400) {
            throw new ProviderException('http_4xx', $status);
        }

        return $this->parse($response->json(), $destinations);
    }

    /**
     * Maps a cURL failure to a fixed reason. Only transient problems are worth a retry:
     * a timeout, or a connection that was refused, reset or could not be resolved.
     * A TLS/certificate problem (for example PHP without a CA bundle) fails the same way every time.
     */
    private function connectionFailure(string $message): ProviderException
    {
        $errno = preg_match('/cURL error (\d+)/', $message, $m) ? (int) $m[1] : null;
        $timedOut = $errno === 28 || stripos($message, 'timed out') !== false;

        if ($timedOut) {
            return new ProviderException('timeout', null, true, true);
        }
        if (in_array($errno, [35, 51, 58, 60, 64, 77, 83, 90, 91], true)) {
            return new ProviderException('tls_error');
        }

        return new ProviderException('connection_error', null, in_array($errno, [6, 7, 18, 52, 55, 56], true));
    }

    private function url(array $origin, array $destinations): string
    {
        $coordinates = [$this->lonLat($origin)];
        foreach ($destinations as $destination) {
            $coordinates[] = $this->lonLat($destination);
        }

        $destinationIndexes = implode(';', range(1, count($destinations)));

        // Built by hand: the ";" separators must stay literal in the path and the query.
        return rtrim((string) config('integration.distance.osrm.base_url'), '/')
            .'/table/v1/'.config('integration.distance.osrm.profile', 'driving').'/'.implode(';', $coordinates)
            ."?sources=0&destinations={$destinationIndexes}&annotations=duration,distance";
    }

    /** OSRM wants longitude first. */
    private function lonLat(array $point): string
    {
        return sprintf('%.6F,%.6F', $point['lng'], $point['lat']);
    }

    /**
     * @param mixed $body decoded JSON
     * @return list<array{branch_id: int, distance_km: float|null, travel_min: int|null, source: string}>
     */
    private function parse(mixed $body, array $destinations): array
    {
        $count = count($destinations);
        $durations = is_array($body) ? ($body['durations'][0] ?? null) : null;
        $distances = is_array($body) ? ($body['distances'][0] ?? null) : null;

        if (! is_array($body) || ($body['code'] ?? null) !== 'Ok'
            || ! is_array($durations) || ! is_array($distances)
            || count($durations) !== $count || count($distances) !== $count) {
            throw new ProviderException('malformed_response');
        }

        $items = [];
        foreach (array_values($destinations) as $i => $destination) {
            $seconds = $durations[$i] ?? null;
            $meters = $distances[$i] ?? null;

            foreach ([$seconds, $meters] as $value) {
                if ($value !== null && ! is_int($value) && ! is_float($value)) {
                    throw new ProviderException('malformed_response');
                }
            }

            // OSRM answers null for a destination it cannot route to: only that item is marked as missing.
            $items[] = ($seconds === null || $meters === null)
                ? ['branch_id' => $destination['branch_id'], 'distance_km' => null, 'travel_min' => null, 'source' => 'osrm']
                : [
                    'branch_id' => $destination['branch_id'],
                    'distance_km' => round($meters / 1000, 2),
                    'travel_min' => (int) ceil($seconds / 60), // rounded up
                    'source' => 'osrm',
                ];
        }

        return $items;
    }
}
