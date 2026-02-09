<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCalculatorSnapshots extends Command
{
    protected $signature = 'calculator:prune-snapshots
        {--hours=168 : Keep this many recent hours (default 7 days)}
        {--batch=50000 : Rows deleted per batch}
        {--sleep-ms=50 : Sleep between batches in milliseconds}';

    protected $description = 'Delete old rows from option_snapshots used by calculator';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $batch = max(1000, (int) $this->option('batch'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cutoff = now('UTC')->subHours($hours);

        $this->info("Pruning option_snapshots before {$cutoff->toDateTimeString()} UTC (batch={$batch})...");

        $deleted = 0;
        do {
            $n = DB::table('option_snapshots')
                ->where('fetched_at', '<', $cutoff)
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

