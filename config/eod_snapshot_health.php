<?php

return [
    'enabled' => (bool) env('EOD_SNAPSHOT_HEALTH_ENABLED', false),
    // Prepare tracked complete publications before moving request reads.
    // Roll back reads independently while retaining mutation tracking.
    'read_enabled' => (bool) env('EOD_SNAPSHOT_HEALTH_READ_ENABLED', false),
];
