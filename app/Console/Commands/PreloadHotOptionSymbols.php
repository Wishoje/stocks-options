<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use App\Jobs\{
    PricesBackfillJob, PricesDailyJob, FetchOptionChainDataJob,
    ComputeVolMetricsJob, Seasonality5DJob, ComputeExpiryPressureJob, ComputeUAJob,
    ComputePositioningJob,
};

class PreloadHotOptionSymbols extends Command
{
    protected $signature = 'preload:hot-options 
                            {--limit=200 : Max symbols from hot_option_symbols}';

    protected $description = 'Preload EOD datasets for the most active option symbols (from hot_option_symbols)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 500));

        // Use latest snapshot in hot_option_symbols
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
            $this->info('No symbols found in hot_option_symbols for '.$tradeDate);
            return self::SUCCESS;
        }

        $this->info('Preloading EOD for '.$tradeDate.' – symbols: '.implode(', ', $symbols));

        // $batch = Bus::batch([
        //     new PricesBackfillJob($symbols, 400),
        //     new PricesDailyJob($symbols),
        //     new FetchOptionChainDataJob($symbols, 90),
        //     new ComputeVolMetricsJob($symbols),
        //     new Seasonality5DJob($symbols, 15, 2),
        //     new ComputeExpiryPressureJob($symbols, 3),
        //     new ComputePositioningJob($symbols),
        //     new ComputeUAJob($symbols),
        // ])
        // ->name("Hot EOD Preload {$tradeDate}")
        // ->allowFailures()
        // ->dispatch();

        // $this->info("Queued preload batch: {$batch->id}");

        return self::SUCCESS;
    }
}
