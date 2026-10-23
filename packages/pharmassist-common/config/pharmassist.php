<?php

return [
    // Name reported in every response envelope ("service" field).
    'service' => env('SERVICE_NAME', 'unknown-service'),

    // Shared secret for service-to-service calls (X-Internal-Key header).
    // If empty, the InternalKey middleware rejects every request (fail closed).
    'internal_key' => env('INTERNAL_SERVICE_KEY'),
];
