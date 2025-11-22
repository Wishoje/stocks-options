<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIntradayCounters extends Command
{
    protected $signature = 'intraday:prune-counters {--days=7 : Keep this many recent trading days}';
    protected $description = 'Delete old rows from option_live_counters';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now('America/New_York')->subDays($days)->toDateString();

        $this->info("Pruning option_live_counters before {$cutoff}…");

        $deleted = DB::table('option_live_counters')
            ->where('trade_date', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} rows.");

        return self::SUCCESS;
    }
}
