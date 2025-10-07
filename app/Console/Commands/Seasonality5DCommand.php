<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\Seasonality5DJob;

class Seasonality5DCommand extends Command
{
    protected $signature = 'seasonality:5d {symbols?*} {--years=15} {--window=2}';
    protected $description = 'Compute next-5-day seasonality for symbols';

    public function handle(): int
    {
        $symbols = $this->argument('symbols');
        if (empty($symbols)) $symbols = ['SPY','QQQ','IWM'];

        $years  = (int)$this->option('years');
        $window = (int)$this->option('window');

        Seasonality5DJob::dispatchSync($symbols, $years, $window);
        $this->info("Computed 5d seasonality for: ".implode(', ', $symbols));
        return self::SUCCESS;
    }
}
