<?php

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\BuildAiExportJob;
use App\Jobs\ComputeBlindSpotsJob;
use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PrimeSymbolJob;
use App\Jobs\QueueSymbolEnrichmentJob;
use App\Jobs\Seasonality5DJob;
use App\Jobs\SendLifecycleEmailJob;

$standardBackoff = [15, 60, 180];

return [
    BootstrapUserSymbolJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 60,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + source',
        'write_strategy' => 'dispatch guards and an ordered child chain',
    ],
    BuildAiExportJob::class => [
        'connection' => 'redis-long', 'queues' => ['exports'], 'max_timeout' => 900,
        'tries' => 2, 'backoff' => [60], 'identity' => 'export id',
        'write_strategy' => 'one export row transitions queued -> processing -> completed/failed',
    ],
    ComputeBlindSpotsJob::class => [
        'connection' => 'redis', 'queues' => ['default'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + lookahead',
        'write_strategy' => 'natural-key update-or-insert',
    ],
    ComputeExpiryPressureJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + anchor date + window',
        'write_strategy' => 'natural-key update-or-insert',
    ],
    ComputePositioningJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + anchor date',
        'write_strategy' => 'per-symbol atomic replace',
    ],
    ComputeUAJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + UA parameters + frozen anchor date',
        'write_strategy' => 'per-symbol/date rebuild using natural uniqueness',
    ],
    ComputeVolMetricsJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + resolved session',
        'write_strategy' => 'per-symbol atomic derived-metric publication',
    ],
    FetchCalculatorChainJob::class => [
        'connection' => 'redis', 'queues' => ['calculator'], 'max_timeout' => 270,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + selected expiration',
        'write_strategy' => 'natural-key upsert; completeness publication remains GEX-008/GEX-009',
        'provider_timeout' => 30,
    ],
    FetchOptionChainDataJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + target date + horizon',
        'write_strategy' => 'symbol/date guard and natural-key upserts',
        'provider_timeout' => 20,
    ],
    FetchPolygonIntradayOptionsJob::class => [
        'connection' => 'redis', 'queues' => ['intraday', 'intraday-heavy', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['intraday' => 105, 'intraday-heavy' => 540, 'bootstrap' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + current market session',
        'write_strategy' => 'contract capture upsert and aggregate upsert; nullable total uniqueness remains GEX-012',
        'provider_timeout' => 10,
    ],
    FetchUnderlyingQuotesJob::class => [
        'connection' => 'redis', 'queues' => ['quotes'], 'max_timeout' => 90,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols',
        'write_strategy' => 'one current row per symbol',
        'provider_timeout' => 10,
    ],
    PricesBackfillJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + history window + frozen end date',
        'write_strategy' => 'symbol/trade-date update-or-insert',
        'provider_timeout' => 10,
    ],
    PricesDailyJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + market session',
        'write_strategy' => 'symbol/trade-date update-or-insert',
        'provider_timeout' => 15,
    ],
    PrimeSymbolJob::class => [
        'connection' => 'redis', 'queues' => ['prime'], 'max_timeout' => 60,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol',
        'write_strategy' => 'read-before-dispatch ordered child chain',
    ],
    QueueSymbolEnrichmentJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 30,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + source',
        'write_strategy' => 'cache dispatch guard',
    ],
    Seasonality5DJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + seasonality parameters + frozen as-of date',
        'write_strategy' => 'symbol/date update-or-insert',
    ],
    SendLifecycleEmailJob::class => [
        'connection' => 'redis', 'queues' => ['default'], 'max_timeout' => 60,
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'user + lifecycle event + template',
        'write_strategy' => 'lifecycle log uniqueness narrows duplicates; accepted-before-commit mail needs an outbox',
    ],
];
