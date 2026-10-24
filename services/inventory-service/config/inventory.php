<?php

return [
    // How long stock stays held for a reservation before the expiry job releases it.
    // A caller may override it per hold with ttl_minutes.
    'hold_ttl_hours' => (int) env('INVENTORY_HOLD_TTL_HOURS', 48),
];
