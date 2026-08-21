<?php

namespace App\Support;

use App\Jobs\ConfirmWorkRunOrchestrationJob;
use App\Jobs\RunSymbolBootstrapPhaseJob;
use App\Models\SymbolBootstrapRun;
use App\Models\WorkRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SymbolBootstrapPhaseDispatcher
{
    public function __construct(private readonly SymbolBootstrapCoordinator $coordinator) {}

    /**
     * Dispatch every dependency-ready phase for one durable parent.
     *
     * The optional confirmation is used only for the first quote phase. It
     * closes the same root-orchestration crash window as the legacy chain.
     */
    public function dispatchReady(
        string $workRunId,
        ?ConfirmWorkRunOrchestrationJob $parentConfirmation = null
    ): int {
        $dispatched = 0;
        $candidates = $this->coordinator->dispatchable(100)
            ->where('work_run_id', $workRunId)
            ->values();

        foreach ($candidates as $candidate) {
            $expectedParentFence = $parentConfirmation === null ? null : [
                'delivery_token' => $parentConfirmation->workRunDeliveryToken,
                'attempt' => $parentConfirmation->workRunAttempt,
                'orchestration_token' => $parentConfirmation->orchestrationToken,
            ];
            $reservation = $this->coordinator->reservePhase(
                $workRunId,
                $candidate->phase,
                expectedParentFence: $expectedParentFence
            );
            if (! $reservation) {
                continue;
            }

            $phase = $reservation['phase'];
            $phaseToken = $reservation['delivery_token'];
            $job = (new RunSymbolBootstrapPhaseJob(
                $workRunId,
                $phase->phase,
                $phaseToken,
                $reservation['parent_delivery_token'],
                $reservation['parent_attempt'],
                $reservation['parent_orchestration_token']
            ))->withJobTimeout($this->timeoutFor($phase->phase, $phase->queue))
                ->onConnection($phase->queue_connection)->onQueue($phase->queue);

            try {
                if ($parentConfirmation !== null && $dispatched === 0) {
                    $parentConfirmation->onConnection($phase->queue_connection)->onQueue($phase->queue);
                    Bus::chain([$parentConfirmation, $job])
                        ->onConnection($phase->queue_connection)
                        ->onQueue($phase->queue)
                        ->dispatch();
                    $parentConfirmation = null;
                } else {
                    Bus::dispatch($job);
                }
                $this->coordinator->markPhaseDispatched(
                    $workRunId,
                    $phase->phase,
                    $phaseToken,
                    now('UTC')
                );
                $dispatched++;
            } catch (Throwable $exception) {
                $this->coordinator->markPhaseDispatchFailed(
                    $workRunId,
                    $phase->phase,
                    $phaseToken,
                    $exception,
                    now('UTC')
                );

                throw $exception;
            }
        }

        return $dispatched;
    }

    /** Recover due failed phases without replaying completed siblings. */
    public function reconcile(int $limit = 100): int
    {
        if (! Schema::hasTable('symbol_bootstrap_phases')
            || ! Schema::hasTable('symbol_bootstrap_runs')) {
            return 0;
        }

        $dueRunIds = $this->coordinator->dispatchable($limit)
            ->pluck('work_run_id')
            ->unique()
            ->values();
        $activeRunIds = SymbolBootstrapRun::query()
            ->whereHas('workRun', fn ($query) => $query->where('status', WorkRun::STATUS_RUNNING))
            ->orderBy('heartbeat_at')
            ->limit(max(1, $limit))
            ->pluck('work_run_id');
        $runIds = $dueRunIds->merge($activeRunIds)->unique()->take(max(1, $limit));
        $dispatched = 0;

        foreach ($runIds as $runId) {
            try {
                $this->coordinator->reconcileRun((string) $runId);
                $dispatched += $this->dispatchReady((string) $runId);
            } catch (Throwable $exception) {
                Log::channel('queue_monitor')->warning('symbol_bootstrap.phase_redispatch_failed', [
                    'run_id' => $runId,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $dispatched;
    }

    private function timeoutFor(string $phase, string $queue): int
    {
        return match ($phase) {
            SymbolBootstrapCoordinator::PHASE_QUOTE => 120,
            SymbolBootstrapCoordinator::PHASE_CATALOG => 240,
            SymbolBootstrapCoordinator::PHASE_FAST_EOD => 270,
            SymbolBootstrapCoordinator::PHASE_INTRADAY => $queue === (string) config(
                'queue_lanes.queues.intraday_heavy',
                'intraday-heavy'
            ) ? 540 : 105,
            SymbolBootstrapCoordinator::PHASE_ENRICHMENT => 60,
            default => 540,
        };
    }
}
