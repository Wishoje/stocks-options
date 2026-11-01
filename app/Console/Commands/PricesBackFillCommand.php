<?php

namespace App\Console\Commands;

use App\Jobs\PricesBackfillJob;
use Illuminate\Console\Command;

class PricesBackfillCommand extends Command
{
    protected $signature = 'prices:backfill {symbols?*} {--days=90}
        {--replace-existing : Replace complete historical OHLC for an explicit provider correction}';

    protected $description = 'Fill missing or incomplete daily OHLC while preserving complete historical bars';

    public function handle(): int
    {
        $symbols = $this->argument('symbols') ?: ['SPY', 'QQQ', 'IWM', 'MSFT'];
        PricesBackfillJob::dispatchSync($symbols, (int) $this->option('days'), null, (bool) $this->option('replace-existing'));
        $this->info('Backfilled prices for: '.implode(', ', $symbols));

        return self::SUCCESS;
    }
}
