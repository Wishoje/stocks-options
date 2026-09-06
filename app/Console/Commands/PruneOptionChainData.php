<?php

namespace App\Console\Commands;

use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        $healthEnabled = (bool) config('eod_snapshot_health.enabled', false);
        $mutationTokens = [];
        try {
            do {
                if ($healthEnabled) {
                    $rows = DB::table('option_chain_data as o')
                        ->leftJoin('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                        ->where('o.data_date', '<', $cutoff)
                        ->limit($batch)
                        ->get(['o.id', 'e.symbol']);
                    if ($rows->contains(fn ($row): bool => ! is_string($row->symbol) || trim($row->symbol) === '')) {
                        throw new RuntimeException('Pruning refuses rows without a resolvable symbol mutation fence.');
                    }
                    foreach ($rows->pluck('symbol')->unique()->sort()->values() as $symbol) {
                        if (! array_key_exists($symbol, $mutationTokens)) {
                            $mutationTokens[$symbol] = app(EodSnapshotHealth::class)->begin($symbol,
                                'prune-option-chain:v1:before:'.$cutoff,
                                ['source' => 'prune-option-chain', 'data_date' => $cutoff]);
                        }
                    }
                    $n = $rows->isEmpty() ? 0 : DB::table('option_chain_data')
                        ->whereIntegerInRaw('id', $rows->pluck('id')->all())
                        ->where('data_date', '<', $cutoff)
                        ->delete();
                } else {
                    $n = DB::table('option_chain_data')
                        ->where('data_date', '<', $cutoff)
                        ->limit($batch)
                        ->delete();
                }

                $deleted += $n;

                if ($n > 0 && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            } while ($healthEnabled ? $rows->isNotEmpty() : $n > 0);
            $fencedSymbols = array_keys($mutationTokens);
            foreach ($mutationTokens as $symbol => $token) {
                app(EodSnapshotHealth::class)->complete($token);
                unset($mutationTokens[$symbol]);
            }
            if ($healthEnabled && $fencedSymbols !== []) {
                app(EodCacheVersion::class)->publish($fencedSymbols, [EodCacheVersion::DOMAIN_GEX]);
            }
        } finally {
            foreach ($mutationTokens as $token) {
                app(EodSnapshotHealth::class)->fail($token);
            }
        }

        $this->info("Deleted {$deleted} rows total.");

        return self::SUCCESS;
    }
}
