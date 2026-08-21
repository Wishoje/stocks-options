<?php

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\BuildAiExportJob;
use App\Jobs\CompleteSymbolBootstrapPhaseJob;
use App\Jobs\CompleteWorkRunJob;
use App\Jobs\ComputeBlindSpotsJob;
use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\ConfirmSymbolBootstrapPhaseOrchestrationJob;
use App\Jobs\ConfirmWorkRunOrchestrationJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PrimeSymbolJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Jobs\QueueSymbolEnrichmentJob;
use App\Jobs\RunSymbolBootstrapPhaseJob;
use App\Jobs\Seasonality5DJob;
use App\Jobs\SendLifecycleEmailJob;

$standardBackoff = [15, 60, 180];

return [
    BootstrapUserSymbolJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 60,
        'isolated_queues' => ['bootstrap-fast'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + source',
        'write_strategy' => 'dispatch guards and an ordered child chain',
    ],
    BuildAiExportJob::class => [
        'connection' => 'redis-long', 'queues' => ['exports'], 'max_timeout' => 900,
        'isolated_queues' => ['exports'],
        'tries' => 2, 'backoff' => [60], 'identity' => 'export id',
        'write_strategy' => 'one export row transitions queued -> processing -> completed/failed',
    ],
    CompleteSymbolBootstrapPhaseJob::class => [
        'connection' => 'redis', 'queues' => [], 'max_timeout' => 30,
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'work run + phase + phase token + fenced attempt',
        'write_strategy' => 'phase-token-and-attempt-fenced durable completion transition',
    ],
    ComputeBlindSpotsJob::class => [
        'connection' => 'redis', 'queues' => ['default'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540],
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + lookahead',
        'write_strategy' => 'natural-key update-or-insert',
    ],
    ComputeExpiryPressureJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'isolated_queues' => ['default', 'bootstrap-fast'],
        'isolated_queue_timeouts' => ['default' => 540, 'bootstrap-fast' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + anchor date + window',
        'write_strategy' => 'natural-key update-or-insert',
    ],
    ComputePositioningJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'isolated_queues' => ['default', 'bootstrap-fast'],
        'isolated_queue_timeouts' => ['default' => 540, 'bootstrap-fast' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + anchor date',
        'write_strategy' => 'per-symbol atomic replace',
    ],
    ComputeUAJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + UA parameters + frozen anchor date',
        'write_strategy' => 'per-symbol/date rebuild using natural uniqueness',
    ],
    ComputeVolMetricsJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + resolved session',
        'write_strategy' => 'per-symbol atomic derived-metric publication',
    ],
    ConfirmSymbolBootstrapPhaseOrchestrationJob::class => [
        'connection' => 'redis', 'queues' => [], 'max_timeout' => 30,
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'work run + phase + phase token + fenced orchestration attempt',
        'write_strategy' => 'phase-orchestration-token-fenced durable dispatch confirmation',
    ],
    CompleteWorkRunJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 60,
        'isolated_queues' => ['bootstrap-fast'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'work run + fenced attempt',
        'write_strategy' => 'token-fenced durable work-run terminal transition',
    ],
    ConfirmWorkRunOrchestrationJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 60,
        'isolated_queues' => ['bootstrap-fast'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'work run + fenced orchestration attempt',
        'write_strategy' => 'token-fenced durable orchestration confirmation',
    ],
    FetchCalculatorChainJob::class => [
        'connection' => 'redis', 'queues' => ['calculator'], 'max_timeout' => 270,
        'isolated_queues' => ['calculator-interactive', 'calculator-fill', 'calculator-fill-heavy'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + selected expiration + scheduled or durable generation',
        'write_strategy' => 'immutable expiry publication with generation-fenced catalog and compatibility pointers',
        'provider_timeout' => 30,
    ],
    FetchOptionChainDataJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'isolated_queues' => ['default', 'bootstrap-fast'],
        'isolated_queue_timeouts' => ['default' => 540, 'bootstrap-fast' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + target date + horizon',
        'write_strategy' => 'symbol/date guard and natural-key upserts',
        'provider_timeout' => 20,
    ],
    FetchPolygonIntradayOptionsJob::class => [
        'connection' => 'redis', 'queues' => ['intraday', 'intraday-heavy', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['intraday' => 105, 'intraday-heavy' => 540, 'bootstrap' => 270],
        'isolated_queues' => ['intraday', 'intraday-heavy', 'intraday-interactive', 'bootstrap-fast'],
        'isolated_queue_timeouts' => [
            'intraday' => 105,
            'intraday-heavy' => 540,
            'intraday-interactive' => 105,
            'bootstrap-fast' => 270,
        ],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + current market session',
        'write_strategy' => 'contract capture upsert plus freshness-fenced legacy total and flagged canonical dual-write',
        'provider_timeout' => 10,
    ],
    FetchUnderlyingQuotesJob::class => [
        'connection' => 'redis', 'queues' => ['quotes'], 'max_timeout' => 90,
        'isolated_queues' => ['quotes'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols',
        'write_strategy' => 'one current row per symbol',
        'provider_timeout' => 10,
    ],
    PricesBackfillJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + history window + frozen end date',
        'write_strategy' => 'symbol/trade-date update-or-insert',
        'provider_timeout' => 10,
    ],
    PricesDailyJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110, 'bootstrap' => 270],
        'isolated_queues' => ['default', 'bootstrap-fast'],
        'isolated_queue_timeouts' => ['default' => 540, 'bootstrap-fast' => 270],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + market session',
        'write_strategy' => 'symbol/trade-date update-or-insert',
        'provider_timeout' => 15,
    ],
    PrimeSymbolJob::class => [
        'connection' => 'redis', 'queues' => ['prime'], 'max_timeout' => 60,
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + completed cache domains',
        'write_strategy' => 'read-before-dispatch ordered child chain',
    ],
    PublishEodCacheVersionJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime', 'bootstrap'], 'max_timeout' => 30,
        'isolated_queues' => ['default', 'bootstrap-fast'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + stable publication token',
        'write_strategy' => 'idempotent per-symbol EOD cache publication fence',
    ],
    QueueSymbolEnrichmentJob::class => [
        'connection' => 'redis', 'queues' => ['bootstrap'], 'max_timeout' => 30,
        'isolated_queues' => ['bootstrap-fast'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'symbol + source',
        'write_strategy' => 'cache dispatch guard',
    ],
    RunSymbolBootstrapPhaseJob::class => [
        'connection' => 'redis', 'queues' => [], 'max_timeout' => 540,
        'isolated_queues' => ['bootstrap-fast', 'intraday-interactive', 'intraday', 'intraday-heavy', 'default'],
        'isolated_queue_timeouts' => [
            'bootstrap-fast' => 270,
            'intraday-interactive' => 105,
            'intraday' => 105,
            'intraday-heavy' => 540,
            'default' => 540,
        ],
        'tries' => 1, 'backoff' => $standardBackoff,
        'identity' => 'work run + phase + phase delivery token + fenced parent orchestration',
        'write_strategy' => 'phase-token-fenced durable transition with coverage-fenced EOD publication',
    ],
    Seasonality5DJob::class => [
        'connection' => 'redis', 'queues' => ['default', 'prime'], 'max_timeout' => 540,
        'queue_timeouts' => ['default' => 540, 'prime' => 110],
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'sorted symbols + seasonality parameters + frozen as-of date',
        'write_strategy' => 'symbol/date update-or-insert',
    ],
    SendLifecycleEmailJob::class => [
        'connection' => 'redis', 'queues' => ['default'], 'max_timeout' => 60,
        'isolated_queues' => ['default'],
        'tries' => 3, 'backoff' => $standardBackoff, 'identity' => 'user + lifecycle event + template',
        'write_strategy' => 'lifecycle log uniqueness narrows duplicates; accepted-before-commit mail needs an outbox',
    ],
];
