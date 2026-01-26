<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class PrimeSymbolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $symbol) {}

    public function handle(): void
    {
        $s = $this->symbol;

        $today = now('America/New_York')->isWeekend()
            ? now('America/New_York')->previousWeekday()->toDateString()
            : now('America/New_York')->toDateString();

        $hasPrices = DB::table('prices_daily')
            ->where('symbol',$s)->where('trade_date',$today)->exists();

        $hasAnyExp = DB::table('option_expirations')->where('symbol',$s)->exists();

        $hasChainsToday = $hasAnyExp && DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $s)
            ->whereDate('o.data_date', $today)
            ->exists();

        $hasSeasonalityToday = DB::table('seasonality_5d')
            ->where('symbol', $s)
            ->whereDate('data_date', $today)
            ->exists();

        $hasExpiryPressureToday = DB::table('expiry_pressure')
            ->where('symbol', $s)
            ->whereDate('data_date', $today)
            ->exists();

        // Only short-circuit if all downstream artifacts already exist
        if ($hasPrices && $hasChainsToday && $hasSeasonalityToday && $hasExpiryPressureToday) return;

        // Run sequentially to guarantee upstream data is ready before dependent jobs execute
        Bus::chain([
            new \App\Jobs\PricesBackfillJob([$s], 400),
            new \App\Jobs\PricesDailyJob([$s]),
            new \App\Jobs\FetchOptionChainDataJob([$s], 90),
            new \App\Jobs\ComputeVolMetricsJob([$s]),
            new \App\Jobs\Seasonality5DJob([$s], 15, 2),
            new \App\Jobs\ComputeExpiryPressureJob([$s], 3),
            new \App\Jobs\ComputePositioningJob([$s]),
            new \App\Jobs\ComputeUAJob([$s]),
        ])->onQueue('default')->dispatch();
    }
}
