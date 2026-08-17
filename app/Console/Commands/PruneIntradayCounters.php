<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneIntradayCounters extends Command
{
    protected $signature = 'intraday:prune-counters {--days=7 : Keep this many recent trading days}';

    protected $description = 'Delete old legacy counters and canonical option-live totals';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }
        $cutoff = now('America/New_York')->subDays($days)->toDateString();

        $this->info("Pruning intraday counters before {$cutoff}...");

        [$deletedCounters, $deletedTotals] = DB::transaction(function () use ($cutoff): array {
            // Match the dual-write lock order: legacy first, then canonical.
            $deletedCounters = DB::table('option_live_counters')
                ->where('trade_date', '<', $cutoff)
                ->delete();
            $deletedTotals = Schema::hasTable('option_live_totals')
                ? DB::table('option_live_totals')->where('trade_date', '<', $cutoff)->delete()
                : 0;

            return [$deletedCounters, $deletedTotals];
        }, 3);

        $this->info(
            "Deleted {$deletedCounters} option_live_counters rows and {$deletedTotals} option_live_totals rows."
        );

        return self::SUCCESS;
    }
}
