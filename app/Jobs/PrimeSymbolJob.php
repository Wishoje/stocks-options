<?php

namespace App\Jobs;

use App\Support\EodSnapshotSelector;
use App\Support\QueueLanes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class PrimeSymbolJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'prime';

    public int $timeout = 60;

    public function __construct(public string $symbol)
    {
        $this->onQueue(QueueLanes::enrichment());
    }

    public function handle(): void
    {
        $jobs = $this->plannedJobs();
        if ($jobs === []) {
            return;
        }

        Bus::chain($jobs)->onQueue(QueueLanes::enrichment())->dispatch();
    }

    /**
     * Build the enrichment jobs from the data visible at execution time.
     * Bootstrap chains use this after their base data jobs have completed so
     * the durable run can append only the work that is still missing.
     *
     * @return array<int, object>
     */
    public function plannedJobs(): array
    {
        $s = $this->symbol;
        $selector = app(EodSnapshotSelector::class);

        $completedSessionDate = $selector->completedSessionDate(now('America/New_York'));
        $tradeDate = $completedSessionDate;
        $anchorDate = $selector->resolvedAnchorDate();

        $hasPrices = DB::table('prices_daily')
            ->where('symbol', $s)->where('trade_date', $completedSessionDate)->exists();

        $priceRows = (int) DB::table('prices_daily')
            ->where('symbol', $s)
            ->count();

        $hasChainsForTradeDate = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $s)
            ->whereDate('o.data_date', $tradeDate)
            ->exists();

        $hasSeasonalityForTradeDate = DB::table('seasonality_5d')
            ->where('symbol', $s)
            ->whereDate('data_date', $tradeDate)
            ->exists();

        $hasExpiryPressureForTradeDate = DB::table('expiry_pressure')
            ->where('symbol', $s)
            ->whereDate('data_date', $tradeDate)
            ->exists();

        $hasPositioningForTradeDate = DB::table('dex_by_expiry')
            ->where('symbol', $s)
            ->whereDate('data_date', $tradeDate)
            ->exists();

        $hasVolMetricsForAnchorDate = DB::table('iv_term')
            ->where('symbol', $s)
            ->whereDate('data_date', $anchorDate)
            ->exists();

        $hasUaForAnchorDate = DB::table('unusual_activity')
            ->where('symbol', $s)
            ->whereDate('data_date', $anchorDate)
            ->exists();

        $jobs = [];

        if ($priceRows < 30) {
            $jobs[] = new \App\Jobs\PricesBackfillJob([$s], 400);
        }
        if (! $hasPrices) {
            $jobs[] = new \App\Jobs\PricesDailyJob([$s]);
        }
        if (! $hasChainsForTradeDate) {
            $jobs[] = new \App\Jobs\FetchOptionChainDataJob([$s], 90, null, 110);
        }
        if (! $hasVolMetricsForAnchorDate) {
            $jobs[] = new \App\Jobs\ComputeVolMetricsJob([$s]);
        }
        if (! $hasSeasonalityForTradeDate) {
            $jobs[] = new \App\Jobs\Seasonality5DJob([$s], 15, 2);
        }
        if (! $hasExpiryPressureForTradeDate) {
            $jobs[] = new \App\Jobs\ComputeExpiryPressureJob([$s], 3, $tradeDate);
        }
        if (! $hasPositioningForTradeDate) {
            $jobs[] = new \App\Jobs\ComputePositioningJob([$s], $tradeDate);
        }
        if (! $hasUaForAnchorDate) {
            $jobs[] = new \App\Jobs\ComputeUAJob([$s]);
        }

        $queue = QueueLanes::enrichment();
        foreach ($jobs as $job) {
            $job->withJobTimeout(min($job->timeout, 110));
            $job->onQueue($queue);
        }

        return $jobs;
    }
}
