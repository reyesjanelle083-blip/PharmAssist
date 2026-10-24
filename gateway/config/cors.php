<?php

// Only the origins listed in CORS_ALLOWED_ORIGINS (comma separated) are allowed.
// An empty list allows nothing; a wildcard entry is ignored on purpose.
$origins = array_values(array_filter(
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    fn (string $origin) => $origin !== '' && $origin !== '*'
));

// Each origin becomes an exact, anchored pattern. With a plain list and a single origin the CORS
// library answers every request with that origin; with patterns it sends Access-Control-Allow-Origin
// only when the request's Origin really matches, so unknown origins get no CORS headers at all.
$patterns = array_map(fn (string $origin) => '#^'.preg_quote($origin, '#').'$#', $origins);

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => $patterns,

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    // Tokens travel in the Authorization header, not cookies.
    'supports_credentials' => false,
];
