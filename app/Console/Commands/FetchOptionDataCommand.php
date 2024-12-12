<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchOptionChainDataJob;

class FetchOptionDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'options:fetch {symbols?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch option chain data from Yahoo Finance and store it in the database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Retrieve symbols from the command line input, fallback to default symbols
        $symbols = $this->argument('symbols');
        if (empty($symbols)) {
            $symbols = ['SPY', 'IWM', 'QQQ'];
        }

        // Dispatch the job to fetch data (sync for simplicity)
        FetchOptionChainDataJob::dispatchSync($symbols);

        $this->info('Option chain data fetch job dispatched for: ' . implode(', ', $symbols));

        return 0;
    }
}
