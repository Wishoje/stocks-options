<?php

namespace App\Jobs;

use App\Jobs\Middleware\EnsureWorkRunOrchestrationCurrent;
use App\Support\QueueLanes;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class BootstrapUserSymbolJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'bootstrap';

    public int $timeout = 60;

    public function __construct(
        public string $symbol,
        public ?string $source = null,
        public ?string $workRunId = null,
        public ?string $workRunDeliveryToken = null
    ) {
        if (($workRunId === null) !== ($workRunDeliveryToken === null)) {
            throw new InvalidArgumentException('Work-run ID and delivery token must be provided together.');
        }

        $this->onQueue(QueueLanes::bootstrap());
    }

    public static function dispatchIfNeeded(string $symbol, ?string $source = null, int $ttlSeconds = 120): bool
    {
        $sym = Symbols::canon($symbol);
        if (! $sym) {
            return false;
        }

        $lockKey = "symbol-bootstrap:dispatch:{$sym}";
        $dispatchLock = Cache::lock($lockKey, $ttlSeconds);
        if (! $dispatchLock->get()) {
            return false;
        }

        try {
            Bus::dispatch(new self($sym, $source));
        } catch (\Throwable $exception) {
            $dispatchLock->release();
            throw $exception;
        }

        return true;
    }

    public function handle(): void
    {
        $workRuns = $this->workRunCoordinator();
        $workRunAttempt = max(1, $this->attempts());
        if ($workRuns && $workRuns->hasDispatchedOrchestration(
            (string) $this->workRunId,
            (string) $this->workRunDeliveryToken
        )) {
            return;
        }

        if ($workRuns && ! $workRuns->markStarted(
            (string) $this->workRunId,
            (string) $this->workRunDeliveryToken,
            $workRunAttempt,
            now()
        )) {
            return;
        }

        $symbol = Symbols::canon($this->symbol);
        if (! $symbol) {
            $workRuns?->markFailed(
                (string) $this->workRunId,
                (string) $this->workRunDeliveryToken,
                $workRunAttempt,
                'validation',
                'invalid_symbol',
                now()
            );

            return;
        }

        $chainLock = null;
        if (! $workRuns) {
            $chainKey = "symbol-bootstrap:chain:{$symbol}";
            $chainLock = Cache::lock($chainKey, 180);
            if (! $chainLock->get()) {
                return;
            }
        }

        $tradeDate = $this->tradeDate(now('America/New_York'));

        $orchestrationToken = null;
        try {
            $queue = QueueLanes::bootstrapChild();
            $orchestrationToken = $workRuns?->reserveOrchestration(
                (string) $this->workRunId,
                (string) $this->workRunDeliveryToken,
                $workRunAttempt
            );
            if ($workRuns && ! $orchestrationToken) {
                return;
            }

            $jobs = [
                (new PricesDailyJob([$symbol]))->withJobTimeout(270)->onQueue($queue),
                (new FetchOptionChainDataJob([$symbol], 90, null, 270))->onQueue($queue),
                (new ComputeExpiryPressureJob([$symbol], 3, $tradeDate))->withJobTimeout(270)->onQueue($queue),
                (new ComputePositioningJob([$symbol], $tradeDate))->withJobTimeout(270)->onQueue($queue),
                (new FetchPolygonIntradayOptionsJob([$symbol], 270))->onQueue($queue),
            ];

            if ($workRuns) {
                array_unshift($jobs, (new ConfirmWorkRunOrchestrationJob(
                    (string) $this->workRunId,
                    (string) $this->workRunDeliveryToken,
                    $workRunAttempt,
                    (string) $orchestrationToken
                ))->onQueue($queue));
                $jobs[] = (new QueueSymbolEnrichmentJob(
                    $symbol,
                    $this->source,
                    (string) $this->workRunId,
                    (string) $this->workRunDeliveryToken,
                    $workRunAttempt,
                    (string) $orchestrationToken
                ))->onQueue($queue);

                $middleware = new EnsureWorkRunOrchestrationCurrent(
                    (string) $this->workRunId,
                    (string) $this->workRunDeliveryToken,
                    $workRunAttempt,
                    (string) $orchestrationToken
                );
                $jobs = array_map(
                    static fn (object $job): object => $job->through([$middleware]),
                    $jobs
                );
            } else {
                $jobs[] = (new QueueSymbolEnrichmentJob($symbol, $this->source))->onQueue($queue);
            }

            $chain = Bus::chain($jobs);
            if ($workRuns) {
                $workRunId = (string) $this->workRunId;
                $deliveryToken = (string) $this->workRunDeliveryToken;
                $chain->catch(static function (Throwable $exception) use (
                    $workRunId,
                    $deliveryToken,
                    $workRunAttempt
                ): void {
                    app(WorkRunCoordinator::class)->markTerminalException(
                        $workRunId,
                        $deliveryToken,
                        $workRunAttempt,
                        $exception
                    );
                });
            }
            $chain->dispatch();
        } catch (\Throwable $exception) {
            if ($workRuns && $orchestrationToken) {
                $workRuns->markOrchestrationDispatchFailed(
                    (string) $this->workRunId,
                    (string) $this->workRunDeliveryToken,
                    $workRunAttempt,
                    (string) $orchestrationToken
                );
            } else {
                $chainLock?->release();
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->workRunCoordinator()?->markTerminalException(
            (string) $this->workRunId,
            (string) $this->workRunDeliveryToken,
            max(1, $this->attempts()),
            $exception
        );

        parent::failed($exception);
    }

    protected function identityPayload(): array
    {
        return array_filter([
            'symbol' => $this->symbol,
            'source' => $this->source,
            'workRunId' => $this->workRunId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function workRunCoordinator(): ?WorkRunCoordinator
    {
        return $this->workRunId !== null ? app(WorkRunCoordinator::class) : null;
    }

    private function tradeDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();

            return $ny->toDateString();
        }

        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }
}
