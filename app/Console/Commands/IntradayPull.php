<?php

namespace App\Console\Commands;

use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Support\QueueLanes;
use Illuminate\Console\Command;

class IntradayPull extends Command
{
    protected $signature = 'intraday:pull {symbols* : e.g. SPY QQQ AAPL}';

    protected $description = 'Fetch intraday option volumes from Polygon for given symbols';

    public function handle(): int
    {
        $syms = array_map('strtoupper', $this->argument('symbols'));
        if (! QueueLanes::isolated()) {
            dispatch_sync(new FetchPolygonIntradayOptionsJob($syms));
            $this->info('Completed FetchPolygonIntradayOptionsJob for: '.implode(', ', $syms));

            return self::SUCCESS;
        }

        foreach (QueueLanes::scheduledIntradayBatches($syms, count($syms)) as $batch) {
            FetchPolygonIntradayOptionsJob::dispatch($batch)
                ->onQueue(QueueLanes::intradayBatch($batch, interactive: true));
        }
        $this->info('Queued FetchPolygonIntradayOptionsJob for: '.implode(', ', $syms));

        return self::SUCCESS;
    }
}
