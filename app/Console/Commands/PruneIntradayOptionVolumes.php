<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIntradayOptionVolumes extends Command
{
    protected $signature = 'intraday:prune-option-volumes
        {--hours=24 : Keep this many recent hours}
        {--batch=100000 : Rows deleted per batch}
        {--sleep-ms=50 : Sleep between batches in milliseconds}';
    protected $description = 'Delete old rows from intraday_option_volumes';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $batch = max(1000, (int) $this->option('batch'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cutoff = now('UTC')->subHours($hours);

        $this->info("Pruning intraday_option_volumes before {$cutoff->toDateTimeString()} UTC (batch={$batch})...");

        $deleted = 0;
        do {
            $n = DB::table('intraday_option_volumes')
                ->where('captured_at', '<', $cutoff)
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
