<?php

return [
    'timezone' => 'America/New_York',

    'extended_hours' => [
        'start' => env('CALCULATOR_QUOTE_EXTENDED_START', '04:00'),
        'end' => env('CALCULATOR_QUOTE_EXTENDED_END', '20:00'),
    ],

    'freshness_seconds' => [
        'regular' => [
            'live' => (int) env('CALCULATOR_QUOTE_REGULAR_LIVE_SECONDS', 600),
            'usable' => (int) env('CALCULATOR_QUOTE_REGULAR_USABLE_SECONDS', 3600),
        ],
        'extended' => [
            'live' => (int) env('CALCULATOR_QUOTE_EXTENDED_LIVE_SECONDS', 1800),
            'usable' => (int) env('CALCULATOR_QUOTE_EXTENDED_USABLE_SECONDS', 43200),
        ],
        'closed' => [
            'live' => (int) env('CALCULATOR_QUOTE_CLOSED_LIVE_SECONDS', 0),
            'usable' => (int) env('CALCULATOR_QUOTE_CLOSED_USABLE_SECONDS', 259200),
        ],
    ],

    'allow_stale_for_calculation' => filter_var(
        env('CALCULATOR_QUOTE_ALLOW_STALE', true),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? true,

    'future_tolerance_seconds' => (int) env('CALCULATOR_QUOTE_FUTURE_TOLERANCE_SECONDS', 30),
];
