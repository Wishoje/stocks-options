<?php

return [
    // Shared coverage is independent of any one customer's saved watchlist.
    'enabled' => env('PRODUCT_UNIVERSE_ENABLED', true),

    // Stock and ETF gaps reviewed against production and options activity on 2026-11-08.
    // Index options require separate quote, settlement and model support.
    'symbols' => [
        'AI', 'APP', 'ASTS', 'AVGO', 'BA', 'BABA', 'BAC', 'BMNR', 'DELL',
        'EEM', 'ETHA', 'F', 'GDX', 'GME', 'GOOG', 'HIMS', 'HOOD', 'HYG',
        'IREN', 'KWEB', 'MARA', 'MRVL', 'NFLX', 'NOK', 'NOW', 'ONDS', 'OPEN',
        'RIVN', 'RKLB', 'SOXL', 'SOXX', 'STX', 'TQQQ', 'TSLL', 'VOO', 'WULF', 'XLF',
    ],
];
