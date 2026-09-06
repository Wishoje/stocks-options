<?php

return [
    // Enable only after the durable retry migration and both server releases.
    'enabled' => filter_var(env('PROVIDER_BACKPRESSURE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'backoff_seconds' => [15, 60, 180],
    'jitter_min_seconds' => 1,
    'jitter_max_seconds' => 5,
    'interactive_depth' => 6,
    'interactive_head_age_seconds' => 30,
    'fill_depth' => 50,
    'fill_head_age_seconds' => 120,
    'rate' => [
        // No request-window allowance has been verified. Concurrency remains
        // explicitly configured by MASSIVE_CONCURRENCY_LIMIT independently.
        'requests' => env('MASSIVE_REQUESTS_PER_WINDOW'),
        'window_seconds' => (int) env('MASSIVE_REQUEST_WINDOW_SECONDS', 60),
    ],
    'replay' => [
        'enabled' => true,
        'prefix' => 'provider-replay:massive',
        'ttl_seconds' => 1200,
        'max_page_bytes' => 2 * 1024 * 1024,
        'max_execution_bytes' => 16 * 1024 * 1024,
        'max_pages' => 1024,
    ],
];
