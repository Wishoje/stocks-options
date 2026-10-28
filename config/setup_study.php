<?php

return [
    'owner_email' => env('SETUP_STUDY_OWNER_EMAIL', 'kovacevic.milan881@gmail.com'),
    // Only existing recorded evidence is read. This task never requests market data.
    'enabled' => (bool) env('SETUP_STUDY_ENABLED', true),
    'batch_symbols' => 2,
    'lookback_days' => 45,
    'maximum_report_days' => 180,
];
