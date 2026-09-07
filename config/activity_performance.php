<?php

return [
    'batch_pricing_enabled' => (bool) env('ACTIVITY_BATCH_PRICING_ENABLED', false),

    // This limits SQL statement size, not response coverage or the caller's limit.
    'pricing_batch_size' => 100,
];
