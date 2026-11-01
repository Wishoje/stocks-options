<?php

return [
    // Enable the private feature on both releases; only the worker runs the scheduler.
    'enabled' => (bool) env('SWING_RESEARCH_ENABLED', false),
    'batch_symbols' => 2,
    'maximum_report_days' => 180,
];
