<?php

namespace App\Jobs;

use App\Support\EodCacheVersion;
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

    /** @var string[] */
    public array $completedCacheDomains = [];

    public function __construct(public string $symbol, array $completedCacheDomains = [])
    {
        $this->completedCacheDomains = $completedCacheDomains;
        $this->onQueue(QueueLanes::enrichment());
    }

    public function handle(): void
    {
        $jobs = $this->plannedJobs();
        $domains = array_values(array_unique(array_merge(
            $this->completedCacheDomains,
            $this->cacheDomainsForJobs($jobs)
        )));
        if ($domains !== []) {
            $jobs[] = new PublishEodCacheVersionJob([$this->symbol], $domains);
        }
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
    public function plannedJobs(?string $frozenSessionDate = null): array
    {
        $s = $this->symbol;
        $selector = app(EodSnapshotSelector::class);

        $completedSessionDate = $frozenSessionDate
            ? substr($frozenSessionDate, 0, 10)
            : $selector->completedSessionDate(now('America/New_York'));
        $tradeDate = $completedSessionDate;
        $anchorDate = $frozenSessionDate
            ? $completedSessionDate
            : $selector->resolvedAnchorDate();

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

        // A phased bootstrap promises the complete history window frozen for
        // that manifest. The backfill is idempotent, so always schedule it for
        // frozen-session planning instead of treating 30 partial rows as full.
        if ($frozenSessionDate !== null || $priceRows < 30) {
            $jobs[] = $frozenSessionDate !== null
                ? new \App\Jobs\PricesBackfillJob([$s], 400, $completedSessionDate)
                : new \App\Jobs\PricesBackfillJob([$s], 400);
        }
        if (! $hasPrices) {
            $jobs[] = $frozenSessionDate !== null
                ? new \App\Jobs\PricesDailyJob([$s], $completedSessionDate)
                : new \App\Jobs\PricesDailyJob([$s]);
        }
        if (! $hasChainsForTradeDate) {
            $jobs[] = new \App\Jobs\FetchOptionChainDataJob(
                [$s],
                90,
                $frozenSessionDate !== null ? $tradeDate : null,
                110
            );
        }
        // Frozen bootstrap planning follows the completed fill phase. Existing
        // derived rows may have been calculated from an earlier partial chain,
        // so recompute option-dependent domains against the frozen full scope.
        if ($frozenSessionDate !== null || ! $hasVolMetricsForAnchorDate) {
            $jobs[] = $frozenSessionDate !== null
                ? new \App\Jobs\ComputeVolMetricsJob([$s], $anchorDate)
                : new \App\Jobs\ComputeVolMetricsJob([$s]);
        }
        if ($frozenSessionDate !== null || ! $hasSeasonalityForTradeDate) {
            $jobs[] = $frozenSessionDate !== null
                ? new \App\Jobs\Seasonality5DJob([$s], 15, 2, $completedSessionDate)
                : new \App\Jobs\Seasonality5DJob([$s], 15, 2);
        }
        if ($frozenSessionDate !== null || ! $hasExpiryPressureForTradeDate) {
            $jobs[] = new \App\Jobs\ComputeExpiryPressureJob([$s], 3, $tradeDate);
        }
        if ($frozenSessionDate !== null || ! $hasPositioningForTradeDate) {
            $jobs[] = new \App\Jobs\ComputePositioningJob([$s], $tradeDate);
        }
        if ($frozenSessionDate !== null || ! $hasUaForAnchorDate) {
            $jobs[] = $frozenSessionDate !== null
                ? new \App\Jobs\ComputeUAJob([$s], anchorDate: $anchorDate)
                : new \App\Jobs\ComputeUAJob([$s]);
        }

        $queue = QueueLanes::enrichment();
        foreach ($jobs as $job) {
            $job->withJobTimeout(min($job->timeout, 110));
            $job->onQueue($queue);
        }

        return $jobs;
    }

    /**
     * @param  array<int, object>  $jobs
     * @return string[]
     */
    public function cacheDomainsForJobs(array $jobs): array
    {
        $domains = [];
        foreach ($jobs as $job) {
            if ($job instanceof FetchOptionChainDataJob || $job instanceof ComputePositioningJob) {
                $domains[] = EodCacheVersion::DOMAIN_GEX;
            }
            if ($job instanceof ComputeVolMetricsJob) {
                $domains[] = EodCacheVersion::DOMAIN_VOLATILITY;
            }
            if ($job instanceof ComputeExpiryPressureJob) {
                $domains[] = EodCacheVersion::DOMAIN_EXPIRY_PRESSURE;
            }
            if ($job instanceof ComputeUAJob) {
                $domains[] = EodCacheVersion::DOMAIN_ACTIVITY;
            }
        }

        $domains = array_values(array_unique($domains));
        sort($domains);

        return $domains;
    }
}
