<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchPolygonIntradayOptionsJob;

class IntradayPull extends Command
{
    protected $signature = 'intraday:pull {symbols* : e.g. SPY QQQ AAPL}';
    protected $description = 'Fetch intraday option volumes from Polygon for given symbols';

    public function handle(): int
    {
        $syms = array_map('strtoupper', $this->argument('symbols'));
        dispatch_sync(new FetchPolygonIntradayOptionsJob($syms));
        $this->info('Dispatched FetchPolygonIntradayOptionsJob for: '.implode(', ', $syms));
        return self::SUCCESS;
    }
}
