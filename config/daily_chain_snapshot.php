<?php

return [
    // This lock must use a cache store shared by every scheduler host.
    'lock_seconds' => (int) env('DAILY_CHAIN_SNAPSHOT_LOCK_SECONDS', 7200),
    'lock_wait_seconds' => (int) env('DAILY_CHAIN_SNAPSHOT_LOCK_WAIT_SECONDS', 5),
    'insert_chunk_size' => (int) env('DAILY_CHAIN_SNAPSHOT_INSERT_CHUNK_SIZE', 1000),
];
