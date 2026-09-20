<?php

return [
    // Explicit allowlist; subscription access does not grant publishing access.
    'admin_ids' => array_values(array_filter(array_map('intval', explode(',', env('SOCIAL_ADMIN_IDS', ''))))),
    'schedule_enabled' => env('SOCIAL_SCHEDULE_ENABLED', false),
    'publishing_enabled' => env('SOCIAL_PUBLISHING_ENABLED', false),
    'queue' => env('SOCIAL_QUEUE', 'default'),
    'connection' => env('SOCIAL_QUEUE_CONNECTION'),
    'x' => [
        'consumer_key' => env('X_CONSUMER_KEY'),
        'consumer_secret' => env('X_CONSUMER_SECRET'),
        'access_token' => env('X_ACCESS_TOKEN'),
        'access_secret' => env('X_ACCESS_TOKEN_SECRET'),
        'username' => 'GexOptions',
    ],
];
