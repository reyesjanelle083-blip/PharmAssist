<?php

namespace App\Services\Distance;

use App\Services\Support\CircuitBreaker;

/**
 * The circuit breaker for OSRM: 3 consecutive failed calls open it for 60 seconds
 * (integration.distance.circuit). Behaviour and cache keys are unchanged; the logic lives in CircuitBreaker.
 */
class OsrmCircuitBreaker extends CircuitBreaker
{
    public function __construct()
    {
        parent::__construct('osrm', 'integration.distance.circuit');
    }
}
