<?php

return [
    'singleton_enabled' => filter_var(env('INTRADAY_SINGLETON_JOBS_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Enable only after isolated Redis, consumers, provider limits, and parity
    // have been verified for this deployment.
    'rollout_validated' => filter_var(env('INTRADAY_SINGLETON_ROLLOUT_VALIDATED', false), FILTER_VALIDATE_BOOL),
];
