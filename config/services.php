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
    ],

];
