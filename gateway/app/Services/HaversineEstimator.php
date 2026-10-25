<?php

namespace App\Services;

/**
 * The gateway's own last-resort distance estimate, used only when integration-service cannot answer.
 * Same formula and rounding as integration-service's HaversineProvider: great-circle distance times a road factor,
 * kilometres with 2 decimals, minutes rounded UP from an average speed. An estimate, never a measured road distance.
 */
class HaversineEstimator
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function __construct(private readonly float $roadFactor, private readonly float $averageSpeedKmh) {}

    public static function fromConfig(): self
    {
        return new self(
            max(1.0, (float) config('services.distance.road_factor', 1.3)),
            max(1.0, (float) config('services.distance.avg_speed_kmh', 25)),
        );
    }

    /** @return array{distance_km: float, travel_min: int} */
    public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $roadKm = $this->greatCircleKm($fromLat, $fromLng, $toLat, $toLng) * $this->roadFactor;

        return [
            'distance_km' => round($roadKm, 2),
            'travel_min' => (int) ceil($roadKm / $this->averageSpeedKmh * 60),
        ];
    }

    private function greatCircleKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
