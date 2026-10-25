<?php

return [
    // How long a reservation (and the stock held for it) lasts. Sent to inventory-service as ttl_minutes,
    // so the reservation and the hold expire together.
    'ttl_hours' => (int) env('RESERVATION_TTL_HOURS', 48),

    // inventory-service: stock holds and the medicine catalog.
    'inventory_url' => env('INVENTORY_URL'),
    // Seconds to wait for each inventory-service call. A POST is never retried.
    'inventory_timeout' => (float) env('INVENTORY_TIMEOUT_SECONDS', 3),

    // integration-service: prescription OCR only (plan M7). A problem here never fails the reservation (IntegrationClient).
    'integration_url' => env('INTEGRATION_URL'),
    // Seconds to wait for an OCR answer. Keep it above integration-service's own OCR_TIMEOUT_SECONDS (15 by default).
    'ocr_timeout' => (float) env('RESERVATION_OCR_TIMEOUT_SECONDS', 20),

    // Compensation: how often a hold release is attempted, and the pause between attempts.
    'release_attempts' => 3,
    'release_pause_ms' => (int) env('RELEASE_PAUSE_MS', 200),

    // Private disk (storage/app/private): prescription images are never served by URL.
    'files_disk' => 'local',
    'prescription_max_kb' => 4096,
];
