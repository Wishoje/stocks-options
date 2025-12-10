<?php

namespace App\Console\Commands;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Support\Market;
use App\Support\Symbols;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshUnderlyingQuotes extends Command
{
    protected $signature = 'prices:refresh
                            {--source=watchlist : watchlist|hot|both}
                            {--limit=200 : Max symbols from hot_option_symbols}';

    protected $description = 'Queue refresh of underlying quotes for the current symbol universe';

    public function handle(): int
    {
        $nowEt = now('America/New_York');
        // if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        //     $this->info('Market is closed; skipping price refresh.');
        //     return self::SUCCESS;
        // }

        $source = (string) $this->option('source');
        $limit  = (int) $this->option('limit');
        $limit  = max(1, min($limit, 1000));

        $symbols = collect();

        if (in_array($source, ['watchlist', 'both'], true)) {
            $symbols = $symbols->merge(
                DB::table('watchlists')->pluck('symbol')
            );
        }

        if (in_array($source, ['hot', 'both'], true)) {
            $tradeDate = DB::table('hot_option_symbols')->max('trade_date');

            if ($tradeDate) {
                $symbols = $symbols->merge(
                    DB::table('hot_option_symbols')
                        ->whereDate('trade_date', $tradeDate)
                        ->orderBy('rank')
                        ->limit($limit)
                        ->pluck('symbol')
                );
            }
        }

        // Default to a core list if nothing else
        if ($symbols->isEmpty()) {
            $symbols = collect(['SPY', 'QQQ', 'IWM', 'AAPL', 'MSFT', 'NVDA', 'TSLA', 'AMZN']);
        }

        $symbols = $symbols
            ->map(fn ($s) => Symbols::canon($s))
            ->filter()
            ->unique()
            ->values();

        if ($symbols->isEmpty()) {
            $this->warn('No symbols to refresh.');
            return self::SUCCESS;
        }

        foreach ($symbols->chunk(50) as $chunk) {
            FetchUnderlyingQuotesJob::dispatch($chunk->all());
        }

        $this->info('Queued price refresh for '.count($symbols).' symbols.');

        return self::SUCCESS;
    }
}
