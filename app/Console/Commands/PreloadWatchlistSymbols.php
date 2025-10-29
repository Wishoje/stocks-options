<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use App\Jobs\{
    PricesBackfillJob, PricesDailyJob, FetchOptionChainDataJob,
    ComputeVolMetricsJob, Seasonality5DJob, ComputeExpiryPressureJob, ComputeUAJob
};

class PreloadWatchlistSymbols extends Command
{
    protected $signature = 'watchlist:preload';
    protected $description = 'Preload EOD datasets for all unique watchlisted symbols.';

    public function handle(): int
    {
        $symbols = DB::table('watchlists')->select('symbol')->distinct()->pluck('symbol')->all();
        if (!$symbols) { $this->info('No symbols to preload.'); return self::SUCCESS; }

        $batch = Bus::batch([])->name('Watchlist EOD Preload')->allowFailures()->dispatch();

        $batch->add(new PricesBackfillJob($symbols, 400));
        $batch->add(new PricesDailyJob($symbols));
        $batch->add(new FetchOptionChainDataJob($symbols, 90)); // always 90 days
        $batch->add(new ComputeVolMetricsJob($symbols));
        $batch->add(new Seasonality5DJob($symbols, 15, 2));
        $batch->add(new ComputeExpiryPressureJob($symbols, 3));
        $batch->add(new ComputeUAJob($symbols));

        $this->info("Queued preload batch: {$batch->id}");
        return self::SUCCESS;
    }
}
