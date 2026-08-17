<?php

namespace App\Jobs;

use App\Support\CalculatorPublicationRepository;
use App\Support\CalculatorRefreshState;
use App\Support\CalculatorUnderlyingResolver;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\QueueLanes;
use App\Support\UnderlyingQuoteRecorder;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FetchCalculatorChainJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 270;

    public int $tries = 3;

    public string $symbol;

    public ?string $expiry;

    public ?string $schedulerGeneration = null;

    public ?string $schedulerClaimToken = null;

    public ?string $workRunId = null;

    public ?string $workRunDeliveryToken = null;

    public function __construct(
        string $symbol,
        ?string $expiry = null,
        ?string $schedulerGeneration = null,
        ?string $schedulerClaimToken = null,
        ?string $workRunId = null,
        ?string $workRunDeliveryToken = null
    ) {
        if (($schedulerGeneration === null) !== ($schedulerClaimToken === null)) {
            throw new InvalidArgumentException('Scheduler generation and claim token must be provided together.');
        }
        if ($expiry !== null && $schedulerGeneration !== null) {
            throw new InvalidArgumentException('A selected-expiration fetch cannot own catalog scheduler state.');
        }
        if (($workRunId === null) !== ($workRunDeliveryToken === null)) {
            throw new InvalidArgumentException('Work-run ID and delivery token must be provided together.');
        }
        if ($schedulerGeneration !== null && $workRunId !== null) {
            throw new InvalidArgumentException('A calculator job cannot own scheduler and durable work-run state together.');
        }

        $this->symbol = $symbol;
        $this->expiry = $expiry;
        $this->schedulerGeneration = $schedulerGeneration;
        $this->schedulerClaimToken = $schedulerClaimToken;
        $this->workRunId = $workRunId;
        $this->workRunDeliveryToken = $workRunDeliveryToken;
        $this->onQueue(QueueLanes::calculator($symbol));
    }

    public function handle(): void
    {
        $workRuns = $this->workRunCoordinator();
        if ($workRuns && ! $workRuns->markStarted(
            (string) $this->workRunId,
            (string) $this->workRunDeliveryToken,
            $this->attempts(),
            now()
        )) {
            Log::info('CalculatorChain.staleWorkRunSkipped', [
                'symbol' => $this->symbol,
                'work_run_id' => $this->workRunId,
            ]);

            return;
        }

        $state = $this->scheduledState();
        if ($state && ! $state->markStarted(
            $this->symbol,
            (string) $this->schedulerGeneration,
            (string) $this->schedulerClaimToken,
            $this->attempts(),
            now()
        )) {
            Log::info('CalculatorChain.staleScheduledRunSkipped', [
                'symbol' => $this->symbol,
                'generation' => $this->schedulerGeneration,
            ]);

            return;
        }

        $limiter = app(ProviderConcurrencyLimiter::class);
        try {
            $status = $limiter->withPriority(
                QueueLanes::providerPriority($this->queue),
                fn (): string => $this->fetch(),
                10
            );
        } catch (Throwable $exception) {
            $state?->markAttemptException(
                $this->symbol,
                (string) $this->schedulerGeneration,
                (string) $this->schedulerClaimToken,
                $this->attempts(),
                'attempt_exception:'.$exception::class,
                now()
            );

            throw $exception;
        }

        if (! $state) {
            if ($workRuns) {
                if ($status === 'ok') {
                    $workRuns->markCompleted(
                        (string) $this->workRunId,
                        (string) $this->workRunDeliveryToken,
                        $this->attempts(),
                        now()
                    );
                } else {
                    $workRuns->markFailed(
                        (string) $this->workRunId,
                        (string) $this->workRunDeliveryToken,
                        $this->attempts(),
                        'calculator_incomplete',
                        $status,
                        now()
                    );
                }
            }

            return;
        }

        if ($status === 'ok') {
            $state->markCompleted(
                $this->symbol,
                (string) $this->schedulerGeneration,
                (string) $this->schedulerClaimToken,
                now()
            );

            return;
        }

        $state->markFailed(
            $this->symbol,
            (string) $this->schedulerGeneration,
            (string) $this->schedulerClaimToken,
            $status,
            now()
        );
    }

    public function failed(Throwable $exception): void
    {
        try {
            $publications = app(CalculatorPublicationRepository::class);
            $run = $this->publicationRun($publications);
            if (! in_array((string) ($run['status'] ?? ''), ['complete', 'superseded'], true)) {
                $publications->markRunFailed(
                    (string) $run['id'],
                    'terminal_exception',
                    $exception::class
                );
            }
        } catch (Throwable $publicationException) {
            Log::warning('CalculatorChain.publicationFailureRecordFailed', [
                'symbol' => $this->symbol,
                'exception' => $publicationException::class,
            ]);
        }

        $this->workRunCoordinator()?->markTerminalException(
            (string) $this->workRunId,
            (string) $this->workRunDeliveryToken,
            max(1, $this->attempts()),
            $exception
        );

        $this->scheduledState()?->markFailed(
            $this->symbol,
            (string) $this->schedulerGeneration,
            (string) $this->schedulerClaimToken,
            'terminal_exception:'.$exception::class,
            now()
        );

        parent::failed($exception);
    }

    /** @return array<string, mixed> */
    protected function identityPayload(): array
    {
        $payload = [
            'symbol' => $this->symbol,
            'expiry' => $this->expiry,
        ];

        if ($this->schedulerGeneration !== null) {
            $payload['schedulerGeneration'] = $this->schedulerGeneration;
        }
        if ($this->workRunId !== null) {
            $payload['workRunId'] = $this->workRunId;
        }

        return $payload;
    }

    private function workRunCoordinator(): ?WorkRunCoordinator
    {
        return $this->workRunId !== null ? app(WorkRunCoordinator::class) : null;
    }

    private function fetch(): string
    {
        $symbol = strtoupper($this->symbol);
        $targetExpiry = $this->expiry ? substr((string) $this->expiry, 0, 10) : null;
        $publications = app(CalculatorPublicationRepository::class);
        $publicationRun = $this->publicationRun($publications);
        $publicationRunId = (string) $publicationRun['id'];
        if (in_array((string) $publicationRun['status'], ['complete', 'superseded'], true)) {
            return 'ok';
        }
        if ((string) $publicationRun['status'] === 'capped') {
            return 'partial_pagination_capped';
        }
        if ((string) $publicationRun['status'] === 'failed') {
            return (string) ($publicationRun['failure_code'] ?: 'calculator_incomplete');
        }
        $apiKey = config('services.massive.key') ?: env('MASSIVE_API_KEY');
        $base = rtrim((string) config('services.massive.base', 'https://api.massive.com'), '/');
        $mode = (string) config('services.massive.mode', 'header'); // header|bearer|query
        $header = (string) config('services.massive.header', 'X-API-Key');
        $qparam = (string) config('services.massive.qparam', 'apiKey');

        $makeRequest = function (int $timeout = 30) use ($mode, $header, $apiKey) {
            $req = Http::timeout($timeout)
                ->connectTimeout(min(5, max(1, $timeout - 1)))
                ->acceptJson()
                ->withHeaders(['Accept' => 'application/json']);

            if ($mode === 'bearer') {
                return $req->withToken($apiKey);
            }

            if ($mode === 'header') {
                return $req->withHeaders([$header => $apiKey]);
            }

            return $req; // query mode
        };

        $authParams = function (array $params = []) use ($mode, $qparam, $apiKey) {
            if ($mode === 'query') {
                $params[$qparam] = $apiKey;
            }

            return $params;
        };
        $metaKey = static fn (string $sym, ?string $exp = null): string => 'calculator:fetch-meta:'.md5($sym.'|'.($exp ?? '*'));
        $storeMeta = function (array $meta) use ($metaKey, $symbol, $targetExpiry): void {
            Cache::put(
                $metaKey($symbol, $targetExpiry),
                array_merge([
                    'symbol' => $symbol,
                    'target_expiry' => $targetExpiry,
                    'recorded_at' => now()->toIso8601String(),
                ], $meta),
                now()->addHours(12)
            );
        };

        // Log::debug('CalculatorChain.start', [
        //     'symbol' => $symbol,
        //     'base'   => $base,
        //     'hasKey' => (bool) $apiKey,
        // ]);

        if (! $apiKey) {
            Log::error('MassiveClient.missingKey', ['job' => 'CalculatorChain']);
            $storeMeta([
                'status' => 'failed_missing_api_key',
                'publication_run_id' => $publicationRunId,
            ]);
            $publications->markRunFailed(
                $publicationRunId,
                'missing_api_key',
                'The calculator provider API key is unavailable.'
            );

            return 'failed_missing_api_key';
        }

        // -----------------------------
        // Step 1: Underlying price
        // -----------------------------
        $uResp = app(ProviderConcurrencyLimiter::class)->massive(
            fn () => $makeRequest(10)->get(
                "{$base}/v3/snapshot",
                $authParams([
                    'ticker.any_of' => $symbol,
                    'limit' => 1,
                ])
            )
        );

        // Log::debug('CalculatorChain.underlying.response', [
        //     'status' => $uResp->status(),
        //     'ok'     => $uResp->ok(),
        // ]);

        $uJson = $uResp->json();
        $u0 = $uJson['results'][0] ?? [];

        // snake + camel for Massive unified snapshot
        $uQuoteSnake = $u0['last_quote'] ?? [];
        $uQuoteCamel = $u0['lastQuote'] ?? [];
        $uQuote = $uQuoteSnake ?: $uQuoteCamel;

        $uTradeSnake = $u0['last_trade'] ?? [];
        $uTradeCamel = $u0['lastTrade'] ?? [];
        $uTrade = $uTradeSnake ?: $uTradeCamel;

        $uSession = $u0['session'] ?? [];
        $uDay = $u0['day'] ?? [];

        $rawU = $uQuote['midpoint']
            ?? $uQuote['mid']
            ?? $uQuote['mark']
            ?? $uTrade['price']
            ?? $uTrade['p']
            ?? ($uSession['close'] ?? null)
            ?? ($uDay['close'] ?? null);

        $rawUnderlyingAsof = $u0['updated']
            ?? $u0['last_updated']
            ?? $uQuote['sip_timestamp']
            ?? $uQuote['participant_timestamp']
            ?? $uQuote['last_updated']
            ?? $uTrade['sip_timestamp']
            ?? $uTrade['participant_timestamp']
            ?? $uTrade['last_updated']
            ?? $uSession['last_updated']
            ?? $uDay['last_updated']
            ?? null;
        if ($uResp->ok()) {
            app(UnderlyingQuoteRecorder::class)->record(
                $symbol,
                $rawU,
                'massive-v3-snapshot',
                $rawUnderlyingAsof
            );
        }

        $underlyingMeta = app(CalculatorUnderlyingResolver::class)->resolve($symbol, now());
        $underlying = $underlyingMeta['price'];

        Log::info('CalculatorChain.underlying', [
            'symbol' => $symbol,
            'price' => $underlying,
            'status' => $underlyingMeta['status'],
            'source' => $underlyingMeta['source'],
            'asof' => $underlyingMeta['asof'],
        ]);

        // -----------------------------
        // Step 2: Option chain (paginated)
        // -----------------------------
        $perPage = 250; // first attempt, fallback to 100 if Massive rejects
        $baseMaxPages = max(50, (int) env('CALC_CHAIN_MAX_PAGES', 150));
        $largeMaxPages = max($baseMaxPages, (int) env('CALC_CHAIN_MAX_PAGES_LARGE', 350));
        $isLargeSymbol = ! $targetExpiry && QueueLanes::isCalculatorHeavy($symbol);
        $maxPages = $isLargeSymbol ? $largeMaxPages : $baseMaxPages;
        $endpointUrl = "{$base}/v3/snapshot/options/{$symbol}";
        $scopeParams = [
            'limit' => $perPage,
            'sort' => 'strike_price',
            'order' => 'asc',
        ];
        if ($targetExpiry) {
            $scopeParams['expiration_date'] = $targetExpiry;
        }
        $contracts = [];
        $page = 0;
        $pageFailedStatus = null;
        $providerFailureCode = null;
        $providerFailureReason = null;
        $cursor = null;
        $hasMorePages = true;
        $visitedCursors = [];

        while ($hasMorePages && $page < $maxPages) {
            if ($cursor !== null && isset($visitedCursors[$cursor])) {
                $providerFailureCode = 'provider_pagination_cycle';
                $providerFailureReason = 'The option-chain provider repeated a pagination cursor.';
                Log::warning('CalculatorChain.paginationCycle', [
                    'symbol' => $symbol,
                    'page' => $page + 1,
                ]);
                break;
            }
            if ($cursor !== null) {
                $visitedCursors[$cursor] = true;
            }
            $page++;
            if ($page === 1 || $page % 10 === 0) {
                $publications->heartbeat($publicationRunId);
            }

            $request = $makeRequest(30);
            $params = $scopeParams;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            // Log::debug('CalculatorChain.page.request', [
            //     'symbol' => $symbol,
            //     'page'   => $page,
            //     'url'    => $endpointUrl,
            //     'params' => $params,
            // ]);

            $resp = app(ProviderConcurrencyLimiter::class)->massive(
                fn () => $request->get($endpointUrl, $authParams($params))
            );

            // limit fallback for page 1
            if (
                $page === 1 &&
                $resp->status() === 400 &&
                str_contains($resp->body(), "'Limit' failed")
            ) {
                Log::warning('CalculatorChain.limitRejected', [
                    'attempted_limit' => $perPage,
                    'status' => $resp->status(),
                ]);

                $perPage = 100;
                $scopeParams['limit'] = $perPage;
                $params = $scopeParams;
                $resp = app(ProviderConcurrencyLimiter::class)->massive(
                    fn () => $request->get($endpointUrl, $authParams($params))
                );

                // Log::debug('CalculatorChain.limitRetry', [
                //     'new_limit' => $perPage,
                //     'status'    => $resp->status(),
                // ]);
            }

            // Log::debug('CalculatorChain.page.response', [
            //     'page'        => $page,
            //     'status'      => $resp->status(),
            //     'ok'          => $resp->ok(),
            // ]);

            if (! $resp->ok()) {
                $pageFailedStatus = $resp->status();
                $providerFailureCode = 'provider_http_error';
                $providerFailureReason = 'The option-chain provider returned HTTP '.$pageFailedStatus.'.';
                Log::warning('CalculatorChain.pageFailed', [
                    'symbol' => $symbol,
                    'page' => $page,
                    'status' => $resp->status(),
                ]);
                break;
            }

            $json = $resp->json();
            if (
                ! is_array($json)
                || ! array_key_exists('results', $json)
                || ! is_array($json['results'])
                || ! array_is_list($json['results'])
            ) {
                $providerFailureCode = 'provider_invalid_payload';
                $providerFailureReason = 'The option-chain provider returned an invalid results payload.';
                Log::warning('CalculatorChain.invalidPayload', [
                    'symbol' => $symbol,
                    'page' => $page,
                ]);
                break;
            }

            $batch = $json['results'];
            $count = count($batch);

            // Log::debug('CalculatorChain.page.results', [
            //     'page'  => $page,
            //     'count' => $count,
            // ]);

            $next = $json['next_url'] ?? null;
            if ($next !== null && ! is_string($next)) {
                $providerFailureCode = 'provider_invalid_payload';
                $providerFailureReason = 'The option-chain provider returned an invalid next cursor.';
                Log::warning('CalculatorChain.invalidNextUrl', [
                    'symbol' => $symbol,
                    'page' => $page,
                ]);
                break;
            }
            $next = is_string($next) && trim($next) !== '' ? trim($next) : null;
            $nextCursor = null;
            if ($next !== null) {
                try {
                    $nextCursor = $this->massiveTrustedCursor(
                        $next,
                        $base,
                        $endpointUrl,
                        $scopeParams,
                        $qparam
                    );
                } catch (RuntimeException $exception) {
                    $providerFailureCode = 'provider_cursor_scope_violation';
                    $providerFailureReason = $exception->getMessage();
                    Log::warning('CalculatorChain.cursorScopeViolation', [
                        'symbol' => $symbol,
                        'page' => $page,
                    ]);
                    break;
                }
            }

            if ($batch !== []) {
                $contracts = array_merge($contracts, $batch);
            }

            // if ($page === 1) {
            //     Log::debug('CalculatorChain.firstContractRaw', [
            //         'symbol' => $symbol,
            //         'sample' => $contracts[0] ?? null,
            //     ]);
            // }

            if ($nextCursor !== null && isset($visitedCursors[$nextCursor])) {
                $providerFailureCode = 'provider_pagination_cycle';
                $providerFailureReason = 'The option-chain provider repeated a pagination cursor.';
                Log::warning('CalculatorChain.paginationCycle', [
                    'symbol' => $symbol,
                    'page' => $page + 1,
                ]);
                break;
            }

            $cursor = $nextCursor;
            $hasMorePages = $nextCursor !== null;
        }

        if ($hasMorePages && $providerFailureCode === null) {
            Log::warning('CalculatorChain.paginationCapReached', [
                'symbol' => $symbol,
                'pages' => $page,
                'max_pages' => $maxPages,
                'target_expiry' => $targetExpiry,
            ]);
        }
        $paginationCapped = $hasMorePages && $providerFailureCode === null;

        Log::info('CalculatorChain.fetchComplete', [
            'symbol' => $symbol,
            'contracts' => count($contracts),
        ]);

        if (empty($contracts)) {
            Log::info('CalculatorChain.noContracts', ['symbol' => $symbol]);
            $authoritativeCatalog = $providerFailureCode === null
                && ! $paginationCapped
                && $targetExpiry === null
                    ? $publications->authoritativeCatalog($symbol)
                    : null;
            $providerEmptyAfterNonempty = (int) ($authoritativeCatalog['expected_count'] ?? 0) > 0;
            $emptyStatus = match (true) {
                $providerFailureCode !== null => $providerFailureCode,
                $paginationCapped => 'partial_pagination_capped',
                $targetExpiry !== null => 'no_contracts',
                $providerEmptyAfterNonempty => 'provider_empty_after_nonempty',
                default => 'no_options',
            };
            $storeMeta([
                'status' => $emptyStatus,
                'pages' => $page,
                'max_pages' => $maxPages,
                'pagination_capped' => $paginationCapped,
                'http_error_status' => $pageFailedStatus,
                'provider_failure_code' => $providerFailureCode
                    ?? ($providerEmptyAfterNonempty ? 'provider_empty_after_nonempty' : null),
                'contracts_fetched' => 0,
                'contracts_kept' => 0,
                'expiries_found' => 0,
                'publication_run_id' => $publicationRunId,
            ]);

            if ($providerFailureCode !== null) {
                $publications->markRunFailed(
                    $publicationRunId,
                    $providerFailureCode,
                    (string) $providerFailureReason
                );

                return $providerFailureCode;
            }
            if ($paginationCapped) {
                $publications->markCapped(
                    $publicationRunId,
                    'Option-chain discovery reached the configured page ceiling.'
                );

                return 'partial_pagination_capped';
            }
            if ($targetExpiry !== null) {
                $publications->markExpiryFailed(
                    $publicationRunId,
                    $targetExpiry,
                    'no_contracts',
                    'The provider returned no contracts for the requested expiration.'
                );

                return 'no_contracts';
            }
            if ($providerEmptyAfterNonempty) {
                $publications->markRunFailed(
                    $publicationRunId,
                    'provider_empty_after_nonempty',
                    'The option-chain provider returned an empty terminal catalog after a previously nonempty catalog.'
                );

                return 'provider_empty_after_nonempty';
            }

            $snapshotAt = now('UTC')->toImmutable();
            $publications->freezeCatalog(
                $publicationRunId,
                [],
                'massive-options-snapshot',
                $snapshotAt,
                terminalCursorReached: true,
                at: $snapshotAt
            );
            $completion = $publications->completeCatalog($publicationRunId, $snapshotAt);
            $runStatus = (string) data_get($completion, 'run.status', 'failed');
            if (! in_array($runStatus, ['complete', 'superseded'], true)) {
                $atomicFailure = (string) data_get(
                    $completion,
                    'run.failure_code',
                    $runStatus
                );
                $storeMeta([
                    'status' => $atomicFailure,
                    'pages' => $page,
                    'max_pages' => $maxPages,
                    'pagination_capped' => false,
                    'http_error_status' => null,
                    'provider_failure_code' => $atomicFailure,
                    'contracts_fetched' => 0,
                    'contracts_kept' => 0,
                    'expiries_found' => 0,
                    'publication_run_id' => $publicationRunId,
                ]);

                return $atomicFailure;
            }

            return 'ok';
        }

        // -----------------------------
        // Step 3: Normalize + upsert
        // -----------------------------
        $inserts = [];
        $now = now('UTC')->toImmutable();
        $exchangeDate = $now->setTimezone('America/New_York')->toDateString();
        $discoveredExpirations = collect($contracts)
            ->map(fn (array $contract): ?string => $this->providerExpiration(
                data_get($contract, 'details.expiration_date'),
                $exchangeDate
            ))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $seen = 0;
        $kept = 0;
        $skipped = 0;

        foreach ($contracts as $c) {
            $seen++;

            $details = $c['details'] ?? [];
            if (empty($details['strike_price'])) {
                $skipped++;

                continue;
            }

            $contractType = strtolower((string) ($details['contract_type'] ?? ''));
            if (! in_array($contractType, ['call', 'put'], true)) {
                $skipped++;

                continue;
            }

            $expiry = $this->providerExpiration(
                $details['expiration_date'] ?? null,
                $exchangeDate
            );
            if (! $expiry) {
                $skipped++;

                continue;
            }
            if ($targetExpiry !== null && $expiry !== $targetExpiry) {
                $skipped++;

                continue;
            }

            // snake + camel for Massive chain
            $quoteSnake = $c['last_quote'] ?? [];
            $quoteCamel = $c['lastQuote'] ?? [];
            $quote = $quoteSnake ?: $quoteCamel;

            $tradeSnake = $c['last_trade'] ?? [];
            $tradeCamel = $c['lastTrade'] ?? [];
            $lastTrade = $tradeSnake ?: $tradeCamel;

            $day = $c['day'] ?? [];
            $fmv = $c['fmv'] ?? null;
            $rawIv = $c['implied_volatility'] ?? null;
            $impliedVolatility = is_numeric($rawIv) && (float) $rawIv > 0
                ? (float) $rawIv
                : null;

            // --- bids/asks from quote (multiple possible keys) ---
            $rawBid = $quote['bid']
                ?? $quote['bid_price']
                ?? $quote['b']
                ?? null;

            $rawAsk = $quote['ask']
                ?? $quote['ask_price']
                ?? $quote['a']
                ?? null;

            $rawMid = $quote['midpoint']
                ?? $quote['mid']
                ?? $quote['mark']
                ?? null;

            $bid = is_numeric($rawBid) ? (float) $rawBid : 0.0;
            $ask = is_numeric($rawAsk) ? (float) $rawAsk : 0.0;

            // primary mid
            if (is_numeric($rawMid)) {
                $mid = (float) $rawMid;
            } elseif ($bid > 0 && $ask > 0) {
                $mid = ($bid + $ask) / 2;
            } else {
                $mid = $bid ?: $ask ?: 0.0;
            }

            // Fallback 1: last trade
            if ($mid == 0.0 && $lastTrade) {
                $lastPrice = $lastTrade['price']
                    ?? $lastTrade['p']
                    ?? null;

                if (is_numeric($lastPrice)) {
                    $mid = (float) $lastPrice;
                }
            }

            // Fallback 2: Fair Market Value
            if ($mid == 0.0 && is_numeric($fmv)) {
                $mid = (float) $fmv;
            }

            // Fallback 3: daily close
            if ($mid == 0.0 && isset($day['close']) && is_numeric($day['close'])) {
                $mid = (float) $day['close'];
            }

            // still useless → skip
            $inserts[] = [
                'symbol' => $symbol,
                'ticker' => $c['ticker'] ?? '',
                'type' => $contractType,
                'strike' => $details['strike_price'],
                'expiry' => $expiry,
                'bid' => round($bid, 2),
                'ask' => round($ask, 2),
                'mid' => round($mid, 2),
                'implied_volatility' => $impliedVolatility,
                'underlying_price' => $underlying !== null ? round((float) $underlying, 2) : null,
                'fetched_at' => $now,
            ];
            $kept++;
        }

        // Log::debug('CalculatorChain.reduceStats', [
        //     'seen'    => $seen,
        //     'kept'    => $kept,
        //     'skipped' => $skipped,
        //     'toUpsert'=> count($inserts),
        // ]);

        $expiriesFound = collect($inserts)->pluck('expiry')->unique()->count();
        $resumingPublication = collect($publications->runManifest($publicationRunId)['expirations'])
            ->contains(fn (array $expiration): bool => ($expiration['readiness'] ?? null) === 'ready');
        $status = $this->publishCalculatorResult(
            $publications,
            $publicationRunId,
            $contracts,
            $inserts,
            $discoveredExpirations,
            $targetExpiry,
            $providerFailureCode,
            $providerFailureReason,
            $paginationCapped,
            $now
        );
        $legacyPublished = ! $resumingPublication
            && $status === 'ok'
            && $inserts !== []
            && $this->writeLegacySnapshotsIfCurrent($publicationRunId, $inserts);
        $storeMeta([
            'status' => $status,
            'pages' => $page,
            'max_pages' => $maxPages,
            'pagination_capped' => $paginationCapped,
            'http_error_status' => $pageFailedStatus,
            'provider_failure_code' => $providerFailureCode,
            'contracts_fetched' => count($contracts),
            'contracts_kept' => count($inserts),
            'expiries_found' => $expiriesFound,
            'underlying_status' => $underlyingMeta['status'],
            'underlying_source' => $underlyingMeta['source'],
            'underlying_asof' => $underlyingMeta['asof'],
            'underlying_usable_for_calculation' => $underlyingMeta['usable_for_calculation'],
            'publication_run_id' => $publicationRunId,
            'publication_generation' => $publicationRun['generation'] ?? null,
            'legacy_snapshot_published' => $legacyPublished,
        ]);

        $logContext = [
            'symbol' => $symbol,
            'status' => $status,
            'contracts' => count($inserts),
            'underlying' => $underlying,
            'target_expiry' => $targetExpiry,
            'pages' => $page,
            'max_pages' => $maxPages,
            'pagination_capped' => $paginationCapped,
            'expiries_found' => $expiriesFound,
        ];
        if ($status === 'ok') {
            Log::info('CalculatorChain.SUCCESS', $logContext);
        } else {
            Log::warning('CalculatorChain.incomplete', $logContext);
        }

        return $status;
    }

    /**
     * Keep compatibility rows behind the same current-pointer fence as immutable publications.
     *
     * A resumed run is deliberately not projected because rows already frozen on an earlier
     * attempt may differ from the retry response. The immutable publication remains authoritative.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeLegacySnapshotsIfCurrent(string $runId, array $rows): bool
    {
        $manifest = app(CalculatorPublicationRepository::class)->runManifest($runId);
        $run = $manifest['run'];
        if (
            ($run['status'] ?? null) !== 'complete'
            || ($run['scope'] ?? null) !== CalculatorPublicationRepository::SCOPE_CATALOG
        ) {
            return false;
        }

        $expected = collect($manifest['expirations'])->keyBy('expiration');
        $candidateExpirations = collect($rows)->pluck('expiry')->unique()->sort()->values();
        if ($candidateExpirations->diff($expected->keys())->isNotEmpty()) {
            return false;
        }
        $legacyContractKeys = collect($rows)->map(static fn (array $row): string => implode('|', [
            strtolower((string) ($row['type'] ?? '')),
            number_format((float) ($row['strike'] ?? 0), 6, '.', ''),
            substr((string) ($row['expiry'] ?? ''), 0, 10),
        ]));
        if ($legacyContractKeys->duplicates()->isNotEmpty()) {
            // The legacy unique key omits ticker/contract identity. Keep its LKG cohort
            // instead of silently collapsing adjusted contracts at the same strike.
            return false;
        }

        return DB::transaction(function () use ($runId, $expected, $rows): bool {
            $lockedRun = DB::table('calculator_publication_runs')
                ->where('id', $runId)
                ->lockForUpdate()
                ->first();
            if (! $lockedRun || (string) $lockedRun->status !== 'complete') {
                return false;
            }

            if ((string) $lockedRun->scope === CalculatorPublicationRepository::SCOPE_CATALOG) {
                $catalogHead = DB::table('calculator_catalog_heads')
                    ->where('symbol', $lockedRun->symbol)
                    ->lockForUpdate()
                    ->first();
                if (! $catalogHead || (string) $catalogHead->current_run_id !== $runId) {
                    return false;
                }
            }

            $expirationHeads = DB::table('calculator_expiry_heads')
                ->where('symbol', $lockedRun->symbol)
                ->whereIn('expiration', $expected->keys()->all())
                ->orderBy('expiration')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (object $head): string => (string) $head->expiration);
            foreach ($expected as $expiration => $readiness) {
                $head = $expirationHeads->get($expiration);
                if (
                    ($readiness['readiness'] ?? null) !== 'ready'
                    || ($readiness['publication_id'] ?? null) === null
                    || ! $head
                    || (string) $head->current_publication_id !== (string) $readiness['publication_id']
                ) {
                    return false;
                }
            }

            foreach (array_chunk($rows, 750) as $chunk) {
                DB::table('option_snapshots')->upsert(
                    $chunk,
                    ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
                    ['bid', 'ask', 'mid', 'implied_volatility', 'underlying_price', 'ticker']
                );
            }

            return true;
        }, 3);
    }

    /** @return array<string, mixed> */
    private function publicationRun(CalculatorPublicationRepository $publications): array
    {
        if ($this->expiry !== null) {
            return $publications->startSelectedExpiryRun(
                $this->symbol,
                substr((string) $this->expiry, 0, 10),
                ownerKey: $this->publicationOwnerKey(),
                purpose: $this->workRunId !== null ? 'interactive_refresh' : 'selected_expiry',
                workRunId: $this->workRunId
            );
        }

        return $publications->startCatalogRun(
            $this->symbol,
            ownerKey: $this->publicationOwnerKey(),
            purpose: $this->schedulerGeneration !== null ? 'scheduled_catalog' : 'full_catalog',
            workRunId: $this->workRunId
        );
    }

    private function publicationOwnerKey(): ?string
    {
        if ($this->workRunId !== null) {
            return null;
        }
        if ($this->schedulerGeneration !== null) {
            return 'scheduler:'.$this->schedulerGeneration;
        }

        return 'job:'.$this->idempotencyKey();
    }

    /**
     * @param  list<array<string, mixed>>  $contracts
     * @param  list<array<string, mixed>>  $inserts
     * @param  list<string>  $discoveredExpirations
     */
    private function publishCalculatorResult(
        CalculatorPublicationRepository $publications,
        string $runId,
        array $contracts,
        array $inserts,
        array $discoveredExpirations,
        ?string $targetExpiry,
        ?string $providerFailureCode,
        ?string $providerFailureReason,
        bool $paginationCapped,
        CarbonImmutable $snapshotAt
    ): string {
        if ($providerFailureCode !== null) {
            $publications->markRunFailed(
                $runId,
                $providerFailureCode,
                (string) $providerFailureReason,
                $snapshotAt
            );

            return $providerFailureCode;
        }
        if ($paginationCapped) {
            $publications->markCapped(
                $runId,
                'Option-chain discovery reached the configured page ceiling.',
                $snapshotAt
            );

            return 'partial_pagination_capped';
        }
        if ($inserts === []) {
            if ($targetExpiry !== null) {
                $publications->markExpiryFailed(
                    $runId,
                    $targetExpiry,
                    'no_usable_contracts',
                    'The provider returned no usable contracts for the requested expiration.',
                    $snapshotAt
                );
            } else {
                $publications->markRunFailed(
                    $runId,
                    'no_usable_contracts',
                    'Terminal discovery returned contracts but none could be normalized.',
                    $snapshotAt
                );
            }

            return 'no_usable_contracts';
        }

        $sourceAsOf = $this->contractSourceAsOf($contracts, $snapshotAt);
        $exchangeDate = $snapshotAt->setTimezone('America/New_York')->toDateString();
        $byExpiration = collect($inserts)
            ->groupBy('expiry')
            ->sortKeys();
        $manifest = $publications->runManifest($runId);
        $expectedExpirations = $targetExpiry === null
            ? $discoveredExpirations
            : $byExpiration->keys()->values()->all();

        if ($targetExpiry === null) {
            if (($manifest['run']['expected_frozen_at'] ?? null) === null) {
                $publications->freezeCatalog(
                    $runId,
                    $expectedExpirations,
                    'massive-options-snapshot',
                    $sourceAsOf,
                    terminalCursorReached: true,
                    discoveryHorizon: $expectedExpirations === []
                        ? null
                        : end($expectedExpirations),
                    at: $snapshotAt
                );
                $manifest = $publications->runManifest($runId);
            } else {
                $expectedExpirations = collect($manifest['expirations'])
                    ->pluck('expiration')
                    ->values()
                    ->all();
            }
        }

        $readinessByExpiration = collect($manifest['expirations'])->keyBy('expiration');
        foreach ($expectedExpirations as $expiration) {
            $readiness = $readinessByExpiration->get($expiration);
            if (($readiness['readiness'] ?? null) === 'ready') {
                continue;
            }
            $rows = $byExpiration->get($expiration);
            if ($rows === null || $rows->isEmpty()) {
                $publications->markExpiryFailed(
                    $runId,
                    (string) $expiration,
                    'expected_expiry_missing',
                    'A frozen catalog expiration was absent from the retry response.',
                    $snapshotAt
                );

                break;
            }
            $expirationContracts = array_values(array_filter(
                $contracts,
                fn (array $contract): bool => $this->providerExpiration(
                    data_get($contract, 'details.expiration_date'),
                    $exchangeDate
                ) === (string) $expiration
            ));
            $expirationSourceAsOf = $this->contractSourceAsOf(
                $expirationContracts,
                $snapshotAt
            );
            $publicationRows = collect($rows)->map(static fn (array $row): array => [
                'ticker' => $row['ticker'] ?: null,
                'type' => $row['type'],
                'strike' => $row['strike'],
                'bid' => $row['bid'],
                'ask' => $row['ask'],
                'mid' => $row['mid'],
                'implied_volatility' => $row['implied_volatility'],
            ])->values()->all();

            $publications->stageAndPublishExpiry(
                $runId,
                (string) $expiration,
                'massive-options-snapshot',
                $expirationSourceAsOf,
                $snapshotAt,
                $publicationRows,
                $snapshotAt
            );
        }

        if ($targetExpiry !== null) {
            $run = $publications->run($runId);

            return in_array((string) ($run['status'] ?? ''), ['complete', 'superseded'], true)
                ? 'ok'
                : (string) ($run['status'] ?? 'partial');
        }

        $completion = $publications->completeCatalog($runId, $snapshotAt);
        $runStatus = (string) data_get($completion, 'run.status', 'partial');

        return in_array($runStatus, ['complete', 'superseded'], true) ? 'ok' : $runStatus;
    }

    /**
     * Accept only the opaque cursor from a provider continuation URL. The
     * endpoint, immutable request scope, and authentication remain local.
     *
     * @param  array<string, mixed>  $scopeParams
     */
    private function massiveTrustedCursor(
        string $nextUrl,
        string $base,
        string $endpointUrl,
        array $scopeParams,
        string $qparam
    ): string {
        if (str_starts_with($nextUrl, '?')) {
            $nextUrl = $endpointUrl.$nextUrl;
        } elseif (! str_starts_with($nextUrl, 'http://') && ! str_starts_with($nextUrl, 'https://')) {
            $nextUrl = rtrim($base, '/').'/'.ltrim($nextUrl, '/');
        }

        $expectedOrigin = parse_url($base);
        $actual = parse_url($nextUrl);
        if (
            ! is_array($expectedOrigin)
            || ! is_array($actual)
            || isset($actual['user'])
            || isset($actual['pass'])
            || isset($actual['fragment'])
            || strtolower((string) ($expectedOrigin['scheme'] ?? '')) !== strtolower((string) ($actual['scheme'] ?? ''))
            || strtolower((string) ($expectedOrigin['host'] ?? '')) !== strtolower((string) ($actual['host'] ?? ''))
            || (int) ($expectedOrigin['port'] ?? 0) !== (int) ($actual['port'] ?? 0)
        ) {
            throw new RuntimeException('Massive returned an untrusted cursor URL.');
        }

        $expectedPath = (string) parse_url($endpointUrl, PHP_URL_PATH);
        $actualPath = (string) ($actual['path'] ?? '');
        if ($expectedPath === '' || $actualPath !== $expectedPath) {
            throw new RuntimeException('Massive returned a cursor for an unexpected endpoint.');
        }

        $cursor = null;
        $seen = [];
        $allowed = array_fill_keys(array_keys($scopeParams), true);
        $allowed[$qparam] = true;
        $allowed['cursor'] = true;
        foreach (explode('&', (string) ($actual['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$encodedKey, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($encodedKey);
            $value = rawurldecode($encodedValue);
            if ($name === '' || isset($seen[$name]) || ! isset($allowed[$name])) {
                throw new RuntimeException('Massive returned an invalid cursor query.');
            }
            $seen[$name] = true;

            if ($name === 'cursor') {
                $cursor = $value;

                continue;
            }

            if ($name !== $qparam && (string) $scopeParams[$name] !== $value) {
                throw new RuntimeException('Massive cursor changed the requested scope.');
            }
        }

        if (! is_string($cursor) || trim($cursor) === '') {
            throw new RuntimeException('Massive returned a malformed cursor.');
        }

        return $cursor;
    }

    private function providerExpiration(mixed $value, string $exchangeDate): ?string
    {
        $expiration = trim((string) $value);
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiration) !== 1
            || $expiration < $exchangeDate
        ) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $expiration));

        return checkdate($month, $day, $year) ? $expiration : null;
    }

    /** @param list<array<string, mixed>> $contracts */
    private function contractSourceAsOf(array $contracts, CarbonImmutable $fallback): CarbonImmutable
    {
        $latest = null;
        foreach ($contracts as $contract) {
            $quote = ($contract['last_quote'] ?? []) ?: ($contract['lastQuote'] ?? []);
            $trade = ($contract['last_trade'] ?? []) ?: ($contract['lastTrade'] ?? []);
            foreach ([
                $contract['updated'] ?? null,
                $contract['last_updated'] ?? null,
                $quote['sip_timestamp'] ?? null,
                $quote['participant_timestamp'] ?? null,
                $quote['last_updated'] ?? null,
                $trade['sip_timestamp'] ?? null,
                $trade['participant_timestamp'] ?? null,
                $trade['last_updated'] ?? null,
            ] as $candidate) {
                $parsed = $this->providerTimestamp($candidate);
                if ($parsed?->isAfter($fallback->addSeconds(30))) {
                    continue;
                }
                if ($parsed?->isAfter($fallback)) {
                    $parsed = $fallback;
                }
                if ($parsed && ($latest === null || $parsed->isAfter($latest))) {
                    $latest = $parsed;
                }
            }
        }

        return $latest ?? $fallback;
    }

    private function providerTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $raw = (string) $value;
                $number = (int) $raw;
                $seconds = match (true) {
                    strlen($raw) >= 19 => intdiv($number, 1_000_000_000),
                    strlen($raw) >= 16 => intdiv($number, 1_000_000),
                    strlen($raw) >= 13 => intdiv($number, 1_000),
                    default => $number,
                };

                return CarbonImmutable::createFromTimestampUTC($seconds);
            }

            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function scheduledState(): ?CalculatorRefreshState
    {
        if ($this->schedulerGeneration === null || $this->schedulerClaimToken === null || $this->expiry !== null) {
            return null;
        }

        return app(CalculatorRefreshState::class);
    }
}
