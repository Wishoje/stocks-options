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
        'eod_chain_max_pages' => (int) env('EOD_CHAIN_MAX_PAGES', 120),
        'eod_chain_page_limit' => (int) env('EOD_CHAIN_PAGE_LIMIT', 250),
        'eod_chain_max_pages_per_expiry' => (int) env('EOD_CHAIN_MAX_PAGES_PER_EXPIRY', 80),
        'eod_chain_max_hint_expiries' => (int) env('EOD_CHAIN_MAX_HINT_EXPIRIES', 40),
        // EOD ingest precision knobs (higher values = more complete, slower compute).
        'eod_strike_band_pct' => (float) env('EOD_STRIKE_BAND_PCT', 2.0),
        'eod_greeks_near_pct' => (float) env('EOD_GREEKS_NEAR_PCT', 2.0),
        'eod_min_keep_oi' => (int) env('EOD_MIN_KEEP_OI', 1),
        'eod_min_keep_vol' => (int) env('EOD_MIN_KEEP_VOL', 1),
    ],

    // Google Analytics 4
    'ga4_id' => env('GA4_ID'),

];
