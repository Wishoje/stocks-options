<?php

return [
    'enabled' => (bool) env('QUOTE_REFRESH_ENABLED', false),
    'completed_ttl_seconds' => 300,
    'final_delay_minutes' => 15,
    'final_window_minutes' => 15,
    'symbol_lock_seconds' => 120,
];
