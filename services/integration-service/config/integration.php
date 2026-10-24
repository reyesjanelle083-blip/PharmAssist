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
            'timeout_seconds' => (float) env('OSRM_TIMEOUT_SECONDS', 1.8),
            // Extra attempts after the first one (only for timeouts, connection errors and 5xx).
            // Default 0: one attempt only. The retry code is kept and can be switched on with OSRM_RETRIES=1.
            'retries' => (int) env('OSRM_RETRIES', 0),
            // Hard limit across all attempts. The gateway waits 3 s and the request itself adds roughly 0.5 to 0.8 s
            // of overhead (measured), so this must stay well below that.
            'deadline_seconds' => (float) env('OSRM_DEADLINE_SECONDS', 1.8),
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

    // Branch inventory feed (XML or JSON) translator.
    'feed' => [
        // Largest accepted request body, in kilobytes (the gateway enforces 512 KB too).
        'max_kb' => max(1, (int) env('FEED_MAX_KB', 512)),
        'max_items' => 500,
        // The XML contract. docs/schemas/inventory-feed.xsd must stay identical to this file.
        'xsd_path' => resource_path('schemas/inventory-feed.xsd'),
    ],

    // Prescription OCR, run locally with the Tesseract binary. The image never leaves this machine and is never stored.
    'ocr' => [
        // tesseract | stub. INTEGRATION_MODE=stub always uses the stub as well.
        'provider' => strtolower((string) env('OCR_PROVIDER', 'tesseract')),
        'tesseract_bin' => (string) env('TESSERACT_BIN', 'tesseract'),
        'language' => 'eng',
        // Tesseract page segmentation mode (6 = a single uniform block of text).
        'psm' => (int) env('OCR_PSM', 6),
        // Seconds the Tesseract process may run before it is killed.
        'timeout_seconds' => (float) env('OCR_TIMEOUT_SECONDS', 15),
        // Below this mean word confidence (0 to 1) the result is "low_confidence".
        'min_confidence' => (float) env('OCR_MIN_CONFIDENCE', 0.6),
        // How many OCR jobs may run at the same time (a cache lock per slot). More callers get OCR_BUSY.
        'max_concurrent' => max(1, (int) env('OCR_MAX_CONCURRENT', 2)),
        // Testing only (refused in production like the distance one): every OCR attempt fails at once.
        'force_fail' => filter_var(env('OCR_FORCE_FAIL', false), FILTER_VALIDATE_BOOLEAN),

        // Upload limits
        'max_upload_bytes' => 5 * 1024 * 1024,
        'max_pixels' => 4000,        // width and height
        // The image is downscaled to this longest side before OCR
        'max_side_px' => 2000,

        // Result limits
        'max_text_chars' => 5000,
        'min_text_chars' => 15,      // shorter text is "low_confidence"

        'circuit' => [
            'failure_threshold' => 3,
            'open_seconds' => 60,
        ],
    ],
];
