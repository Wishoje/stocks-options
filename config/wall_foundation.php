<?php

return [
    // Audit tooling is local-only. No scheduled collector is enabled in Batch 1.
    'symbols' => ['SPY', 'QQQ', 'TSLA'],
    'production_capture_path' => storage_path('app/private/wall-foundation/production-capture.json'),
    'planned_interval_minutes' => 5,
    'planned_retention_days' => 180,
];
