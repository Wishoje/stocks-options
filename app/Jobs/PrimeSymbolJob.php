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

        if ($hasPrices && $hasChainsToday) return;

        $batch = Bus::batch([])->name("Prime {$s}")->allowFailures()->dispatch();

        $batch->add(new \App\Jobs\PricesBackfillJob([$s], 400));
        $batch->add(new \App\Jobs\PricesDailyJob([$s]));
        $batch->add(new \App\Jobs\FetchOptionChainDataJob([$s], 90));
        $batch->add(new \App\Jobs\ComputeVolMetricsJob([$s]));
        $batch->add(new \App\Jobs\Seasonality5DJob([$s], 15, 2));
        $batch->add(new \App\Jobs\ComputeExpiryPressureJob([$s], 3));
        $batch->add(new \App\Jobs\ComputePositioningJob([$s]));
        $batch->add(new \App\Jobs\ComputeUAJob([$s]));
    }
}
