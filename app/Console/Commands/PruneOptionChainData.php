<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOptionChainData extends Command
{
    protected $signature = 'options:prune-chain-data
        {--days=180 : Keep this many recent EOD data days}
        {--batch=50000 : Rows deleted per batch}
        {--sleep-ms=50 : Sleep between batches in milliseconds}
        {--dry-run : Count matching rows without deleting}';

    protected $description = 'Delete old rows from option_chain_data';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $batch = max(1000, (int) $this->option('batch'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $cutoff = now('America/New_York')->subDays($days)->toDateString();

        $query = DB::table('option_chain_data')
            ->where('data_date', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->info("Would delete {$count} option_chain_data rows before {$cutoff}.");

            return self::SUCCESS;
        }

        $this->info("Pruning option_chain_data before {$cutoff} (batch={$batch})...");

        $deleted = 0;
        do {
            $n = DB::table('option_chain_data')
                ->where('data_date', '<', $cutoff)
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
