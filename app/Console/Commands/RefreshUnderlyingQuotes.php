<?php

namespace App\Console\Commands;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Support\QuoteRefreshDispatcher;
use App\Support\QuoteRefreshPolicy;
use App\Support\Symbols;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshUnderlyingQuotes extends Command
{
    protected $signature = 'prices:refresh
                            {--source=watchlist : watchlist|hot|both}
                            {--limit=200 : Max symbols from hot_option_symbols}';

    protected $description = 'Queue refresh of underlying quotes for the current symbol universe';

    public function handle(QuoteRefreshPolicy $policy, QuoteRefreshDispatcher $dispatcher): int
    {
        $durable = $policy->enabled();
        if ($durable && ! ($window = $policy->window())['eligible']) {
            $this->info('Scheduled quote refresh skipped: '.$window['reason'].'.');

            return self::SUCCESS;
        }

        $source = (string) $this->option('source');
        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 1000));

        $symbols = collect();

        if (in_array($source, ['watchlist', 'both'], true)) {
            $symbols = $symbols->merge(
                $durable
                    ? DB::table('watchlists')
                        ->whereNotNull('symbol')
                        ->whereRaw("TRIM(symbol) <> ''")
                        ->selectRaw('UPPER(TRIM(symbol)) as symbol')
                        ->distinct()->orderBy('symbol')->pluck('symbol')
                    : DB::table('watchlists')->pluck('symbol')
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

        if ($durable) {
            $result = $dispatcher->dispatch($symbols);
            Log::channel('scheduler')->info('quotes.scheduled_dispatch', $result);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        // Four bounded requests fit under the 90-second job ceiling even when
        // each provider call consumes its full retry/request allowance.
        foreach ($symbols->chunk(4) as $chunk) {
            FetchUnderlyingQuotesJob::dispatch($chunk->all());
        }

        $this->info('Queued price refresh for '.count($symbols).' symbols.');

        return self::SUCCESS;
    }
}
