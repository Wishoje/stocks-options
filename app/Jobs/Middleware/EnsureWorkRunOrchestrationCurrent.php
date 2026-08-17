<?php

namespace App\Jobs\Middleware;

use App\Support\WorkRunCoordinator;
use Closure;
use Illuminate\Support\Facades\Log;

final class EnsureWorkRunOrchestrationCurrent
{
    public function __construct(
        private readonly string $workRunId,
        private readonly string $deliveryToken,
        private readonly int $attempt,
        private readonly string $orchestrationToken
    ) {}

    public function handle(object $job, Closure $next): void
    {
        if (! app(WorkRunCoordinator::class)->isOrchestrationCurrent(
            $this->workRunId,
            $this->deliveryToken,
            $this->attempt,
            $this->orchestrationToken
        )) {
            Log::info('work_run.stale_orchestration_job_skipped', [
                'run_id' => $this->workRunId,
                'job' => $job::class,
            ]);

            return;
        }

        $next($job);
    }
}
