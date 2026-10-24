<?php

namespace App\Services\Distance;

/**
 * Adapter interface: where distances come from is hidden from the rest of the service
 * (OSRM today; Google or another routing engine could be added without touching DistanceService).
 */
interface DistanceProvider
{
    /**
     * @param array{lat: float, lng: float} $origin
     * @param list<array{branch_id: int, lat: float, lng: float}> $destinations
     * @param float|null $timeoutSeconds how long this call may take (providers that use no network ignore it)
     * @return list<array{branch_id: int, distance_km: float|null, travel_min: int|null, source: string}>
     *         Same order as $destinations. A null distance/time means "this provider has no value for that item".
     *
     * @throws ProviderException when the provider could not answer at all
     */
    public function estimate(array $origin, array $destinations, ?float $timeoutSeconds = null): array;
}
