<?php

return [
    'bulk_enabled' => filter_var(env('INTRADAY_BULK_WRITES_ENABLED', true), FILTER_VALIDATE_BOOL),
    'chunk_size' => max(1, min(1000, (int) env('INTRADAY_WRITE_CHUNK_SIZE', 250))),
];
