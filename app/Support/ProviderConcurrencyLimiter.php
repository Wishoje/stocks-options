<?php

namespace App\Support;

use App\Exceptions\ProviderConcurrencyUnavailable;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use LogicException;
use Throwable;

class ProviderConcurrencyLimiter
{
    /** @var string[] */
    private array $priorityStack = [];

    /** @var array<int, int|null> */
    private array $blockForStack = [];

    private bool $metricsFailureLogged = false;

    public function withPriority(
        string $priority,
        callable $callback,
        ?int $blockForSeconds = null
    ): mixed {
        $this->priorityStack[] = $this->normalizePriority($priority);
        $this->blockForStack[] = $blockForSeconds;

        try {
            return $callback();
        } finally {
            array_pop($this->priorityStack);
            array_pop($this->blockForStack);
        }
    }

    public function massive(callable $callback, ?int $blockForSeconds = null): mixed
    {
        $enabled = (bool) config('services.massive.concurrency.enabled', false);

        // Queue routing can happen in a web process before a worker reloads
        // its configuration. Check again at request time so an already
        // serialized job cannot bypass the required gate on a stale worker.
        if ((bool) config('queue_lanes.isolated', false) && ! $enabled) {
            throw new LogicException(
                'QUEUE_LANES_ISOLATED requires MASSIVE_CONCURRENCY_ENABLED=true.'
            );
        }

        if (! $enabled) {
            return $callback();
        }

        $limit = (int) config('services.massive.concurrency.limit', 0);
        $releaseAfter = (int) config('services.massive.concurrency.release_after', 90);
        $contextBlockFor = $this->blockForStack === []
            ? null
            : $this->blockForStack[array_key_last($this->blockForStack)];
        $blockFor = $blockForSeconds
            ?? $contextBlockFor
            ?? (int) config('services.massive.concurrency.block_for', 45);
        $sleepMilliseconds = (int) config('services.massive.concurrency.sleep_milliseconds', 100);
        $metricsTtl = (int) config('services.massive.concurrency.metrics_ttl', 172800);
        $connectionName = (string) config('services.massive.concurrency.connection', 'default');
        $prefix = (string) config('services.massive.concurrency.key', 'provider-concurrency:massive');

        if ($limit < 2) {
            throw new InvalidArgumentException('Massive concurrency limit must be at least 2.');
        }
        if ($releaseAfter < 1 || $blockFor < 0 || $sleepMilliseconds < 1 || $metricsTtl < 60) {
            throw new InvalidArgumentException('Massive concurrency timing values are invalid.');
        }

        $priority = end($this->priorityStack) ?: QueueLanes::PRIORITY_BACKGROUND;
        // A static partition is deliberately conservative for the first
        // rollout. The two class limits sum to the verified provider ceiling,
        // so both classes always progress and no second global acquisition is
        // needed. GEX-021 may add measured borrowing of idle capacity.
        $classLimit = $priority === QueueLanes::PRIORITY_INTERACTIVE
            ? intdiv($limit + 1, 2)
            : intdiv($limit, 2);
        $connection = Redis::connection($connectionName);
        $startedAt = microtime(true);

        return $connection
            ->funnel("{$prefix}:class:{$priority}:")
            ->limit($classLimit)
            ->releaseAfter($releaseAfter)
            ->block($blockFor)
            ->sleep($sleepMilliseconds)
            ->then(
                function () use (
                    $callback,
                    $connection,
                    $prefix,
                    $priority,
                    $startedAt,
                    $metricsTtl
                ): mixed {
                    $waitMilliseconds = (int) round((microtime(true) - $startedAt) * 1000);
                    $this->recordMetric(
                        $connection,
                        $prefix,
                        $priority,
                        'acquired',
                        $waitMilliseconds,
                        $metricsTtl
                    );

                    try {
                        $result = $callback();
                        $this->recordMetric(
                            $connection,
                            $prefix,
                            $priority,
                            'completed',
                            0,
                            $metricsTtl
                        );

                        return $result;
                    } catch (Throwable $exception) {
                        $this->recordMetric(
                            $connection,
                            $prefix,
                            $priority,
                            'provider_exception',
                            0,
                            $metricsTtl
                        );

                        throw $exception;
                    }
                },
                function (LimiterTimeoutException $exception) use (
                    $connection,
                    $prefix,
                    $priority,
                    $startedAt,
                    $metricsTtl
                ): never {
                    $this->recordMetric(
                        $connection,
                        $prefix,
                        $priority,
                        'acquire_timeout',
                        (int) round((microtime(true) - $startedAt) * 1000),
                        $metricsTtl
                    );

                    throw new ProviderConcurrencyUnavailable(
                        'Massive provider priority capacity unavailable.',
                        previous: $exception
                    );
                }
            );
    }

    private function recordMetric(
        mixed $connection,
        string $prefix,
        string $priority,
        string $event,
        int $waitMilliseconds,
        int $metricsTtl
    ): void {
        try {
            $key = "{$prefix}:metrics:".gmdate('Y-m-d');
            $connection->hincrby($key, "{$priority}:{$event}", 1);
            if ($waitMilliseconds > 0) {
                $connection->hincrby($key, "{$priority}:wait_ms", $waitMilliseconds);
            }
            $connection->expire($key, $metricsTtl);
        } catch (Throwable $exception) {
            if (! $this->metricsFailureLogged) {
                $this->metricsFailureLogged = true;
                Log::channel('queue_monitor')->warning('provider_concurrency.metrics_failed', [
                    'provider' => 'massive',
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function normalizePriority(string $priority): string
    {
        return $priority === QueueLanes::PRIORITY_INTERACTIVE
            ? QueueLanes::PRIORITY_INTERACTIVE
            : QueueLanes::PRIORITY_BACKGROUND;
    }
}
