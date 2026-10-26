<?php

return [
    // Enable only on the scheduler worker after reviewing the local feature.
    'automatic_refresh_enabled' => (bool) env('SWING_IDEAS_AUTOMATIC_REFRESH_ENABLED', false),
];
