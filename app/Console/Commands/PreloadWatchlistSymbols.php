<?php

namespace App\Console\Commands;

use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Jobs\Seasonality5DJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class PreloadWatchlistSymbols extends Command
{
    protected $signature = 'watchlist:preload';

    protected $description = 'Preload EOD datasets for all unique watchlisted symbols.';

    public function handle(): int
    {
        $symbols = DB::table('watchlists')->select('symbol')->distinct()->pluck('symbol')->all();
        if (! $symbols) {
            $this->info('No symbols to preload.');

            return self::SUCCESS;
        }

        $jobs = [];

        foreach ($symbols as $symbol) {
            // Keep the provider request, timeout, retries, and downstream chain
            // isolated to one symbol. A dense chain must not replay or block
            // unrelated symbols in the same queue payload.
            $chunk = [$symbol];

            // First job in the chain (this is what goes into the batch)
            $first = new PricesBackfillJob($chunk, 400);

            // Chain the rest (order preserved)
            $first->chain([
                new PricesDailyJob($chunk),
                new FetchOptionChainDataJob($chunk, 90),

                new ComputeVolMetricsJob($chunk),
                new Seasonality5DJob($chunk, 15, 2),

                new ComputeExpiryPressureJob($chunk, 3),
                new ComputePositioningJob($chunk),
                new ComputeUAJob($chunk),
                // This must remain last. Failed/partial chains keep the
                // previous per-symbol response generation readable.
                new PublishEodCacheVersionJob($chunk),
            ]);

            $jobs[] = $first;
        }

        $batch = Bus::batch($jobs)
            ->name('Watchlist EOD Preload')
            ->dispatch();

        $this->info("Queued preload batch: {$batch->id}");

        return self::SUCCESS;
    }
}
