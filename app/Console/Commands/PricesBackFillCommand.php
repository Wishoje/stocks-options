<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PricesBackfillJob;

class PricesBackfillCommand extends Command
{
    protected $signature = 'prices:backfill {symbols?*} {--days=90}';
    protected $description = 'Backfill daily OHLC for the past N days';

    public function handle(): int
    {
        $symbols = $this->argument('symbols') ?: ['SPY','QQQ','IWM','MSFT'];
        PricesBackfillJob::dispatchSync($symbols, (int)$this->option('days'));
        $this->info('Backfilled prices for: '.implode(', ', $symbols));
        return self::SUCCESS;
    }
}