<?php

namespace App\Jobs;

use App\Support\EodSnapshotSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class PrimeSymbolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'prime';

    public function __construct(public string $symbol)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $s = $this->symbol;
        $selector = app(EodSnapshotSelector::class);

        $completedSessionDate = $selector->completedSessionDate(now('America/New_York'));
        $tradeDate = $completedSessionDate;
        $anchorDate = $selector->resolvedAnchorDate();

        $hasPrices = DB::table('prices_daily')
            ->where('symbol',$s)->where('trade_date',$completedSessionDate)->exists();

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
        if (!$hasPrices) {
            $jobs[] = new \App\Jobs\PricesDailyJob([$s]);
        }
        if (!$hasChainsForTradeDate) {
            $jobs[] = new \App\Jobs\FetchOptionChainDataJob([$s], 90);
        }
        if (!$hasVolMetricsForAnchorDate) {
            $jobs[] = new \App\Jobs\ComputeVolMetricsJob([$s]);
        }
        if (!$hasSeasonalityForTradeDate) {
            $jobs[] = new \App\Jobs\Seasonality5DJob([$s], 15, 2);
        }
        if (!$hasExpiryPressureForTradeDate) {
            $jobs[] = new \App\Jobs\ComputeExpiryPressureJob([$s], 3, $tradeDate);
        }
        if (!$hasPositioningForTradeDate) {
            $jobs[] = new \App\Jobs\ComputePositioningJob([$s], $tradeDate);
        }
        if (!$hasUaForAnchorDate) {
            $jobs[] = new \App\Jobs\ComputeUAJob([$s]);
        }

        if ($jobs === []) {
            return;
        }

        foreach ($jobs as $job) {
            $job->onQueue(self::QUEUE);
        }

        Bus::chain($jobs)->onQueue(self::QUEUE)->dispatch();
    }
}
