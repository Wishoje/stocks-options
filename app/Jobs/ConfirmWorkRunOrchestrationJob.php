<?php

namespace App\Jobs;

use App\Support\WorkRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ConfirmWorkRunOrchestrationJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public string $workRunId,
        public string $workRunDeliveryToken,
        public int $workRunAttempt,
        public string $orchestrationToken
    ) {}

    public function handle(WorkRunCoordinator $runs): void
    {
        $runs->markOrchestrationDispatched(
            $this->workRunId,
            $this->workRunDeliveryToken,
            $this->workRunAttempt,
            $this->orchestrationToken,
            now()
        );
    }

    protected function identityPayload(): array
    {
        return [
            'workRunId' => $this->workRunId,
            'workRunAttempt' => $this->workRunAttempt,
        ];
    }
}
