<?php

return [
    // HS256 signing secret shared only by identity-service (signs) and the gateway (verifies).
    // Must be at least 32 characters; JwtIssuer fails closed otherwise.
    'secret' => env('JWT_SECRET'),

    // Access token lifetime in minutes.
    'ttl_minutes' => 60,
];
