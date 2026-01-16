<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\Seasonality5DJob;
use App\Jobs\ComputeExpiryPressureJob;

class FetchOptionDataCommand extends Command
{
    protected $signature = 'options:fetch {symbols?*}';
    protected $description = 'Fetch option chain data from Yahoo Finance and store it in the database';

    public function handle()
    {
        $symbols = $this->argument('symbols') ?: ['SPY','IWM','QQQ'];
        $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));

        // Prices first (VolMetrics + Seasonality depend on these)
        (new PricesBackfillJob($symbols, 400))->handle();
        (new PricesDailyJob($symbols))->handle();

        // Options next (Positioning/UA/ExpiryPressure depend on these)
        FetchOptionChainDataJob::dispatchSync($symbols);

        // Computes (VolMetrics needs both prices + options)
        (new ComputeVolMetricsJob($symbols))->handle();
        (new Seasonality5DJob($symbols, 15, 2))->handle();

        (new ComputeExpiryPressureJob($symbols, 3))->handle();
        (new ComputePositioningJob($symbols))->handle();
        (new ComputeUAJob($symbols))->handle();

        $this->info('Fetched and computed positioning for: '.implode(', ', $symbols));
        return 0;
    }

}
