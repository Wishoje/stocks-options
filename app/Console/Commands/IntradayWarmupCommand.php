<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Support\Market;
use Carbon\Carbon;
use App\Support\QueueLanes;

class IntradayWarmupCommand extends Command
{
    protected $signature = 'intraday:warmup 
                            {--limit=200 : Max symbols from hot_option_symbols}';

    protected $description = 'Fetch intraday option volumes for today\'s hot option symbols';

    public function handle(): int
    {
        // if (!Market::isRthOpen(Carbon::now('America/New_York'))) {
        //     $this->info('Market is closed; skipping intraday warmup.');
        //     return self::SUCCESS;
        // }

        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 500));

        $tradeDate = DB::table('hot_option_symbols')->max('trade_date');

        if (!$tradeDate) {
            $this->warn('hot_option_symbols is empty; run hot-options:fetch first.');
            return self::SUCCESS;
        }

        $symbols = DB::table('hot_option_symbols')
            ->whereDate('trade_date', $tradeDate)
            ->orderBy('rank')
            ->limit($limit)
            ->pluck('symbol')
            ->map(fn ($s) => \App\Support\Symbols::canon($s))
            ->unique()
            ->values()
            ->all();

        if (!$symbols) {
            $this->info('No symbols found to warm up.');
            return self::SUCCESS;
        }

        if (! QueueLanes::isolated()) {
            $heavy = array_values(array_filter(
                $symbols,
                fn (string $symbol): bool => in_array($symbol, ['SPY', 'QQQ'], true)
            ));
            $normal = array_values(array_diff($symbols, $heavy));

            foreach ($heavy as $symbol) {
                FetchPolygonIntradayOptionsJob::dispatch([$symbol]);
            }
            foreach (array_chunk($normal, 25) as $chunk) {
                FetchPolygonIntradayOptionsJob::dispatch($chunk);
            }
        } else {
            foreach (QueueLanes::scheduledIntradayBatches($symbols, 25) as $batch) {
                FetchPolygonIntradayOptionsJob::dispatch($batch)
                    ->onQueue(QueueLanes::intradayBatch($batch));
            }
        }

        $this->info('Queued intraday snapshot for: '.implode(', ', $symbols));
        return self::SUCCESS;
    }
}
