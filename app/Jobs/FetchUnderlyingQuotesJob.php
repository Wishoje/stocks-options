<?php

namespace App\Jobs;

use App\Exceptions\ProviderConcurrencyUnavailable;
use App\Exceptions\ProviderDeferred;
use App\Exceptions\QuoteRefreshIncomplete;
use App\Models\UnderlyingQuote;
use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use App\Support\Market;
use App\Support\PolygonClient;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\ProviderRequestReplay;
use App\Support\QueueLanes;
use App\Support\QuoteRefreshPolicy;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FetchUnderlyingQuotesJob extends QueueJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    // Defaults also apply when an older serialized payload has no new fields.
    public bool $scheduled = true;

    public ?string $sessionDate = null;

    public array $workRunDeliveries = [];

    public ?string $phase = null;

    public function __construct(
        public array $symbols,
        bool $scheduled = true,
        ?string $sessionDate = null,
        array $workRunDeliveries = [],
        ?string $phase = null
    ) {
        $this->scheduled = $scheduled;
        $this->sessionDate = $sessionDate;
        $this->workRunDeliveries = $workRunDeliveries;
        $this->phase = $phase;
        // Separate, lightweight queue for prices
        $this->onQueue(QueueLanes::quotes());
    }

    public function handle(): void
    {
        if (QuoteRefreshPolicy::enabled() || $this->workRunDeliveries !== []) {
            $this->fetchManaged();

            return;
        }
        $limiter = app(ProviderConcurrencyLimiter::class);
        $limiter->withPriority(
            QueueLanes::providerPriority($this->queue),
            fn () => $this->fetchAndPersist(),
            1
        );
    }

    private function fetchManaged(): void
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($raw) => is_scalar($raw) ? Symbols::canon((string) $raw) : null,
            $this->symbols
        ))));
        sort($symbols, SORT_STRING);
        if (count($symbols) > 4) {
            throw new \InvalidArgumentException('A quote-refresh batch may contain at most four symbols.');
        }
        $attempt = max(1, $this->attempts());
        $coordinator = app(WorkRunCoordinator::class);
        $active = [];
        foreach ($symbols as $symbol) {
            if ($this->workRunDeliveries !== []) {
                $delivery = $this->workRunDeliveries[$symbol] ?? null;
                if (! is_array($delivery) || ! isset($delivery['run_id'], $delivery['delivery_token'])
                    || ! $coordinator->markStarted($delivery['run_id'], $delivery['delivery_token'], $attempt)) {
                    continue;
                }
                $active[$symbol] = $delivery;
            } else {
                $active[$symbol] = null;
            }
        }
        if ($active === []) {
            return;
        }
        $policy = app(QuoteRefreshPolicy::class);
        $window = $policy->window();
        $date = $this->sessionDate ?? ($this->scheduled
            ? $window['session_date'] : \App\Support\MarketSession::describe()['trade_date']);
        $phase = $this->phase ?? $window['phase'] ?? 'first_use';
        if ($this->scheduled && (! $window['eligible'] || $date !== $window['session_date'] || $phase !== $window['phase'])) {
            foreach ($active as $delivery) {
                if ($delivery) {
                    $coordinator->markCompleted($delivery['run_id'], $delivery['delivery_token'], $attempt);
                }
            }

            return;
        }
        $replay = app(ProviderRequestReplay::class);
        $scope = 'quote-batch:'.hash('sha256', json_encode([
            $date, $phase, array_map(static fn ($delivery) => $delivery['run_id'] ?? null, $active), array_keys($active),
        ], JSON_THROW_ON_ERROR));
        $replay->withExecution($scope, function () use ($active, $date, $phase, $window, $policy, $coordinator, $attempt, $replay): void {
            $unfinished = $active;
            $locks = [];
            try {
                foreach ($active as $symbol => $delivery) {
                    $lock = Cache::lock('quote-refresh:symbol:'.hash('sha256', $symbol), max(
                        120, (int) config('quote_refresh.symbol_lock_seconds', 120)
                    ));
                    if (! $lock->get()) {
                        $exception = new ProviderDeferred(
                            ProviderDeferred::QUOTE_PENDING,
                            CarbonImmutable::now('UTC')->addSeconds(15)
                        );
                        if (! $delivery) {
                            throw $exception;
                        }
                        $coordinator->deferProvider($delivery['run_id'], $delivery['delivery_token'], $attempt, $exception, 0);
                        unset($unfinished[$symbol]);

                        continue;
                    }
                    $locks[] = $lock;
                    $state = DB::table('quote_refresh_states')->where('symbol', $symbol)->where('session_date', $date)->first();
                    $stored = $this->scheduled ? null : UnderlyingQuote::query()->where('symbol', $symbol)->where('last_price', '>', 0)->first();
                    // Outside the polling window, explicit first-use work only
                    // needs a provider request when no usable stored quote exists.
                    $skip = $this->scheduled ? ! $policy->isDue($state, $window)
                        : ($stored !== null && (! $window['eligible'] || ! $policy->isDue($state, $window)));
                    if ($skip) {
                        if ($delivery) {
                            $coordinator->markCompleted($delivery['run_id'], $delivery['delivery_token'], $attempt);
                        }
                        unset($unfinished[$symbol]);
                    }
                }
                if ($unfinished === []) {
                    return;
                }
                $captured = CarbonImmutable::now('UTC');
                $quotes = app(ProviderConcurrencyLimiter::class)->withPriority(
                    $this->scheduled ? QueueLanes::PRIORITY_BACKGROUND : QueueLanes::PRIORITY_INTERACTIVE,
                    fn () => $replay->withoutReplay(fn () => app(PolygonClient::class)->underlyingQuotes(array_keys($unfinished))),
                    0
                );
                $received = CarbonImmutable::now('UTC');
                $missing = 0;
                $errors = 0;
                foreach ($unfinished as $symbol => $delivery) {
                    $quote = $quotes[$symbol] ?? null;
                    if (! $quote || empty($quote['last_price']) || $quote['last_price'] <= 0) {
                        if (! $delivery) {
                            $missing++;
                            unset($unfinished[$symbol]);

                            continue;
                        }
                        $coordinator->markFailed($delivery['run_id'], $delivery['delivery_token'], $attempt, 'provider_data', 'missing_quote');
                        unset($unfinished[$symbol]);

                        continue;
                    }
                    try {
                        $this->publishManaged($symbol, $date, $phase, $quote, $captured, $received, $delivery, $attempt, $window);
                        if ($delivery) {
                            $coordinator->markCompleted($delivery['run_id'], $delivery['delivery_token'], $attempt);
                        }
                    } catch (\Throwable $exception) {
                        if ($delivery) {
                            $coordinator->markFailed(
                                $delivery['run_id'], $delivery['delivery_token'], $attempt,
                                $this->errorCategory($exception), 'quote_refresh:'.$exception::class
                            );
                        } else {
                            $errors++;
                        }
                        Log::warning('FetchUnderlyingQuotesJob.error', [
                            'symbol' => $symbol, 'exception' => $exception::class,
                        ]);
                    }
                    unset($unfinished[$symbol]);
                }
                if ($errors > 0) {
                    throw new RuntimeException("Quote refresh failed for {$errors} symbol(s).");
                }
                if ($missing > 0) {
                    throw new QuoteRefreshIncomplete('Quote refresh incomplete: missing_quote');
                }
            } catch (ProviderDeferred $exception) {
                if ($this->workRunDeliveries === []) {
                    throw $exception;
                }
                foreach ($unfinished as $delivery) {
                    $coordinator->deferProvider(
                        $delivery['run_id'], $delivery['delivery_token'], $attempt,
                        $exception, $replay->executedRequestCount()
                    );
                }
            } catch (\Throwable $exception) {
                if ($this->workRunDeliveries === []) {
                    throw $exception;
                }
                foreach ($unfinished as $delivery) {
                    $coordinator->markFailed(
                        $delivery['run_id'], $delivery['delivery_token'], $attempt,
                        $this->errorCategory($exception), 'quote_refresh:'.$exception::class
                    );
                }
            } finally {
                foreach (array_reverse($locks) as $lock) {
                    $lock->release();
                }
            }
        });
    }

    /** Publish the original v2 fields and independent receipt metadata atomically. */
    private function publishManaged(
        string $symbol,
        string $date,
        string $phase,
        array $quote,
        CarbonImmutable $captured,
        CarbonImmutable $received,
        ?array $delivery,
        int $attempt,
        array $window
    ): bool {
        $asofUtc = $this->normalizeAsof($quote['asof'] ?? null);

        return DB::transaction(function () use ($symbol, $date, $phase, $quote, $captured, $received, $delivery, $attempt, $window, $asofUtc): bool {
            if ($delivery) {
                $identity = WorkRun::query()->whereKey($delivery['run_id'])->first(['slot_key']);
                if (! $identity) {
                    return false;
                }
                $slot = WorkRunSlot::query()->whereKey($identity->slot_key)->lockForUpdate()->first();
                if (! $slot || $slot->current_run_id !== $delivery['run_id']) {
                    return false;
                }
                $run = WorkRun::query()->lockForUpdate()->find($delivery['run_id']);
                if (! $run || $run->status !== WorkRun::STATUS_RUNNING || $run->attempt !== $attempt
                    || ! hash_equals((string) $run->delivery_token, (string) $delivery['delivery_token'])
                    || $run->symbol !== $symbol || ($run->parameters['session_date'] ?? null) !== $date
                    || ($run->parameters['phase'] ?? null) !== $phase) {
                    return false;
                }
            }
            if ($this->scheduled) {
                $currentWindow = app(QuoteRefreshPolicy::class)->window();
                if (! $currentWindow['eligible'] || $currentWindow['session_date'] !== $date || $currentWindow['phase'] !== $phase) {
                    return false;
                }
            }
            DB::table('quote_refresh_states')->insertOrIgnore(['symbol' => $symbol, 'session_date' => $date]);
            $stateQuery = DB::table('quote_refresh_states')->where('symbol', $symbol)->where('session_date', $date);
            $state = (clone $stateQuery)->lockForUpdate()->first();
            if ($state->captured_at && CarbonImmutable::parse($state->captured_at, 'UTC')->greaterThan($captured)) {
                return false;
            }
            $current = UnderlyingQuote::query()->where('symbol', $symbol)->lockForUpdate()->first();
            $currentUsesIngestionTime = str_ends_with((string) ($current?->source ?? ''), ':ingested-at');
            $preserve = ($asofUtc === null && $current?->asof && ! $currentUsesIngestionTime)
                || ($asofUtc !== null && $current?->asof && ! $currentUsesIngestionTime && $current->asof->gt($asofUtc));
            if (! $preserve) {
                $source = (string) ($quote['source'] ?? 'massive');
                $effectiveAsof = $asofUtc;
                if ($effectiveAsof === null) {
                    $effectiveAsof = CarbonImmutable::now('UTC');
                    $source .= ':ingested-at';
                }
                ($current ?? new UnderlyingQuote(['symbol' => $symbol]))->fill([
                    'source' => $source,
                    'last_price' => $quote['last_price'],
                    'prev_close' => $quote['prev_close'] ?? null,
                    'asof' => $effectiveAsof,
                ])->save();
            }
            $values = [
                'source_asof' => $asofUtc?->format('Y-m-d H:i:s.u'),
                'captured_at' => $captured->format('Y-m-d H:i:s.u'),
                'received_at' => $received->format('Y-m-d H:i:s.u'),
                'ingestion_completed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'work_run_id' => $delivery['run_id'] ?? null,
            ];
            if ($phase === 'final' && $window['final_starts_at']
                && $captured->greaterThanOrEqualTo(CarbonImmutable::parse($window['final_starts_at']))
                && $received->greaterThanOrEqualTo(CarbonImmutable::parse($window['final_starts_at']))) {
                $values['final_captured_at'] = $values['captured_at'];
                $values['final_received_at'] = $values['received_at'];
                $values['final_completed_at'] = $values['ingestion_completed_at'];
            }
            $stateQuery->update($values);

            return true;
        }, 3);
    }

    private function fetchAndPersist(): void
    {
        // $nowEt = now('America/New_York');
        // if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        //     Log::info('FetchUnderlyingQuotesJob.marketClosed', ['ts' => $nowEt->toDateTimeString()]);
        //     return;
        // }

        /** @var PolygonClient $client */
        $client = app(PolygonClient::class);

        $failed = 0;
        $errored = 0;

        foreach ($this->symbols as $raw) {
            $symbol = Symbols::canon($raw);
            if (! $symbol) {
                continue;
            }

            try {
                // Log::debug('FetchUnderlyingQuotesJob.symbol', ['symbol' => $symbol]);

                $quote = $client->underlyingQuote($symbol);

                if (! $quote || empty($quote['last_price']) || $quote['last_price'] <= 0) {
                    Log::warning('FetchUnderlyingQuotesJob.noQuote', ['symbol' => $symbol]);
                    $failed++;

                    continue;
                }

                $asofRaw = $quote['asof'] ?? null;
                $asofUtc = $this->normalizeAsof($asofRaw);

                DB::transaction(function () use ($symbol, $quote, $asofUtc): void {
                    $current = UnderlyingQuote::query()
                        ->where('symbol', $symbol)
                        ->lockForUpdate()
                        ->first();
                    $currentUsesIngestionTime = str_ends_with(
                        (string) ($current?->source ?? ''),
                        ':ingested-at'
                    );

                    // A provider response without source time cannot supersede
                    // an already timestamped quote. Keep the last verifiable row.
                    if ($asofUtc === null && $current?->asof && ! $currentUsesIngestionTime) {
                        return;
                    }

                    if (
                        $asofUtc !== null
                        && $current?->asof
                        && ! $currentUsesIngestionTime
                        && $current->asof->gt($asofUtc)
                    ) {
                        return;
                    }

                    $source = (string) ($quote['source'] ?? 'massive');
                    $effectiveAsof = $asofUtc;
                    if ($effectiveAsof === null) {
                        $effectiveAsof = CarbonImmutable::now('UTC');
                        $source .= ':ingested-at';
                    }

                    ($current ?? new UnderlyingQuote(['symbol' => $symbol]))
                        ->fill([
                            'source' => $source,
                            'last_price' => $quote['last_price'],
                            'prev_close' => $quote['prev_close'] ?? null,
                            'asof' => $effectiveAsof,
                        ])
                        ->save();
                }, 3);
            } catch (\App\Exceptions\ProviderDeferred $exception) {
                throw $exception;
            } catch (ProviderConcurrencyUnavailable $exception) {
                // Capacity pressure must retry the job immediately. Continuing
                // through a batch could spend the whole 90-second job timeout
                // waiting once per symbol without making a provider request.
                throw $exception;
            } catch (\Throwable $e) {
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'unauthorized')
                    || str_contains($message, 'rate_limited')
                    || str_contains($message, 'api key missing')) {
                    // Preserve safe provider categories for the durable phase
                    // coordinator instead of collapsing permanent auth and
                    // transient throttling into a generic quote failure.
                    throw $e;
                }
                $errored++;
                Log::warning('FetchUnderlyingQuotesJob.error', [
                    'symbol' => $symbol,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($errored > 0) {
            throw new RuntimeException("Quote refresh failed for {$errored} symbol(s).");
        }

        if ($failed > 0) {
            throw new QuoteRefreshIncomplete("Quote refresh incomplete for {$failed} symbol(s).");
        }
    }

    /**
     * Normalize various asof formats to a UTC Carbon instance.
     *
     * Massive v2 uses epoch nanoseconds (e.g. 1765328400000000000).
     */
    private function normalizeAsof(mixed $asofRaw): ?CarbonImmutable
    {
        if ($asofRaw === null || $asofRaw === '') {
            return null;
        }

        // Numeric epoch (seconds/ms/us/ns)
        if (is_int($asofRaw) || (is_string($asofRaw) && ctype_digit($asofRaw))) {
            $num = (int) $asofRaw;
            $len = strlen((string) $num);

            // 19 digits: ns, 16: µs, 13: ms, <=10: seconds
            if ($len >= 19) {
                $seconds = intdiv($num, 1_000_000_000); // ns -> s
            } elseif ($len >= 16) {
                $seconds = intdiv($num, 1_000_000);     // µs -> s
            } elseif ($len >= 13) {
                $seconds = intdiv($num, 1_000);         // ms -> s
            } else {
                $seconds = $num;                        // seconds
            }

            return CarbonImmutable::createFromTimestampUTC($seconds);
        }

        // Fallback: treat as string datetime
        return CarbonImmutable::parse((string) $asofRaw, 'UTC');
    }
}
