<?php

$boolean = static function (string $key, bool $default = false): bool {
    return filter_var(
        env($key, $default),
        FILTER_VALIDATE_BOOL,
        FILTER_NULL_ON_FAILURE
    ) ?? $default;
};

return [
    /*
    | Keep every rollout switch off until the additive table has been migrated,
    | backfilled, and compared for a complete market session.
    */
    'dual_write' => $boolean('OPTION_LIVE_TOTALS_DUAL_WRITE'),
    'compare_writes' => $boolean('OPTION_LIVE_TOTALS_COMPARE_WRITES'),
    'read_from_canonical' => $boolean('OPTION_LIVE_TOTALS_READ_FROM_CANONICAL'),
];
