<?php

namespace App\Console\Commands;

use App\Jobs\BootstrapUserSymbolJob;
use App\Support\CoordinationCache;
use App\Support\ProductUniverse;
use App\Support\PublicSpyPreview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PrepareProductUniverse extends Command
{
    protected $signature = 'universe:prepare {--limit=2 : Maximum symbols to initialize per run, at most 5} {--dry-run : List the next batch without dispatching jobs}';

    protected $description = 'Gradually initialize shared stock and ETF coverage through the existing bootstrap queues';

    public function handle(): int
    {
        $symbols = ProductUniverse::configuredSymbols();
        if ($symbols === []) {
            $this->info('No shared symbols need initialization.');

            return self::SUCCESS;
        }
        $catalogs = DB::table('option_expirations')->whereIn('symbol', $symbols)
            ->where('expiration_date', '>=', now('America/New_York')->toDateString())
            ->distinct()->pluck('symbol')->all();
        $prices = DB::table('prices_daily')->whereIn('symbol', $symbols)
            ->where('trade_date', PublicSpyPreview::completedSession())->distinct()->pluck('symbol')->all();
        $unprepared = array_values(array_diff($symbols, array_intersect($catalogs, $prices)));
        $cache = CoordinationCache::store();
        $attempts = $unprepared ? $cache->many(array_map(fn ($symbol) => 'universe:prepare:attempt:'.$symbol, $unprepared)) : [];
        $due = array_values(array_filter($unprepared, fn ($symbol) => empty($attempts['universe:prepare:attempt:'.$symbol])));
        $batch = array_slice($due, 0, max(1, min(5, (int) $this->option('limit'))));
        $this->line(count($unprepared).' shared symbols awaiting initialization; '.count($batch).' in this batch.');
        if ($this->option('dry-run')) {
            $this->line(implode(', ', $batch));

            return self::SUCCESS;
        }
        if ($batch === []) {
            return self::SUCCESS;
        }
        $lock = $cache->lock('universe:prepare:dispatch', 120);
        if (! $lock->get()) {
            $this->info('Shared-symbol initialization is already running.');

            return self::SUCCESS;
        }
        $failed = false;
        try {
            foreach ($batch as $symbol) {
                // Bootstrap already handles durable deduplication and provider deferrals.
                // The cooldown also bounds repeated attempts for an unavailable chain.
                if (! $cache->add('universe:prepare:attempt:'.$symbol, true, now()->addHours(6))) {
                    continue;
                }
                try {
                    $dispatched = BootstrapUserSymbolJob::dispatchIfNeeded($symbol, 'shared_universe');
                    $this->line($symbol.($dispatched ? ': initialization queued.' : ': existing or deferred initialization retained.'));
                } catch (\Throwable $error) {
                    $cache->put('universe:prepare:attempt:'.$symbol, true, now()->addMinutes(30));
                    $this->warn($symbol.': initialization could not be queued ('.get_class($error).').');
                    $failed = true;
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
