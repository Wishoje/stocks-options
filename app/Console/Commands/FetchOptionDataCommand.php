<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputePositioningJob;   // ← add

class FetchOptionDataCommand extends Command
{
    protected $signature = 'options:fetch {symbols?*}';
    protected $description = 'Fetch option chain data from Yahoo Finance and store it in the database';

    public function handle()
    {
        $symbols = $this->argument('symbols') ?: ['SPY','IWM','QQQ'];
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));

        // 1) Ingest (your existing fetch)
        FetchOptionChainDataJob::dispatchSync($symbols);

        // 2) Compute DEX/Gamma regime for those symbols (immediate for testing)
        //    Use dispatch() if you prefer async with a queue worker.
        (new ComputePositioningJob($symbols))->handle();
        // or: foreach (array_chunk($symbols, 25) as $chunk) ComputePositioningJob::dispatch($chunk);

        $this->info('Fetched and computed positioning for: '.implode(', ', $symbols));
        return 0;
    }
}
