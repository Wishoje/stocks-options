<?php

return [
    'enabled' => filter_var(env('SYMBOL_BOOTSTRAP_ENABLED', false), FILTER_VALIDATE_BOOL),
    'fast_horizon_days' => (int) env('SYMBOL_BOOTSTRAP_FAST_HORIZON_DAYS', 14),
    'fill_horizon_days' => (int) env('SYMBOL_BOOTSTRAP_FILL_HORIZON_DAYS', 90),

    'queue_connection' => env('QUEUE_CONNECTION', 'redis'),
    'queues' => [
        'quote' => env('QUEUE_LANE_BOOTSTRAP_FAST', 'bootstrap-fast'),
        'catalog' => env('QUEUE_LANE_BOOTSTRAP_FAST', 'bootstrap-fast'),
        'fast_eod' => env('QUEUE_LANE_BOOTSTRAP_FAST', 'bootstrap-fast'),
        'intraday' => env('QUEUE_LANE_INTRADAY_INTERACTIVE', 'intraday-interactive'),
        'fill' => env('QUEUE_LANE_ENRICHMENT', 'default'),
        'enrichment' => env('QUEUE_LANE_ENRICHMENT', 'default'),
    ],

    'dispatch_reservation_seconds' => (int) env('SYMBOL_BOOTSTRAP_DISPATCH_RESERVATION', 120),
    'pending_lease_seconds' => (int) env('SYMBOL_BOOTSTRAP_PENDING_LEASE', 3600),
    'running_lease_seconds' => [
        'quote' => (int) env('SYMBOL_BOOTSTRAP_QUOTE_LEASE', 300),
        'catalog' => (int) env('SYMBOL_BOOTSTRAP_CATALOG_LEASE', 300),
        'fast_eod' => (int) env('SYMBOL_BOOTSTRAP_FAST_EOD_LEASE', 900),
        'intraday' => (int) env('SYMBOL_BOOTSTRAP_INTRADAY_LEASE', 1080),
        'fill' => (int) env('SYMBOL_BOOTSTRAP_FILL_LEASE', 1080),
        'enrichment' => (int) env('SYMBOL_BOOTSTRAP_ENRICHMENT_LEASE', 3600),
    ],
    'retry_backoff_seconds' => [15, 60, 180],
    'max_phase_attempts' => (int) env('SYMBOL_BOOTSTRAP_MAX_PHASE_ATTEMPTS', 5),
    'failure_cooldown_seconds' => (int) env('SYMBOL_BOOTSTRAP_FAILURE_COOLDOWN', 300),
];
