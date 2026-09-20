<?php

return [
    // Set once, immediately before the refreshed conversion listeners are
    // activated. Events before this UTC instant are excluded from new cohorts.
    'cohort_started_at' => env('CONVERSION_MEASUREMENT_STARTED_AT'),
];
