<?php

namespace App\Jobs;

use App\Jobs\Middleware\EnsureWorkRunOrchestrationCurrent;
use App\Support\QueueLanes;
use App\Support\Symbols;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class QueueSymbolEnrichmentJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(
        public string $symbol,
        public ?string $source = null,
        public ?string $workRunId = null,
        public ?string $workRunDeliveryToken = null,
        public int $workRunAttempt = 0,
        public ?string $workRunOrchestrationToken = null
    ) {
        $workRunFields = [
            $workRunId,
            $workRunDeliveryToken,
            $workRunOrchestrationToken,
        ];
        $provided = count(array_filter($workRunFields, static fn (?string $value): bool => $value !== null));
        if (! in_array($provided, [0, count($workRunFields)], true)) {
            throw new InvalidArgumentException('All work-run orchestration fields must be provided together.');
        }

        $this->onQueue(QueueLanes::bootstrapChild());
    }

    public function handle(): void
    {
        $symbol = Symbols::canon($this->symbol);
        if (! $symbol) {
            return;
        }

        if ($this->workRunId !== null) {
            $middleware = new EnsureWorkRunOrchestrationCurrent(
                $this->workRunId,
                (string) $this->workRunDeliveryToken,
                $this->workRunAttempt,
                (string) $this->workRunOrchestrationToken
            );

            $jobs = (new PrimeSymbolJob($symbol))->plannedJobs();
            foreach ($jobs as $job) {
                $this->appendToChain($job->through([$middleware]));
            }

            $this->appendToChain(
                (new CompleteWorkRunJob(
                    $this->workRunId,
                    (string) $this->workRunDeliveryToken,
                    $this->workRunAttempt
                ))->onQueue(QueueLanes::bootstrapChild())->through([$middleware])
            );

            return;
        }

        $lockKey = "symbol-enrichment:{$symbol}";
        $dispatchLock = Cache::lock($lockKey, 600);
        if (! $dispatchLock->get()) {
            return;
        }

        try {
            Bus::dispatch(new PrimeSymbolJob($symbol));
        } catch (\Throwable $exception) {
            $dispatchLock->release();
            throw $exception;
        }
    }

    protected function identityPayload(): array
    {
        return array_filter([
            'symbol' => $this->symbol,
            'source' => $this->source,
            'workRunId' => $this->workRunId,
            'workRunAttempt' => $this->workRunId !== null ? $this->workRunAttempt : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
