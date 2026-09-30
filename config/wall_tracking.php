<?php

return [
    // Enable on the worker only after the local review and deployment checks.
    'enabled' => (bool) env('WALL_TRACKING_ENABLED', false),
    'symbols' => ['SPY', 'QQQ', 'TSLA'],
    'quote_max_age_seconds' => 420,
    // Set only for a known delayed feed; source times remain unchanged.
    'quote_delay_seconds' => max(0, min(900, (int) env('WALL_TRACKING_QUOTE_DELAY_SECONDS', 0))),
    'comparison_gap_seconds' => 900,
    // Input coverage is a collection gate, not a claim about captured GEX.
    'minimum_oi_coverage_pct' => 99.5,
    'maximum_excluded_row_pct' => 5,
];
