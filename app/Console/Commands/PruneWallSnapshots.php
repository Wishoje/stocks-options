<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneWallSnapshots extends Command
{
    protected $signature = 'walls:prune-snapshots
        {--days=120 : Keep this many recent days}
        {--batch=50000 : Rows deleted per batch}
        {--sleep-ms=50 : Sleep between batches in milliseconds}';

    protected $description = 'Delete old rows from symbol_wall_snapshots';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $batch = max(1000, (int) $this->option('batch'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cutoff = now('America/New_York')->subDays($days)->toDateString();

        $this->info("Pruning symbol_wall_snapshots before {$cutoff} (batch={$batch})...");

        $deleted = 0;
        do {
            $n = DB::table('symbol_wall_snapshots')
                ->where('trade_date', '<', $cutoff)
                ->limit($batch)
                ->delete();

            $deleted += $n;
            if ($n > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        } while ($n > 0);

        $this->info("Deleted {$deleted} rows total.");

        return self::SUCCESS;
    }
}
