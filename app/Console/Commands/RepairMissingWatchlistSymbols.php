<?php

namespace App\Console\Commands;

use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\Seasonality5DJob;
use App\Support\EodHealth;
use App\Support\Symbols;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RepairMissingWatchlistSymbols extends Command
{
    protected $signature = 'watchlist:repair-missing
                            {--date= : Target data_date (YYYY-MM-DD), defaults to current NY trading date}
                            {--symbols= : Optional comma-separated symbol override}
                            {--chunk=10 : Symbols per queued chunk}
                            {--days=90 : Expiration lookahead for option chain fetch}
                            {--profile=broad : Incomplete-data profile: broad|core}
                            {--check-incomplete : Also treat low-quality symbol snapshots as repair candidates}
                            {--min-expirations= : Override minimum distinct expirations for a symbol on target date}
                            {--min-strikes= : Override minimum distinct strikes for a symbol on target date}
                            {--min-strike-ratio= : Override min target/previous strike-count ratio before marking incomplete}
                            {--with-backfill : Include PricesBackfillJob before daily/chain jobs}
                            {--dry-run : Report missing/incomplete symbols only, do not queue jobs}';

    protected $description = 'Find watchlist symbols with missing/incomplete EOD option_chain_data and queue repair jobs.';

    public function handle(): int
    {
        $targetDate = $this->resolveTargetDate((string) $this->option('date'));
        if ($targetDate === null) {
            $this->error('Invalid --date. Use YYYY-MM-DD.');
            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $days = max(1, (int) $this->option('days'));
        $profile = strtolower(trim((string) $this->option('profile')));
        $checkIncomplete = (bool) $this->option('check-incomplete');
        $thresholds = EodHealth::resolveThresholds(
            $profile,
            $this->option('min-expirations'),
            $this->option('min-strikes'),
            $this->option('min-strike-ratio')
        );
        $profile = $thresholds['profile'];
        $minExpirations = $thresholds['min_expirations'];
        $minStrikes = $thresholds['min_strikes'];
        $minStrikeRatio = $thresholds['min_strike_ratio'];
        $withBackfill = (bool) $this->option('with-backfill');
        $dryRun = (bool) $this->option('dry-run');

        $symbols = $this->resolveSymbolUniverse((string) $this->option('symbols'));
        if (empty($symbols)) {
            $this->info('No symbols to evaluate.');
            return self::SUCCESS;
        }

        $targetStats = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereDate('o.data_date', $targetDate)
            ->whereIn('e.symbol', $symbols)
            ->select(
                'e.symbol',
                DB::raw('COUNT(*) as rows_n'),
                DB::raw('COUNT(DISTINCT o.option_type) as option_types_n'),
                DB::raw('COUNT(DISTINCT o.expiration_id) as expirations_n'),
                DB::raw('COUNT(DISTINCT o.strike) as strikes_n'),
            )
            ->groupBy('e.symbol')
            ->get()
            ->mapWithKeys(function ($row) {
                $sym = Symbols::canon((string) $row->symbol);
                if ($sym === null || $sym === '') {
                    return [];
                }
                return [$sym => [
                    'rows_n' => (int) $row->rows_n,
                    'option_types_n' => (int) $row->option_types_n,
                    'expirations_n' => (int) $row->expirations_n,
                    'strikes_n' => (int) $row->strikes_n,
                ]];
            });

        $covered = $targetStats->keys()->values();

        $missing = collect($symbols)
            ->diff($covered)
            ->values();

        $incompleteReasons = [];
        if ($checkIncomplete) {
            $prevDates = DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->whereIn('e.symbol', $symbols)
                ->whereDate('o.data_date', '<', $targetDate)
                ->select('e.symbol', DB::raw('MAX(o.data_date) as prev_date'))
                ->groupBy('e.symbol');

            $prevStats = DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->joinSub($prevDates, 'p', function ($join) {
                    $join->on('e.symbol', '=', 'p.symbol')
                        ->on('o.data_date', '=', 'p.prev_date');
                })
                ->whereIn('e.symbol', $symbols)
                ->select(
                    'e.symbol',
                    DB::raw('COUNT(DISTINCT o.strike) as prev_strikes_n')
                )
                ->groupBy('e.symbol')
                ->get()
                ->mapWithKeys(function ($row) {
                    $sym = Symbols::canon((string) $row->symbol);
                    if ($sym === null || $sym === '') {
                        return [];
                    }
                    return [$sym => (int) $row->prev_strikes_n];
                });

            foreach ($targetStats as $sym => $stats) {
                $prevStrikes = (int) ($prevStats[$sym] ?? 0);
                $reasons = EodHealth::incompleteReasons($stats, $prevStrikes, $thresholds);

                if (!empty($reasons)) {
                    $incompleteReasons[$sym] = $reasons;
                }
            }
        }

        $incomplete = collect(array_keys($incompleteReasons))->values();
        $toRepair = $missing->merge($incomplete)->unique()->values();

        $this->info(sprintf(
            'Target date=%s, symbols=%d, covered=%d, missing=%d, incomplete=%d, repair_candidates=%d',
            $targetDate,
            count($symbols),
            $covered->count(),
            $missing->count(),
            $incomplete->count(),
            $toRepair->count(),
        ));
        if ($checkIncomplete) {
            $this->line(sprintf(
                'Incomplete thresholds (profile=%s): min_expirations=%d, min_strikes=%d, min_strike_ratio=%.2f',
                $profile,
                $minExpirations,
                $minStrikes,
                $minStrikeRatio
            ));
        }

        if ($missing->isNotEmpty()) {
            $this->line('Missing symbols: '.implode(', ', $missing->all()));
        }
        if ($incomplete->isNotEmpty()) {
            $this->line('Incomplete symbols: '.implode(', ', $incomplete->all()));
            foreach ($incomplete as $sym) {
                $this->line(" - {$sym}: ".implode(', ', $incompleteReasons[$sym] ?? []));
            }
        }

        if ($toRepair->isEmpty() || $dryRun) {
            return self::SUCCESS;
        }

        $batch = Bus::batch([])
            ->name("Watchlist EOD Missing Repair {$targetDate}")
            ->dispatch();

        foreach (array_chunk($toRepair->all(), $chunkSize) as $group) {
            if ($withBackfill) {
                $first = new PricesBackfillJob($group, 400);
                $chain = [
                    new PricesDailyJob($group),
                    new FetchOptionChainDataJob($group, $days),
                    new ComputeVolMetricsJob($group),
                    new Seasonality5DJob($group, 15, 2),
                    new ComputeExpiryPressureJob($group, 3),
                    new ComputePositioningJob($group),
                    new ComputeUAJob($group),
                ];
            } else {
                $first = new PricesDailyJob($group);
                $chain = [
                    new FetchOptionChainDataJob($group, $days),
                    new ComputeVolMetricsJob($group),
                    new Seasonality5DJob($group, 15, 2),
                    new ComputeExpiryPressureJob($group, 3),
                    new ComputePositioningJob($group),
                    new ComputeUAJob($group),
                ];
            }

            $first->chain($chain);
            $batch->add($first);
        }

        $this->info("Queued repair batch: {$batch->id}");

        return self::SUCCESS;
    }

    protected function resolveSymbolUniverse(string $override): array
    {
        if (trim($override) !== '') {
            return collect(explode(',', $override))
                ->map(fn ($s) => Symbols::canon((string) $s))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return DB::table('watchlists')
            ->pluck('symbol')
            ->map(fn ($s) => Symbols::canon((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveTargetDate(string $dateOpt): ?string
    {
        $raw = trim($dateOpt);
        if ($raw === '') {
            return $this->tradingDate(now('America/New_York'));
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw, 'America/New_York')->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }
}
