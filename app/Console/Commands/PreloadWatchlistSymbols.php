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

  // App/Console/Commands/PreloadWatchlistSymbols.php
    public function handle(): int
    {
        $rows = DB::table('watchlists')->pluck('symbol')->all();

        $symbols = collect($rows)
            ->map(fn($s) => \App\Support\Symbols::canon((string)$s))
            ->filter()                      // drop null/blank
            ->unique()
            ->values()
            ->all();

        if (!$symbols) {
            $this->info('No symbols to preload.');
            return self::SUCCESS;
        }

        $batch = Bus::batch([])->name('Watchlist EOD Preload')->allowFailures()->dispatch();

        // Chunk to keep jobs small and resilient
        foreach (array_chunk($symbols, 50) as $chunk) {
            $batch->add(new PricesBackfillJob($chunk, 400));
            $batch->add(new PricesDailyJob($chunk));
            $batch->add(new FetchOptionChainDataJob($chunk, 90));
            $batch->add(new ComputeVolMetricsJob($chunk));
            $batch->add(new Seasonality5DJob($chunk, 15, 2));
            $batch->add(new ComputeExpiryPressureJob($chunk, 3));
            $batch->add(new ComputeUAJob($chunk));
        }

        $this->info("Queued preload batch: {$batch->id} (".count($symbols)." symbols)");
        return self::SUCCESS;
    }
}
