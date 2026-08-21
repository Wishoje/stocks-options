<?php

namespace App\Jobs\Middleware;

use App\Support\SymbolBootstrapCoordinator;
use Closure;
use Illuminate\Support\Facades\Log;

final class EnsureSymbolBootstrapPhaseOrchestrationCurrent
{
    public function __construct(
        private readonly string $workRunId,
        private readonly string $phase,
        private readonly string $phaseToken,
        private readonly int $attempt,
        private readonly string $orchestrationToken
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $coordinator = app(SymbolBootstrapCoordinator::class);
        if (! $coordinator->isPhaseOrchestrationCurrent(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $this->attempt,
            $this->orchestrationToken
        )) {
            Log::info('symbol_bootstrap.stale_phase_orchestration_job_skipped', [
                'run_id' => $this->workRunId,
                'phase' => $this->phase,
                'job' => $job::class,
            ]);

            return;
        }
        if (! $coordinator->heartbeatPhaseOrchestration(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $this->attempt,
            $this->orchestrationToken,
            now('UTC')
        )) {
            return;
        }

        $next($job);
    }
}
