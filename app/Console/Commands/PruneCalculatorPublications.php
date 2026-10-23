<?php

namespace App\Console\Commands;

use App\Support\CalculatorPublicationPruner;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneCalculatorPublications extends Command
{
    protected $signature = 'calculator:prune-publications {--hours=168} {--batch=25} {--max-seconds=900} {--dry-run}';

    protected $description = 'Archive and prune old calculator versions without removing current or rollback publications';

    public function handle(CalculatorPublicationPruner $pruner): int
    {
        if (! $this->option('dry-run') && MarketSession::describe(CarbonImmutable::now('UTC'))['is_rth']) {
            $this->error('Calculator version pruning runs outside the regular market session.');

            return self::FAILURE;
        }

        return Cache::lock('maintenance:calculator-publication-prune', 1800)->get(function () use ($pruner) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                DB::statement('SET SESSION lock_wait_timeout=3');
            }
            $cutoff = CarbonImmutable::now('UTC')->subHours(max(168, (int) $this->option('hours')));
            $summary = ['at' => now('UTC')->toIso8601String(), 'cutoff' => $cutoff->toIso8601String(),
                'candidate_publications' => 0, 'candidate_rows' => 0,
                'deleted_publications' => 0, 'deleted_rows' => 0, 'archive_bytes' => 0, 'archives' => [], 'status' => 'preview'];
            try {
                $candidates = $pruner->candidates($cutoff);
                $summary['candidate_publications'] = count($candidates);
                $summary['candidate_rows'] = array_sum(array_column($candidates, 'row_count'));
                if (! $this->option('dry-run')) {
                    $summary['status'] = 'running';
                    $this->saveReport($summary, false);
                    $started = microtime(true);
                    $budget = max(1, min(900, (int) $this->option('max-seconds')));
                    foreach (array_chunk($candidates, max(1, min(25, (int) $this->option('batch')))) as $batch) {
                        if (microtime(true) - $started >= $budget) {
                            $summary['status'] = 'budget_reached';
                            break;
                        }
                        $result = $pruner->prune($batch, $cutoff);
                        $summary['deleted_publications'] += $result['publications'];
                        $summary['deleted_rows'] += $result['rows'];
                        if ($result['archive']) {
                            $summary['archive_bytes'] += $result['archive']['bytes'];
                            $summary['archives'][] = $result['archive'];
                        }
                        if (count($summary['archives']) % 100 === 0) {
                            $this->saveReport($summary, false);
                        }
                        usleep(25000);
                    }
                    if ($summary['status'] !== 'budget_reached') {
                        $summary['status'] = 'complete';
                    }
                    if ($summary['deleted_rows'] > 0 && DB::getDriverName() === 'mysql') {
                        // Refresh allocation estimates for the later guarded disk check.
                        $summary['statistics_refresh'] = DB::select('ANALYZE TABLE calculator_expiry_publication_rows');
                    }
                    $this->saveReport($summary, true);
                }
                $this->line(json_encode(array_diff_key($summary, ['archives' => true]), JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            } catch (Throwable $error) {
                report($error);
                if (! $this->option('dry-run')) {
                    $summary['status'] = 'error';
                    $summary['error_class'] = get_class($error);
                    $this->saveReport($summary, true);
                }
                $this->error('Calculator pruning stopped safely; inspect the private maintenance report and application log.');

                return self::FAILURE;
            }
        }) ?? self::SUCCESS;
    }

    private function saveReport(array $summary, bool $complete): void
    {
        $root = config('maintenance.calculator_archive_path');
        if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700, true))) {
            throw new \RuntimeException('Maintenance report directory is unavailable.');
        }
        $latest = json_encode(array_diff_key($summary, ['archives' => true]), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        file_put_contents($root.'/latest.json.tmp', $latest, LOCK_EX);
        chmod($root.'/latest.json.tmp', 0600);
        rename($root.'/latest.json.tmp', $root.'/latest.json');
        if ($complete) {
            $path = $root.'/report-'.now('UTC')->format('Y-m-d').'.json';
            file_put_contents($path, json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
            chmod($path, 0600);
        }
    }
}
