<?php

namespace App\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneCalculatorSnapshots extends Command
{
    protected $signature = 'calculator:prune-snapshots
        {--hours=168 : Keep this many recent hours (default 7 days)}
        {--batch=50000 : Rows deleted per batch}
        {--sleep-ms=50 : Sleep between batches in milliseconds}';

    protected $description = 'Delete old legacy snapshots and unreferenced calculator publications';

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

        $publicationRuns = $this->prunePublicationRuns($cutoff, min(500, $batch), $sleepMs);

        $this->info("Deleted {$deleted} legacy rows and {$publicationRuns} publication runs total.");

        return self::SUCCESS;
    }

    private function prunePublicationRuns(CarbonInterface $cutoff, int $batch, int $sleepMs): int
    {
        if (! Schema::hasTable('calculator_publication_runs')) {
            return 0;
        }

        $deleted = 0;
        do {
            $protectedCatalogRuns = DB::table('calculator_catalog_heads')
                ->select('current_run_id as run_id')
                ->whereNotNull('current_run_id')
                ->union(
                    DB::table('calculator_catalog_heads')
                        ->select('previous_run_id as run_id')
                        ->whereNotNull('previous_run_id')
                );
            $protectedExpiryRuns = DB::table('calculator_expiry_publications as publication')
                ->join('calculator_expiry_heads as head', function ($join): void {
                    $join->on('head.current_publication_id', '=', 'publication.id')
                        ->orOn('head.previous_publication_id', '=', 'publication.id');
                })
                ->select('publication.run_id');

            $runIds = DB::table('calculator_publication_runs')
                ->whereIn('status', ['complete', 'superseded', 'failed', 'capped'])
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->whereNotIn('id', $protectedCatalogRuns)
                ->whereNotIn('id', $protectedExpiryRuns)
                ->orderBy('completed_at')
                ->limit(max(1, $batch))
                ->pluck('id')
                ->all();

            if ($runIds === []) {
                break;
            }

            $deleted += $this->deletePublicationCandidates($runIds, $cutoff);
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        } while (true);

        return $deleted;
    }

    /**
     * Revalidate a previously selected batch while holding the same row locks
     * used by publication and rollback. A head may have changed after the
     * candidate query, so the outer query alone is not a deletion authority.
     *
     * @param  list<string>  $candidateRunIds
     */
    protected function deletePublicationCandidates(
        array $candidateRunIds,
        CarbonInterface $cutoff
    ): int {
        if ($candidateRunIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($candidateRunIds, $cutoff): int {
            $runs = DB::table('calculator_publication_runs')
                ->whereIn('id', $candidateRunIds)
                ->whereIn('status', ['complete', 'superseded', 'failed', 'capped'])
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $cutoff)
                ->lockForUpdate()
                ->get(['id', 'symbol']);
            $runIds = $runs->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            if ($runIds === []) {
                return 0;
            }
            $symbols = $runs->pluck('symbol')->map(static fn ($symbol): string => (string) $symbol)->unique()->all();

            $publications = DB::table('calculator_expiry_publications')
                ->whereIn('run_id', $runIds)
                ->lockForUpdate()
                ->get(['id', 'run_id', 'symbol', 'expiration']);
            $publicationIds = $publications
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            $catalogHeads = DB::table('calculator_catalog_heads')
                ->whereIn('symbol', $symbols)
                ->lockForUpdate()
                ->get(['current_run_id', 'previous_run_id']);

            $expiryHeads = collect();
            if ($publicationIds !== []) {
                $expiryHeads = DB::table('calculator_expiry_heads')
                    ->whereIn('symbol', $symbols)
                    ->lockForUpdate()
                    ->get(['current_publication_id', 'previous_publication_id']);
            }

            $protectedRunIds = $catalogHeads
                ->flatMap(static fn (object $head): array => [
                    $head->current_run_id,
                    $head->previous_run_id,
                ])
                ->filter()
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($expiryHeads->isNotEmpty()) {
                $protectedPublicationIds = $expiryHeads
                    ->flatMap(static fn (object $head): array => [
                        $head->current_publication_id,
                        $head->previous_publication_id,
                    ])
                    ->filter()
                    ->map(static fn ($id): string => (string) $id)
                    ->all();
                $protectedRunIds = array_merge(
                    $protectedRunIds,
                    $publications
                        ->whereIn('id', $protectedPublicationIds)
                        ->pluck('run_id')
                        ->map(static fn ($id): string => (string) $id)
                        ->all()
                );
            }

            $deleteRunIds = array_values(array_diff($runIds, array_unique($protectedRunIds)));
            if ($deleteRunIds === []) {
                return 0;
            }

            $deletePublicationIds = DB::table('calculator_expiry_publications')
                ->whereIn('run_id', $deleteRunIds)
                ->pluck('id')
                ->all();
            if ($deletePublicationIds !== []) {
                DB::table('calculator_expiry_publication_rows')
                    ->whereIn('publication_id', $deletePublicationIds)
                    ->delete();
            }
            DB::table('calculator_run_expirations')->whereIn('run_id', $deleteRunIds)->delete();
            DB::table('calculator_expiry_publications')->whereIn('run_id', $deleteRunIds)->delete();
            DB::table('calculator_publication_runs')->whereIn('id', $deleteRunIds)->delete();

            return count($deleteRunIds);
        }, 3);
    }
}
