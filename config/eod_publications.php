<?php

return [
    // Enable only after importing the existing heads with old writers drained.
    'write_enabled' => (bool) env('EOD_CACHE_PUBLICATIONS_WRITE_ENABLED', false),
    'read_enabled' => (bool) env('EOD_CACHE_PUBLICATIONS_READ_ENABLED', false),
];
