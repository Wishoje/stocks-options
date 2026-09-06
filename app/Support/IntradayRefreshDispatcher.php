<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class IntradayRefreshDispatcher
{
    public function __construct(
        private readonly WorkRunCoordinator $runs,
        private readonly WorkRunDispatcher $dispatcher
    ) {}

    public function enabled(): bool
    {
        if (! config('intraday_dispatch.singleton_enabled', false)) {
            return false;
        }
        if (! config('intraday_dispatch.rollout_validated', false)) {
            throw new LogicException('Intraday singleton dispatch requires INTRADAY_SINGLETON_ROLLOUT_VALIDATED=true.');
        }
        if (! QueueLanes::isolated()) {
            throw new LogicException('Intraday singleton dispatch requires QUEUE_LANES_ISOLATED=true.');
        }

        $connection = (string) config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') !== 'redis') {
            throw new LogicException('Intraday singleton dispatch requires a validated Redis queue connection.');
        }
        if (config('queue.connections.'.$connection.'.connection') !== 'queue') {
            throw new LogicException('Intraday singleton dispatch requires the dedicated queue Redis transport.');
        }
        $lanes = array_map(
            static fn (string $key): string => trim((string) config('queue_lanes.queues.'.$key, $key)),
            ['intraday', 'intraday_heavy', 'intraday_interactive']
        );
        if (in_array('', $lanes, true) || count(array_unique($lanes)) !== 3) {
            throw new LogicException('Intraday singleton dispatch requires distinct normal, heavy, and interactive queues.');
        }

        return true;
    }

    /** Read one canonical symbol per watchlist entry before materializing it. @return list<string> */
    public function watchlistSymbols(): array
    {
        return DB::table('watchlists')
            ->whereNotNull('symbol')
            ->whereRaw("TRIM(symbol) <> ''")
            ->selectRaw('UPPER(TRIM(symbol)) as symbol')
            ->distinct()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->all();
    }

    /**
     * Persist each intent before enqueueing it. A Redis failure leaves the
     * intent dispatchable by reconciliation and does not prevent later symbols.
     *
     * @param iterable<string> $symbols
     * @return array{requested:int,created:int,dispatched:int,reused:int,deferred:int,failed:int}
     */
    public function dispatch(iterable $symbols, ?string $tradeDate = null, bool $interactive = false): array
    {
        if (! $this->enabled()) {
            throw new LogicException('Intraday singleton dispatch is disabled.');
        }

        $canonical = [];
        foreach ($symbols as $rawSymbol) {
            $symbol = Symbols::canon($rawSymbol);
            if ($symbol === '') {
                continue;
            }
            if (! Symbols::isValid($symbol) || strlen($symbol) > 16) {
                throw new InvalidArgumentException('Intraday dispatch contains an invalid symbol.');
            }
            $canonical[$symbol] = true;
        }
        $tradeDate = $this->tradeDate($tradeDate);
        $result = ['requested' => count($canonical), 'created' => 0, 'dispatched' => 0, 'reused' => 0, 'deferred' => 0, 'failed' => 0];

        foreach (array_keys($canonical) as $symbol) {
            $run = null;
            try {
                $claim = $this->runs->claim(
                    'intraday_refresh',
                    $symbol,
                    ['trade_date' => $tradeDate],
                    QueueLanes::intraday($symbol, $interactive),
                    deferWhenRateLimited: true
                );
                $run = $claim['run'];
                $result[$claim['created'] ? 'created' : 'reused']++;
                if ($claim['deferred']) {
                    $result['deferred']++;
                }
                if ($this->dispatcher->dispatch($run)) {
                    $result['dispatched']++;
                }
            } catch (Throwable $exception) {
                $result['failed']++;
                Log::channel('scheduler')->warning('intraday.singleton_dispatch_failed', [
                    'symbol' => $symbol,
                    'run_id' => $run?->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $result;
    }

    private function tradeDate(?string $value): string
    {
        if ($value !== null) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            } catch (Throwable) {
                $date = null;
            }
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException('Intraday trade date must use YYYY-MM-DD.');
            }

            return $value;
        }

        // Preserve the existing job's session selection, including pre-open
        // and weekend dispatches. Resolve once so every intent owns that date.
        $ny = now('America/New_York');
        if ($ny->isWeekend() || (int) $ny->format('Hi') < 930) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }
}
