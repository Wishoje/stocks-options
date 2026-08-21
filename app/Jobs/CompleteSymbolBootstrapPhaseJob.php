<?php

namespace App\Jobs;

use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPhaseDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CompleteSymbolBootstrapPhaseJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    /** @param array<string,mixed> $outcome */
    public function __construct(
        public string $workRunId,
        public string $phase,
        public string $phaseToken,
        public int $phaseAttempt,
        public array $outcome = []
    ) {}

    public function handle(
        SymbolBootstrapCoordinator $coordinator,
        SymbolBootstrapPhaseDispatcher $dispatcher
    ): void {
        if (! $coordinator->markPhaseCompleted(
            $this->workRunId,
            $this->phase,
            $this->phaseToken,
            $this->phaseAttempt,
            $this->outcome,
            now('UTC')
        )) {
            return;
        }

        $dispatcher->dispatchReady($this->workRunId);
    }
}
