<?php

return [
    // Activate after the additive metadata migration has run on the shared DB.
    'enabled' => (bool) env('INTRADAY_FRESHNESS_ENABLED', false),
    'completed_ttl_seconds' => (int) env('INTRADAY_COMPLETED_TTL_SECONDS', 90),
];
