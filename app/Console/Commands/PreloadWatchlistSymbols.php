<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use App\Jobs\{
    PricesBackfillJob, PricesDailyJob, FetchOptionChainDataJob,
    ComputeVolMetricsJob, Seasonality5DJob, ComputeExpiryPressureJob, ComputeUAJob,
    ComputePositioningJob,
};

class PreloadWatchlistSymbols extends Command
{
    protected $signature = 'watchlist:preload';
    protected $description = 'Preload EOD datasets for all unique watchlisted symbols.';

    public function handle(): int
    {
        $symbols = DB::table('watchlists')->select('symbol')->distinct()->pluck('symbol')->all();
        if (!$symbols) { $this->info('No symbols to preload.'); return self::SUCCESS; }

        $batch = Bus::batch([])->name('Watchlist EOD Preload')->dispatch();

        foreach (array_chunk($symbols, 10) as $chunk) {
            $batch->add(Bus::chain([
                new PricesBackfillJob($chunk, 400),
                new PricesDailyJob($chunk),
                new FetchOptionChainDataJob($chunk, 90),

                new ComputeVolMetricsJob($chunk),      // needs both option + prices
                new Seasonality5DJob($chunk, 15, 2),   // prices only

                new ComputeExpiryPressureJob($chunk, 3),
                new ComputePositioningJob($chunk),
                new ComputeUAJob($chunk),
            ]));
        }
        
        $this->info("Queued preload batch: {$batch->id}");
        return self::SUCCESS;
    }
}
