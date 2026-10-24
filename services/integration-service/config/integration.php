<?php

return [
    // live: call the real providers. stub: never touch the network, return deterministic fake data (tests, demos).
    'mode' => strtolower((string) env('INTEGRATION_MODE', 'live')),

    // Stub mode and forced failures are testing tools. They are refused in production unless this is set on purpose.
    'allow_stub_in_production' => filter_var(env('ALLOW_STUB_IN_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN),

    'distance' => [
        // Makes the OSRM provider fail immediately, so the fallback path can be tested (plan T12).
        'force_fail' => filter_var(env('DISTANCE_FORCE_FAIL', false), FILTER_VALIDATE_BOOLEAN),

        'cache_ttl_minutes' => 10,

        'osrm' => [
            'base_url' => rtrim((string) env('OSRM_BASE_URL', 'https://router.project-osrm.org'), '/'),
            'profile' => 'driving',
            // Seconds each single attempt may take.
            'timeout_seconds' => (float) env('OSRM_TIMEOUT_SECONDS', 1.5),
            // Extra attempts after the first one (only for timeouts, connection errors and 5xx).
            'retries' => (int) env('OSRM_RETRIES', 1),
            // Hard limit across all attempts. The gateway waits 3 s, so this must stay below that.
            'deadline_seconds' => (float) env('OSRM_DEADLINE_SECONDS', 2.5),
            'backoff_ms' => (int) env('OSRM_BACKOFF_MS', 150),
            'user_agent' => (string) env('HTTP_USER_AGENT', 'PharmAssistSIA/1.0 (replace with a contact email)'),
        ],

        'circuit' => [
            'failure_threshold' => 3,
            'open_seconds' => 60,
        ],

        'haversine' => [
            // Straight-line distance times this factor approximates the road distance.
            'road_factor' => (float) env('HAVERSINE_ROAD_FACTOR', 1.3),
            'avg_speed_kmh' => (float) env('HAVERSINE_AVG_SPEED_KMH', 25),
        ],
    ],
];
