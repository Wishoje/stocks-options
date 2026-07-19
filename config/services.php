<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'finnhub' => [
        'api_key' => env('FINNHUB_API_KEY'),
    ],

    'massive' => [
        'base'   => env('MASSIVE_BASE', 'https://api.massive.com'),
        'key'    => env('MASSIVE_API_KEY'),
        'mode'   => env('MASSIVE_AUTH_MODE', 'header'), // header|bearer|query
        'header' => env('MASSIVE_API_HEADER', 'X-API-Key'),
        'qparam' => env('MASSIVE_API_QUERY', 'apiKey'),
        'concurrency' => [
            'enabled' => filter_var(env('MASSIVE_CONCURRENCY_ENABLED', false), FILTER_VALIDATE_BOOL),
            'connection' => env('MASSIVE_CONCURRENCY_CONNECTION', 'default'),
            'key' => env('MASSIVE_CONCURRENCY_KEY', 'provider-concurrency:massive'),
            // This must be set explicitly from the verified provider plan.
            // The initial gate partitions it between interactive/background.
            'limit' => (int) env('MASSIVE_CONCURRENCY_LIMIT', 0),
            'release_after' => (int) env('MASSIVE_CONCURRENCY_RELEASE_AFTER', 90),
            'block_for' => (int) env('MASSIVE_CONCURRENCY_BLOCK_FOR', 45),
            'web_block_for' => (int) env('MASSIVE_CONCURRENCY_WEB_BLOCK_FOR', 2),
            'sleep_milliseconds' => (int) env('MASSIVE_CONCURRENCY_SLEEP_MS', 100),
            'metrics_ttl' => (int) env('MASSIVE_CONCURRENCY_METRICS_TTL', 172800),
        ],
        'eod_chain_max_pages' => (int) env('EOD_CHAIN_MAX_PAGES', 120),
        'eod_chain_page_limit' => (int) env('EOD_CHAIN_PAGE_LIMIT', 250),
        'eod_chain_max_pages_per_expiry' => (int) env('EOD_CHAIN_MAX_PAGES_PER_EXPIRY', 80),
        // The safe path fetches exact expiration/side partitions for every
        // eligible symbol. Keep it behind a rollout flag so production can be
        // canaried independently of the legacy path.
        'eod_chain_partitioned_fetch_enabled' =>
            filter_var(env('EOD_CHAIN_PARTITIONED_FETCH_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Empty means every symbol. A comma-separated list is supported only
        // to narrow a temporary production canary during rollout.
        'eod_chain_partitioned_canary_symbols' => array_values(array_filter(array_map(
            static fn (string $symbol): string => strtoupper(trim($symbol)),
            explode(',', (string) env('EOD_CHAIN_PARTITIONED_CANARY_SYMBOLS', ''))
        ))),
        'eod_chain_max_pages_per_partition' => (int) env('EOD_CHAIN_MAX_PAGES_PER_PARTITION', 40),
        'eod_chain_reference_probe_max_pages' => (int) env('EOD_CHAIN_REFERENCE_PROBE_MAX_PAGES', 4),
        'eod_chain_max_hint_expiries' => (int) env('EOD_CHAIN_MAX_HINT_EXPIRIES', 90),
        'eod_chain_reference_max_pages' => (int) env('EOD_CHAIN_REFERENCE_MAX_PAGES', 40),
        'eod_chain_repair_partial_expiries' =>
            filter_var(env('EOD_CHAIN_REPAIR_PARTIAL_EXPIRIES', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'eod_min_side_strike_ratio' => (float) env('EOD_MIN_SIDE_STRIKE_RATIO', 0.35),
        'eod_force_data_date' => env('EOD_FORCE_DATA_DATE'),
        // EOD ingest precision knobs (higher values = more complete, slower compute).
        'eod_strike_band_pct' => (float) env('EOD_STRIKE_BAND_PCT', 2.0),
        'eod_greeks_near_pct' => (float) env('EOD_GREEKS_NEAR_PCT', 2.0),
        'eod_min_keep_oi' => (int) env('EOD_MIN_KEEP_OI', 1),
        'eod_min_keep_vol' => (int) env('EOD_MIN_KEEP_VOL', 1),
    ],

    // Google Analytics 4
    'ga4_id' => env('GA4_ID'),

];
