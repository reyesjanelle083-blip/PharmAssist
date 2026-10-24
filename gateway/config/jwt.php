<?php

return [
    // HS256 secret shared with identity-service (which signs tokens). The gateway only verifies.
    // Must be at least 32 characters; JwtVerifier fails closed otherwise.
    'secret' => env('JWT_SECRET'),
];
