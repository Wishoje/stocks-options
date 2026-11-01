<?php

return [
    // Enable only after local review. Collection reads retained evidence on the worker.
    'enabled' => (bool) env('INTRADAY_RESEARCH_ENABLED', false),
    'batch_symbols' => 100,
    'manual_batch_symbols' => 5,
    'maximum_report_days' => 180,
];
