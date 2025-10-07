<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ComputeVolMetricsJob;

class ComputeVolMetricsCommand extends Command
{
    protected $signature = 'vol:compute {symbols?*}';
    protected $description = 'Compute IV term structure and VRP for symbols';

    public function handle(): int
    {
        $symbols = $this->argument('symbols');
        if (empty($symbols)) $symbols = ['SPY','QQQ','IWM'];
        ComputeVolMetricsJob::dispatchSync($symbols);
        $this->info('Computed vol metrics for: '.implode(', ',$symbols));
        return self::SUCCESS;
    }
}
