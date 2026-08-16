<?php

return [
    'scheduler' => [
        'max_symbols' => (int) env('CALCULATOR_SCHEDULER_MAX_SYMBOLS', 75),
        'fresh_minutes' => (int) env('CALCULATOR_SCHEDULER_FRESH_MINUTES', 10),
        'interval_minutes' => (int) env('CALCULATOR_SCHEDULER_INTERVAL_MINUTES', 5),
        'pending_ttl_seconds' => (int) env('CALCULATOR_SCHEDULER_PENDING_TTL', 43200),
        'started_ttl_seconds' => (int) env('CALCULATOR_SCHEDULER_STARTED_TTL', 3600),
        'failure_cooldown_seconds' => (int) env('CALCULATOR_SCHEDULER_FAILURE_COOLDOWN', 300),
        'failure_cooldown_max_seconds' => (int) env('CALCULATOR_SCHEDULER_FAILURE_COOLDOWN_MAX', 3600),
        'state_ttl_seconds' => (int) env('CALCULATOR_SCHEDULER_STATE_TTL', 2592000),
        'fallback_symbols' => array_values(array_filter(array_map(
            static fn (string $symbol): string => strtoupper(trim($symbol)),
            explode(',', (string) env('CALCULATOR_SCHEDULER_FALLBACK_SYMBOLS', 'SPY,QQQ,IWM'))
        ))),
    ],
];
