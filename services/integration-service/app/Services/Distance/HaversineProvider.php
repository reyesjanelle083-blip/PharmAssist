<?php

namespace App\Services\Distance;

/**
 * Offline estimate: great-circle distance times a road factor, travel time from an average speed.
 * Always an estimate, never a measured road distance. Used as the fallback and (labelled "stub") in stub mode.
 */
class HaversineProvider implements DistanceProvider
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function __construct(
        private readonly float $roadFactor,
        private readonly float $averageSpeedKmh,
        private readonly string $source = 'haversine',
    ) {}

    public static function fromConfig(string $source = 'haversine'): self
    {
        return new self(
            max(1.0, (float) config('integration.distance.haversine.road_factor', 1.3)),
            max(1.0, (float) config('integration.distance.haversine.avg_speed_kmh', 25)),
            $source,
        );
    }

    public function estimate(array $origin, array $destinations, ?float $timeoutSeconds = null): array
    {
        return array_map(function (array $destination) use ($origin) {
            $roadKm = $this->greatCircleKm($origin['lat'], $origin['lng'], $destination['lat'], $destination['lng']) * $this->roadFactor;

            return [
                'branch_id' => $destination['branch_id'],
                'distance_km' => round($roadKm, 2),
                'travel_min' => (int) ceil($roadKm / $this->averageSpeedKmh * 60),
                'source' => $this->source,
            ];
        }, $destinations);
    }

    private function greatCircleKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
