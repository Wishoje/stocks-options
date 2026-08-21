<?php

namespace App\Console\Commands;

use App\Models\WorkRun;
use App\Support\SymbolBootstrapPhaseDispatcher;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReconcileWorkRuns extends Command
{
    protected $signature = 'work-runs:reconcile {--limit=100 : Maximum active runs to inspect}';

    protected $description = 'Recover durable market-data work runs that were not dispatched or became abandoned';

    public function handle(
        WorkRunCoordinator $runs,
        WorkRunDispatcher $dispatcher,
        SymbolBootstrapPhaseDispatcher $bootstrapPhases
    ): int {
        if (! Schema::hasTable('work_runs') || ! Schema::hasTable('work_run_slots')) {
            $this->line(json_encode([
                'skipped' => true,
                'reason' => 'work_run_schema_unavailable',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $abandoned = 0;
        $dispatched = 0;
        $errors = 0;
        $bootstrapPhasesDispatched = 0;

        WorkRun::query()
            ->whereIn('status', WorkRun::ACTIVE_STATUSES)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now('UTC'))
            ->orderBy('requested_at')
            ->limit($limit)
            ->pluck('id')
            ->each(function (string $runId) use ($runs, &$abandoned): void {
                if ($runs->markAbandoned($runId)) {
                    $abandoned++;
                }
            });

        foreach ($runs->dispatchable($limit) as $run) {
            try {
                if ($dispatcher->dispatch($run)) {
                    $dispatched++;
                }
            } catch (Throwable $exception) {
                $errors++;
                Log::channel('queue_monitor')->warning('work_run.redispatch_failed', [
                    'run_id' => $run->id,
                    'kind' => $run->kind,
                    'symbol' => $run->symbol,
                    'exception' => $exception::class,
                ]);
            }
        }

        $bootstrapPhasesDispatched = $bootstrapPhases->reconcile($limit);

        $this->line(json_encode([
            'dispatched' => $dispatched,
            'bootstrap_phases_dispatched' => $bootstrapPhasesDispatched,
            'abandoned' => $abandoned,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
