<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PricesDailyJob;

class PricesSeedCommand extends Command
{
    protected $signature = 'prices:seed {symbols?*}';
    protected $description = 'Fetch and store daily OHLC for symbols';

    public function handle(): int
    {
        $symbols = $this->argument('symbols');
        if (empty($symbols)) $symbols = ['SPY','QQQ','IWM']; // default set
        PricesDailyJob::dispatchSync($symbols);
        $this->info('Seeded prices for: '.implode(', ', $symbols));
        return self::SUCCESS;
    }
}
