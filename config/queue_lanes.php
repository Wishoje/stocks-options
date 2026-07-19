<?php

$csv = static fn (string $value): array => array_values(array_filter(array_map(
    static fn (string $item): string => strtoupper(trim($item)),
    explode(',', $value)
)));

return [
    // Producers stay on the current queues until the isolated consumers are
    // running and this flag is enabled on both the web and worker sites.
    'isolated' => filter_var(env('QUEUE_LANES_ISOLATED', false), FILTER_VALIDATE_BOOL),

    // Keep workload-specific classifications separate. Intraday page/runtime
    // measurements and calculator catalog depth are not interchangeable.
    'intraday_heavy_symbols' => $csv((string) env('INTRADAY_HEAVY_SYMBOLS', 'SPY,QQQ')),
    'calculator_heavy_symbols' => $csv((string) env('CALCULATOR_HEAVY_SYMBOLS', 'SPY,QQQ,IWM')),

    'queues' => [
        'bootstrap_fast' => env('QUEUE_LANE_BOOTSTRAP_FAST', 'bootstrap-fast'),
        'intraday_interactive' => env('QUEUE_LANE_INTRADAY_INTERACTIVE', 'intraday-interactive'),
        'calculator_interactive' => env('QUEUE_LANE_CALCULATOR_INTERACTIVE', 'calculator-interactive'),
        'quotes' => env('QUEUE_LANE_QUOTES', 'quotes'),
        'intraday' => env('QUEUE_LANE_INTRADAY', 'intraday'),
        'intraday_heavy' => env('QUEUE_LANE_INTRADAY_HEAVY', 'intraday-heavy'),
        'calculator_fill' => env('QUEUE_LANE_CALCULATOR_FILL', 'calculator-fill'),
        'calculator_fill_heavy' => env('QUEUE_LANE_CALCULATOR_FILL_HEAVY', 'calculator-fill-heavy'),
        'enrichment' => env('QUEUE_LANE_ENRICHMENT', 'default'),
        'exports' => env('QUEUE_LANE_EXPORTS', 'exports'),
    ],

    'legacy' => [
        'bootstrap' => 'bootstrap',
        'prime' => 'prime',
        'calculator' => 'calculator',
    ],
];
