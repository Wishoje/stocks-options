<?php

namespace App\Console\Commands;

use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Support\EodCacheVersion;
use Illuminate\Console\Command;

class ComputeVolMetricsCommand extends Command
{
    protected $signature = 'vol:compute {symbols?*}';

    protected $description = 'Compute IV term structure and VRP for symbols';

    public function handle(): int
    {
        $symbols = $this->argument('symbols');
        if (empty($symbols)) {
            $symbols = ['SPY', 'QQQ', 'IWM'];
        }
        $publication = new PublishEodCacheVersionJob(
            $symbols,
            [EodCacheVersion::DOMAIN_VOLATILITY]
        );
        ComputeVolMetricsJob::dispatchSync($symbols);
        $publication->handle(app(EodCacheVersion::class));
        $this->info('Computed vol metrics for: '.implode(', ', $symbols));

        return self::SUCCESS;
    }
}
