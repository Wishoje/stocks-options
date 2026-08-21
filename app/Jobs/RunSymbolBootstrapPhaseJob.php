<?php

namespace App\Jobs;

use App\Exceptions\QuoteRefreshIncomplete;
use App\Jobs\Middleware\EnsureSymbolBootstrapPhaseCurrent;
use App\Jobs\Middleware\EnsureSymbolBootstrapPhaseOrchestrationCurrent;
use App\Jobs\Middleware\EnsureWorkRunOrchestrationCurrent;
use App\Models\SymbolBootstrapRun;
use App\Models\UnderlyingQuote;
use App\Support\EodCacheVersion;
use App\Support\MassiveExpirationCatalog;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\QueueLanes;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPhaseDispatcher;
use App\Support\WorkRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RunSymbolBootstrapPhaseJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 540;

    // Durable retries rotate the phase token through the reconciler. Allowing
    // Laravel to retry the same payload would let overlapping deliveries share
    // one token after a timeout.
    public int $tries = 1;

    public function __construct(
        public string $workRunId,
        public string $phase,
        public string $phaseToken,
        public string $workRunDeliveryToken,
        public int $workRunAttempt,
        public string $workRunOrchestrationToken
    ) {}

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [
            new EnsureWorkRunOrchestrationCurrent(
                $this->workRunId,
                $this->workRunDeliveryToken,
                $this->workRunAttempt,
                $this->workRunOrchestrationToken
            ),
            new EnsureSymbolBootstrapPhaseCurrent(
                $this->workRunId,
                $this->phase,
                $this->phaseToken
            ),
        ];
    }

    public function handle(
        SymbolBootstrapCoordinator $coordinator,
        SymbolBootstrapPhaseDispatcher $dispatcher,
        WorkRunCoordinator $workRuns
    ): void {
        if ($this->phase === SymbolBootstrapCoordinator::PHASE_ENRICHMENT
            && $coordinator->hasDispatchedPhaseOrchestration(
                $this->workRunId,
                $this->phase,
                $this->phaseToken
            )) {
            return;
        }

        $attempt = max(1, $this->attempts());
        if (! $coordinator->markPhaseStarted(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            now('UTC')
        )) {
            return;
        }
        if (! $workRuns->heartbeatOrchestration(
            $this->workRunId,
            $this->workRunDeliveryToken,
            $this->workRunAttempt,
            $this->workRunOrchestrationToken,
            now('UTC')
        )) {
            return;
        }

        if ($this->phase === SymbolBootstrapCoordinator::PHASE_ENRICHMENT) {
            $this->dispatchEnrichment($coordinator, $attempt);

            return;
        }

        $outcome = match ($this->phase) {
            SymbolBootstrapCoordinator::PHASE_QUOTE => $this->runQuote(),
            SymbolBootstrapCoordinator::PHASE_CATALOG => $this->runCatalog($coordinator),
            SymbolBootstrapCoordinator::PHASE_FAST_EOD => $this->runEod($coordinator, 'fast', true),
            SymbolBootstrapCoordinator::PHASE_INTRADAY => $this->runIntraday(),
            SymbolBootstrapCoordinator::PHASE_FILL => $this->runEod($coordinator, 'fill', false),
            default => throw new RuntimeException("Unknown symbol-bootstrap phase [{$this->phase}]."),
        };

        if ($coordinator->markPhaseCompleted(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            $outcome,
            now('UTC')
        )) {
            $dispatcher->dispatchReady($this->workRunId);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(SymbolBootstrapCoordinator::class)->markPhaseFailed(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            max(1, $this->attempts()),
            $this->phaseErrorCategory($exception),
            $this->phaseErrorCode($exception),
            now('UTC')
        );

        parent::failed($exception);
    }

    /** @return array<string,mixed> */
    private function runQuote(): array
    {
        $manifest = $this->manifest();
        $base = (string) config('services.massive.base', 'https://api.massive.com');
        $mode = (string) config('services.massive.mode', 'header');
        $key = trim((string) config('services.massive.key', ''));
        if ($key === '') {
            throw new RuntimeException('Quote refresh configuration invalid: missing_api_key');
        }
        if (filter_var($base, FILTER_VALIDATE_URL) === false
            || ! in_array($mode, ['header', 'bearer', 'query'], true)
            || ($mode === 'header' && trim((string) config('services.massive.header', 'X-API-Key')) === '')
            || ($mode === 'query' && trim((string) config('services.massive.qparam', 'apiKey')) === '')) {
            throw new RuntimeException('Quote refresh configuration invalid: invalid_configuration');
        }

        try {
            (new FetchUnderlyingQuotesJob([$manifest->symbol]))
                ->onConnection((string) $this->connection)
                ->onQueue((string) $this->queue)
                ->handle();
        } catch (QuoteRefreshIncomplete $exception) {
            $storedQuote = UnderlyingQuote::query()
                ->where('symbol', $manifest->symbol)
                ->where('last_price', '>', 0)
                ->first(['source', 'asof']);

            Log::warning('SymbolBootstrap.quoteUnavailable', [
                'work_run_id' => $this->workRunId,
                'symbol' => $manifest->symbol,
                'stored_quote_available' => $storedQuote !== null,
                'reason' => $exception->getMessage(),
            ]);

            return [
                'symbol' => $manifest->symbol,
                'quote_ready' => false,
                'stored_quote_available' => $storedQuote !== null,
                'status' => $storedQuote === null ? 'unavailable' : 'stored_fallback',
            ];
        }

        return [
            'symbol' => $manifest->symbol,
            'quote_ready' => true,
            'status' => 'refreshed',
        ];
    }

    /** @return array<string,mixed> */
    private function runCatalog(SymbolBootstrapCoordinator $coordinator): array
    {
        $manifest = $this->manifest();
        if ($manifest->catalog_frozen_at !== null) {
            return [
                'catalog_status' => 'already_frozen',
                'expirations' => count($coordinator->expirations($this->workRunId, 'all')),
            ];
        }

        $result = app(ProviderConcurrencyLimiter::class)->withPriority(
            QueueLanes::providerPriority($this->queue),
            fn (): array => app(MassiveExpirationCatalog::class)->discover(
                $manifest->symbol,
                $manifest->catalog_horizon_start->toDateString(),
                $manifest->fill_horizon_days
            ),
            1
        );
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        if (! ($meta['complete'] ?? false) || ($meta['capped'] ?? false)) {
            $status = substr((string) ($meta['status'] ?? 'incomplete_catalog'), 0, 96);
            throw new RuntimeException("Option expiration catalog incomplete: {$status}");
        }
        $expirations = is_array($result['expirations'] ?? null) ? $result['expirations'] : [];
        $coordinator->freezeCatalog(
            $this->workRunId,
            $this->phaseToken,
            $expirations,
            $meta,
            now('UTC')
        );

        return [
            'catalog_status' => $expirations === []
                ? 'no_options'
                : (string) ($meta['status'] ?? 'ok'),
            'source' => (string) ($meta['source'] ?? 'massive_reference'),
            'pages' => (int) ($meta['pages'] ?? 0),
            'expirations' => count($expirations),
        ];
    }

    /** @return array<string,mixed> */
    private function runEod(
        SymbolBootstrapCoordinator $coordinator,
        string $scope,
        bool $mergeOnly
    ): array {
        $manifest = $this->manifest();
        // The fill phase is the final parity pass. Revisit the fast subset too,
        // because its merge-only publication may have preserved stale rows.
        $fetchScope = $scope === 'fill' ? 'all' : $scope;
        $expirations = $coordinator->expirations($this->workRunId, $fetchScope);
        $timeout = $this->eodTimeout($scope);
        if ($expirations !== []) {
            (new FetchOptionChainDataJob(
                [$manifest->symbol],
                $manifest->fill_horizon_days,
                $manifest->session_date->toDateString(),
                $timeout,
                $expirations,
                $mergeOnly
            ))->onConnection((string) $this->connection)
                ->onQueue((string) $this->queue)
                ->handle();
        }

        $ready = $coordinator->publishCoverage(
            $this->workRunId,
            $scope,
            $this->phaseToken,
            max(1, $this->attempts()),
            now('UTC')
        );

        return [
            'scope' => $scope,
            'fetch_scope' => $fetchScope,
            'merge_only' => $mergeOnly,
            'timeout_seconds' => $timeout,
            'expirations_ready' => count($ready),
        ];
    }

    private function eodTimeout(string $scope): int
    {
        return $scope === 'fast' ? 270 : 540;
    }

    /** @return array<string,mixed> */
    private function runIntraday(): array
    {
        $manifest = $this->manifest();
        $interactiveQueue = (string) config(
            'queue_lanes.queues.intraday_interactive',
            'intraday-interactive'
        );
        $heavyQueue = (string) config('queue_lanes.queues.intraday_heavy', 'intraday-heavy');
        $interactive = $this->queue === $interactiveQueue;
        $timeout = $this->queue === $heavyQueue ? 540 : 105;
        if ($manifest->expected_count === 0) {
            return [
                'status' => 'no_options',
                'max_expirations' => 0,
                'interactive' => $interactive,
            ];
        }
        $liveTradeDate = $this->liveTradingDate();

        $job = (new FetchPolygonIntradayOptionsJob(
            [$manifest->symbol],
            $timeout,
            // Intraday owns the live trading session. The manifest session is
            // the completed EOD anchor and may be Friday during Monday RTH.
            $liveTradeDate,
            interactive: $interactive,
            maxExpirations: 8
        ))->onConnection((string) $this->connection)->onQueue((string) $this->queue);
        $status = $job->execute();
        if ($status !== 'ok') {
            throw new RuntimeException("First-use intraday refresh incomplete: {$status}");
        }

        return [
            'status' => $status,
            'max_expirations' => 8,
            'interactive' => $interactive,
            'timeout_seconds' => $timeout,
            'trade_date' => $job->tradeDate,
        ];
    }

    private function liveTradingDate(): string
    {
        $ny = now('America/New_York');
        if ($ny->isWeekend() || (int) $ny->format('Hi') < 930) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }

    private function dispatchEnrichment(
        SymbolBootstrapCoordinator $coordinator,
        int $attempt
    ): void {
        $orchestrationToken = $coordinator->reservePhaseOrchestration(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            now('UTC')
        );
        if (! $orchestrationToken) {
            return;
        }

        $manifest = $this->manifest();
        $prime = new PrimeSymbolJob($manifest->symbol);
        $planned = collect($prime->plannedJobs($manifest->session_date->toDateString()))
            ->reject(static fn (object $job): bool => $job instanceof FetchOptionChainDataJob)
            ->when(
                $manifest->expected_count === 0,
                static fn ($jobs) => $jobs->reject(static fn (object $job): bool => $job instanceof ComputeVolMetricsJob
                    || $job instanceof ComputeExpiryPressureJob
                    || $job instanceof ComputePositioningJob
                    || $job instanceof ComputeUAJob
                )
            )
            ->values()
            ->all();
        $parentMiddleware = new EnsureWorkRunOrchestrationCurrent(
            $this->workRunId,
            $this->workRunDeliveryToken,
            $this->workRunAttempt,
            $this->workRunOrchestrationToken
        );
        $phaseMiddleware = new EnsureSymbolBootstrapPhaseOrchestrationCurrent(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            $orchestrationToken
        );
        $middleware = [$parentMiddleware, $phaseMiddleware];

        $jobs = [new ConfirmSymbolBootstrapPhaseOrchestrationJob(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            $orchestrationToken
        )];
        array_push($jobs, ...$planned);
        $jobs[] = new PublishEodCacheVersionJob([$manifest->symbol], EodCacheVersion::ALL_DOMAINS);
        $jobs[] = new CompleteSymbolBootstrapPhaseJob(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $attempt,
            ['planned_jobs' => count($planned)]
        );

        $jobs = array_map(
            fn (object $job): object => $job
                ->onConnection((string) $this->connection)
                ->onQueue((string) $this->queue)
                ->through($middleware),
            $jobs
        );

        $workRunId = $this->workRunId;
        $phase = $this->phase;
        $phaseToken = $this->phaseToken;
        try {
            Bus::chain($jobs)
                ->onConnection((string) $this->connection)
                ->onQueue((string) $this->queue)
                ->catch(static function (Throwable $exception) use (
                    $workRunId,
                    $phase,
                    $phaseToken,
                    $attempt
                ): void {
                    app(SymbolBootstrapCoordinator::class)->markPhaseFailed(
                        $workRunId,
                        $phase,
                        $phaseToken,
                        $attempt,
                        'enrichment',
                        substr('terminal_exception:'.$exception::class, 0, 128),
                        now('UTC')
                    );
                })
                ->dispatch();
        } catch (Throwable $exception) {
            $coordinator->markPhaseOrchestrationDispatchFailed(
                $this->workRunId,
                $this->phase,
                $this->phaseToken,
                $attempt,
                $orchestrationToken
            );

            throw $exception;
        }
    }

    private function manifest(): SymbolBootstrapRun
    {
        return SymbolBootstrapRun::query()->findOrFail($this->workRunId);
    }

    private function phaseErrorCategory(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if ($exception instanceof InvalidArgumentException) {
            return 'validation';
        }

        return match (true) {
            str_contains($message, 'no option contract') => 'no_options',
            str_contains($message, 'unauthorized'), str_contains($message, 'missing_api_key'),
            str_contains($message, ' 401'), str_contains($message, ' 403') => 'provider_authentication',
            str_contains($message, 'invalid_configuration') => 'configuration',
            str_contains($message, 'invalid_request') => 'validation',
            str_contains($message, 'scope_violation'), str_contains($message, 'malformed_payload') => 'provider_validation',
            str_contains($message, '429'), str_contains($message, 'rate limit'),
            str_contains($message, 'rate_limited') => 'provider_rate_limited',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'timeout',
            str_contains($message, 'catalog') => 'provider_catalog',
            default => 'unexpected',
        };
    }

    private function phaseErrorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        foreach ([
            'unauthorized',
            'missing_api_key',
            'invalid_request',
            'invalid_configuration',
            'cursor_scope_violation',
            'scope_violation',
            'malformed_payload',
            'rate_limited',
            'pagination_capped',
            'pagination_no_progress',
            'cursor_cycle',
            'http_error',
        ] as $code) {
            if (str_contains($message, $code)) {
                return 'catalog_'.$code;
            }
        }

        return substr('terminal_exception:'.$exception::class, 0, 128);
    }
}
