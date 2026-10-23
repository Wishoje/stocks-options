<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIntradayOptionVolumes extends Command
{
    protected $signature = 'intraday:prune-option-volumes
        {--hours=24 : Keep this many recent hours}
        {--batch=10000 : Rows deleted per batch, capped at 10000}
        {--sleep-ms=50 : Sleep between batches in milliseconds}';

    protected $description = 'Delete old rows from intraday_option_volumes';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $batch = max(1000, min(10000, (int) $this->option('batch')));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cutoff = now('UTC')->subHours($hours);

        $this->info("Pruning intraday_option_volumes before {$cutoff->toDateTimeString()} UTC (batch={$batch})...");

        $deleted = 0;
        $lastId = 0;
        $highestId = (int) DB::table('intraday_option_volumes')->max('id');
        do {
            // Scan each part of the primary key once, rather than restarting
            // through deleted pages for every batch. Later inserts wait until tomorrow.
            $ids = DB::table('intraday_option_volumes')
                ->where('id', '>', $lastId)->where('id', '<=', $highestId)
                ->where('captured_at', '<', $cutoff)
                ->orderBy('id')->limit($batch)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            $lastId = (int) max($ids);
            $n = DB::table('intraday_option_volumes')
                ->whereIntegerInRaw('id', $ids)
                ->where('captured_at', '<', $cutoff)
                ->delete();

            $deleted += $n;
            if ($n > 0 && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        } while (true);

        $this->info("Deleted {$deleted} rows total.");

        return self::SUCCESS;
    }
}
