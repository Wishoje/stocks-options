<?php

namespace App\Jobs\Middleware;

use App\Support\SymbolBootstrapCoordinator;
use Closure;
use Illuminate\Support\Facades\Log;

final class EnsureSymbolBootstrapPhaseCurrent
{
    public function __construct(
        private readonly string $workRunId,
        private readonly string $phase,
        private readonly string $phaseToken
    ) {}

    public function handle(object $job, Closure $next): void
    {
        if (! app(SymbolBootstrapCoordinator::class)->isPhaseCurrent(
            $this->workRunId,
            $this->phase,
            $this->phaseToken
        )) {
            Log::info('symbol_bootstrap.stale_phase_job_skipped', [
                'run_id' => $this->workRunId,
                'phase' => $this->phase,
                'job' => $job::class,
            ]);

            return;
        }

        $next($job);
    }
}
