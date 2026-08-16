<?php

namespace App\Support;

use App\Jobs\FetchCalculatorChainJob;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CalculatorPrimeScheduler
{
    public function __construct(private readonly CalculatorRefreshState $states) {}

    /**
     * @return array{
     *     status: string,
     *     source: string|null,
     *     generation: string|null,
     *     configured_count: int,
     *     eligible_count: int,
     *     dispatched: string[],
     *     coalesced: string[],
     *     dispatch_failures: array<string, string>
     * }
     */
    public function dispatchDue(?CarbonInterface $at = null, ?string $generation = null): array
    {
        $at = $at ? CarbonImmutable::parse($at->toIso8601String()) : now('America/New_York')->toImmutable();

        if ($at->isWeekend() || ! Market::isRthOpen($at->toMutable())) {
            return $this->result('market_closed');
        }

        $configured = DB::table('watchlists')
            ->pluck('symbol')
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $source = $configured === [] ? 'fallback' : 'watchlist';
        $symbols = $configured === [] ? $this->fallbackSymbols() : $configured;
        $states = $this->states->many($symbols);
        $cutoff = $at->subMinutes(max(1, (int) config('calculator.scheduler.fresh_minutes', 10)));
        $sortKeys = [];
        foreach ($symbols as $symbol) {
            $sortKeys[$symbol] = $this->sortKey($symbol, $states[$symbol] ?? null);
        }

        $eligible = collect($symbols)
            ->reject(fn (string $symbol): bool => $this->states->isActive($states[$symbol] ?? null, $at))
            ->reject(fn (string $symbol): bool => $this->states->isFresh($states[$symbol] ?? null, $cutoff))
            ->reject(fn (string $symbol): bool => $this->states->isCoolingDown($states[$symbol] ?? null, $at))
            ->sort(fn (string $left, string $right): int => $sortKeys[$left] <=> $sortKeys[$right])
            ->values()
            ->all();

        $generation ??= $this->generation($at);
        $maxSymbols = max(1, (int) config('calculator.scheduler.max_symbols', 75));
        $dispatched = [];
        $coalesced = [];
        $dispatchFailures = [];
        $claimed = 0;

        foreach ($eligible as $symbol) {
            if ($claimed >= $maxSymbols) {
                break;
            }

            $queue = QueueLanes::calculator($symbol);
            $claimToken = $this->states->claim($symbol, $generation, $queue, $at, $cutoff);
            if ($claimToken === null) {
                $coalesced[] = $symbol;

                continue;
            }
            $claimed++;

            try {
                $job = (new FetchCalculatorChainJob(
                    $symbol,
                    schedulerGeneration: $generation,
                    schedulerClaimToken: $claimToken
                ))->onQueue($queue);

                Bus::dispatch($job);
                $dispatched[] = $symbol;
            } catch (Throwable $exception) {
                $this->states->markFailed(
                    $symbol,
                    $generation,
                    $claimToken,
                    'dispatch_failed:'.$exception::class,
                    $at
                );
                $dispatchFailures[$symbol] = $exception::class;

                Log::channel('scheduler')->error('calculator.scheduler.dispatch_failed', [
                    'symbol' => $symbol,
                    'generation' => $generation,
                    'queue' => $queue,
                    'exception' => $exception::class,
                ]);
            }
        }

        return [
            'status' => $dispatchFailures === [] ? 'ok' : 'dispatch_failed',
            'source' => $source,
            'generation' => $generation,
            'configured_count' => count($configured),
            'eligible_count' => count($eligible),
            'dispatched' => $dispatched,
            'coalesced' => $coalesced,
            'dispatch_failures' => $dispatchFailures,
        ];
    }

    /** @return array{int, int, int, int, string} */
    private function sortKey(string $symbol, ?array $state): array
    {
        $lastSuccess = $this->states->lastSuccessAt($state);
        $lastDispatched = $this->states->lastDispatchedAt($state);
        $lastServed = match (true) {
            $lastSuccess === null => $lastDispatched,
            $lastDispatched === null => $lastSuccess,
            $lastSuccess->greaterThan($lastDispatched) => $lastSuccess,
            default => $lastDispatched,
        };

        return [
            $lastServed === null ? 0 : 1,
            $lastServed?->getTimestamp() ?? 0,
            $lastSuccess === null ? 0 : 1,
            $lastSuccess?->getTimestamp() ?? 0,
            $symbol,
        ];
    }

    /** @return string[] */
    private function fallbackSymbols(): array
    {
        return collect(config('calculator.scheduler.fallback_symbols', ['SPY', 'QQQ', 'IWM']))
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function generation(CarbonInterface $at): string
    {
        $interval = max(1, (int) config('calculator.scheduler.interval_minutes', 5));
        $bucket = CarbonImmutable::parse($at->toIso8601String())
            ->setMinute(intdiv($at->minute, $interval) * $interval)
            ->setSecond(0)
            ->setMicrosecond(0)
            ->utc()
            ->format('Ymd\THi\Z');

        return 'scheduled:'.$bucket;
    }

    /**
     * @return array{
     *     status: string,
     *     source: null,
     *     generation: null,
     *     configured_count: 0,
     *     eligible_count: 0,
     *     dispatched: array{},
     *     coalesced: array{},
     *     dispatch_failures: array{}
     * }
     */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'source' => null,
            'generation' => null,
            'configured_count' => 0,
            'eligible_count' => 0,
            'dispatched' => [],
            'coalesced' => [],
            'dispatch_failures' => [],
        ];
    }
}
