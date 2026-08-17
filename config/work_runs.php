<?php

return [
    'max_symbols_per_request' => (int) env('WORK_RUN_MAX_SYMBOLS_PER_REQUEST', 250),
    'pending_ttl_seconds' => (int) env('WORK_RUN_PENDING_TTL', 43200),
    'running_ttl_seconds' => [
        'calculator_refresh' => (int) env('WORK_RUN_CALCULATOR_RUNNING_TTL', 3600),
        'intraday_refresh' => (int) env('WORK_RUN_INTRADAY_RUNNING_TTL', 1800),
        'symbol_bootstrap' => (int) env('WORK_RUN_BOOTSTRAP_RUNNING_TTL', 10800),
    ],
    'reusable_seconds' => [
        'calculator_refresh' => (int) env('WORK_RUN_CALCULATOR_REUSE', 600),
        'intraday_refresh' => (int) env('WORK_RUN_INTRADAY_REUSE', 90),
        'symbol_bootstrap' => (int) env('WORK_RUN_BOOTSTRAP_REUSE', 600),
    ],
    'failure_cooldown_seconds' => (int) env('WORK_RUN_FAILURE_COOLDOWN', 300),
    'dispatch_retry_seconds' => (int) env('WORK_RUN_DISPATCH_RETRY', 15),
    'dispatch_reservation_seconds' => (int) env('WORK_RUN_DISPATCH_RESERVATION', 120),
    'abandon_after_seconds' => (int) env('WORK_RUN_ABANDON_AFTER', 86400),
    'status_poll_seconds' => (int) env('WORK_RUN_STATUS_POLL_SECONDS', 2),

    'rate_limits' => [
        'user_per_minute' => (int) env('WORK_RUN_USER_RATE_LIMIT', 120),
        'ip_per_minute' => (int) env('WORK_RUN_IP_RATE_LIMIT', 240),
        'accepted_symbol_per_minute' => (int) env('WORK_RUN_SYMBOL_START_LIMIT', 12),
        'accepted_provider_per_minute' => (int) env('WORK_RUN_PROVIDER_START_LIMIT', 120),
        'status_per_minute' => (int) env('WORK_RUN_STATUS_RATE_LIMIT', 180),
    ],
];
