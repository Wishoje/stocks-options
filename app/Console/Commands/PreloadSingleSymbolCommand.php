<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PreloadSingleSymbolJob;

class PreloadSingleSymbolCommand extends Command
{
    protected $signature = 'symbols:preload
        {symbol : Ticker symbol (e.g., AAPL)}
        {--backfill=400 : Backfill days for EOD prices}
        {--chainDays=90 : Option chain days horizon}
        {--seasonWin=15 : Seasonality window for Seasonality5D}
        {--seasonStep=2 : Seasonality step for Seasonality5D}
        {--expiryWeeks=3 : Expiry lookahead weeks for ExpiryPressure}';

    protected $description = 'Preload EOD + options datasets for a single symbol (strictly ordered via chain).';

    public function handle(): int
    {
        $raw = (string)$this->argument('symbol');
        $symbol = \App\Support\Symbols::canon($raw);

        if (!$symbol) {
            $this->error("Invalid symbol: '{$raw}'");
            return self::INVALID;
        }

        $job = new PreloadSingleSymbolJob(
            symbol: $symbol,
            backfillDays: (int)$this->option('backfill'),
            chainStrikesDays: (int)$this->option('chainDays'),
            seasonalityWindow: (int)$this->option('seasonWin'),
            seasonalityStep: (int)$this->option('seasonStep'),
            expiryLookaheadWeeks: (int)$this->option('expiryWeeks'),
        );

        // Run the wrapper immediately (which then queues a CHAIN for the actual work)
        dispatch_sync($job);

        // If you prefer to just queue the wrapper, use: dispatch($job);

        $this->info("Queued single-symbol preload chain for {$symbol}.");
        return self::SUCCESS;
    }
}
