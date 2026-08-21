<?php

namespace App\Jobs;

use App\Support\SymbolBootstrapCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ConfirmSymbolBootstrapPhaseOrchestrationJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(
        public string $workRunId,
        public string $phase,
        public string $phaseToken,
        public int $phaseAttempt,
        public string $phaseOrchestrationToken
    ) {}

    public function handle(SymbolBootstrapCoordinator $coordinator): void
    {
        $coordinator->markPhaseOrchestrationDispatched(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $this->phaseAttempt,
            $this->phaseOrchestrationToken,
            now('UTC')
        );
    }
}
