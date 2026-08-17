<?php

namespace App\Jobs;

use App\Support\WorkRunCoordinator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CompleteWorkRunJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public string $workRunId,
        public string $workRunDeliveryToken,
        public int $workRunAttempt
    ) {}

    public function handle(WorkRunCoordinator $runs): void
    {
        $runs->markCompleted(
            $this->workRunId,
            $this->workRunDeliveryToken,
            $this->workRunAttempt,
            now()
        );
    }

    public function failed(Throwable $exception): void
    {
        app(WorkRunCoordinator::class)->markTerminalException(
            $this->workRunId,
            $this->workRunDeliveryToken,
            $this->workRunAttempt,
            $exception
        );

        parent::failed($exception);
    }

    protected function identityPayload(): array
    {
        return [
            'workRunId' => $this->workRunId,
            'workRunAttempt' => $this->workRunAttempt,
        ];
    }
}
