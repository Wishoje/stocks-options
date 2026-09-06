<?php

namespace App\Support;

use App\Exceptions\ProviderConcurrencyUnavailable;
use App\Exceptions\ProviderDeferred;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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

    private ?CarbonImmutable $localCooldownUntil = null;

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

    public function massive(
        callable $callback,
        ?int $blockForSeconds = null,
        ?string $requestKey = null
    ): mixed {
        if (! config('provider_backpressure.enabled', false)
            || ! config('services.massive.concurrency.enabled', false)) {
            return $this->legacyMassive($callback, $blockForSeconds);
        }

        $replay = app(ProviderRequestReplay::class);
        if ($requestKey !== null && ($cached = $replay->lookup($requestKey)) !== null) {
            return $cached;
        }

        $limit = (int) config('services.massive.concurrency.limit', 0);
        $releaseAfter = (int) config('services.massive.concurrency.release_after', 90);
        $metricsTtl = (int) config('services.massive.concurrency.metrics_ttl', 172800);
        if ($limit < 2 || $releaseAfter < 1 || $metricsTtl < 60) {
            throw new InvalidArgumentException('Massive concurrency configuration is invalid.');
        }
        $priority = end($this->priorityStack) ?: QueueLanes::PRIORITY_BACKGROUND;
        $classLimit = $priority === QueueLanes::PRIORITY_INTERACTIVE
            ? intdiv($limit + 1, 2) : intdiv($limit, 2);
        $prefix = (string) config('services.massive.concurrency.key', 'provider-concurrency:massive');
        $callbackEntered = false;

        try {
            $connection = Redis::connection((string) config('services.massive.concurrency.connection', 'default'));
            $this->ensureCooldownElapsed($connection, $prefix);

            // Preserve the deployed GEX-004 keys and static reservation exactly.
            // Admission does not sleep; a durable caller owns the retry.
            return $connection->funnel("{$prefix}:class:{$priority}:")
                ->limit($classLimit)->releaseAfter($releaseAfter)->block(0)
                ->then(function () use (
                    $callback, $connection, $prefix, $priority, $metricsTtl,
                    $requestKey, $replay, &$callbackEntered
                ): mixed {
                    // Recheck after acquisition in case a concurrent response
                    // established a cooldown while this request sought capacity.
                    $this->ensureCooldownElapsed($connection, $prefix);
                    $this->consumeRateWindow($connection, $prefix, $priority);
                    $this->recordMetric($connection, $prefix, $priority, 'acquired', 0, $metricsTtl);
                    $replay->recordPhysicalRequest();
                    $callbackEntered = true;

                    try {
                        $result = $callback();
                    } catch (ConnectionException $exception) {
                        $message = strtolower($exception->getMessage());
                        $reason = str_contains($message, 'timeout') || str_contains($message, 'timed out')
                            || str_contains($message, 'curl error 28')
                            ? ProviderDeferred::TIMEOUT : ProviderDeferred::NETWORK;
                        $this->recordMetric($connection, $prefix, $priority, $reason, 0, $metricsTtl);

                        throw $this->providerFailure($connection, $prefix, $replay, $reason);
                    } catch (Throwable $exception) {
                        $this->recordMetric($connection, $prefix, $priority, 'provider_exception', 0, $metricsTtl);

                        throw $exception;
                    }

                    if ($result instanceof Response) {
                        $this->recordMetric($connection, $prefix, $priority, 'http_'.$result->status(), 0, $metricsTtl);
                        if (($reason = ProviderDeferred::reasonForHttpStatus($result->status())) !== null) {
                            throw $this->providerFailure(
                                $connection, $prefix, $replay, $reason,
                                $result->header('Retry-After'), $result->status()
                            );
                        }
                        if ($requestKey !== null) {
                            $replay->remember($requestKey, $result);
                        }
                    }
                    $this->recordMetric($connection, $prefix, $priority, 'completed', 0, $metricsTtl);

                    return $result;
                }, function (LimiterTimeoutException $exception) use ($connection, $prefix, $priority, $metricsTtl): never {
                    $this->recordMetric($connection, $prefix, $priority, 'acquire_timeout', 0, $metricsTtl);

                    throw new ProviderDeferred(ProviderDeferred::CAPACITY, $this->localDeadline());
                });
        } catch (ProviderDeferred|InvalidArgumentException|LogicException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! $callbackEntered) {
                // Redis failure cannot authorize an unbounded provider call.
                throw new ProviderDeferred(ProviderDeferred::COORDINATION, $this->localDeadline());
            }

            throw $exception;
        }
    }

    private function ensureCooldownElapsed(mixed $connection, string $prefix): void
    {
        if ($this->localCooldownUntil?->isAfter(CarbonImmutable::now('UTC'))) {
            throw new ProviderDeferred(ProviderDeferred::COOLDOWN, $this->localCooldownUntil);
        }
        $until = $connection->get($prefix.':cooldown-until');
        if ($until === null || $until === false) {
            return;
        }
        if (! is_scalar($until) || ! ctype_digit((string) $until)) {
            throw new \RuntimeException('Provider cooldown state is invalid.');
        }
        $deadline = CarbonImmutable::createFromTimestampUTC((int) $until);
        if ($deadline->isAfter(CarbonImmutable::now('UTC'))) {
            throw new ProviderDeferred(ProviderDeferred::COOLDOWN, $deadline);
        }
    }

    private function providerFailure(
        mixed $connection,
        string $prefix,
        ProviderRequestReplay $replay,
        string $reason,
        mixed $retryAfter = null,
        ?int $httpStatus = null
    ): ProviderDeferred {
        $attempt = max(1, $replay->recordFailure());
        $backoffs = array_values((array) config('provider_backpressure.backoff_seconds', [15, 60, 180]));
        $backoff = max(1, (int) ($backoffs[min($attempt - 1, count($backoffs) - 1)] ?? 180));
        $deadline = ProviderRetryAfter::notBefore(
            $retryAfter, CarbonImmutable::now('UTC'), $backoff, $this->jitter()
        );
        $unix = $deadline->getTimestamp() + ($deadline->micro > 0 ? 1 : 0);
        $local = CarbonImmutable::createFromTimestampUTC($unix);
        if ($this->localCooldownUntil === null || $local->greaterThan($this->localCooldownUntil)) {
            $this->localCooldownUntil = $local;
        }
        try {
            // Concurrent failures can only extend the shared provider deadline.
            $unix = (int) $connection->eval(
                <<<'LUA'
local untilAt = math.max(tonumber(redis.call('GET', KEYS[1]) or '0'), tonumber(ARGV[1]))
local clock = redis.call('TIME')
local now = tonumber(clock[1]) + tonumber(clock[2]) / 1000000
redis.call('SET', KEYS[1], string.format('%.0f', untilAt), 'EX', math.max(1, math.ceil(untilAt - now) + 1))
return untilAt
LUA,
                1, $prefix.':cooldown-until', $unix
            );
        } catch (Throwable) {
            // Preserve this worker's deadline even if reads recover before a
            // failed write. Other workers cannot see an unpersisted deadline.
            try {
                Log::channel('queue_monitor')->error('provider_backpressure.cooldown_write_failed', [
                    'provider' => 'massive',
                    'reason' => $reason,
                    'local_retry_not_before' => $this->localCooldownUntil->toIso8601String(),
                    'other_workers_share_deadline' => false,
                ]);
            } catch (Throwable) {
                // A logging failure must not shorten the provider's deadline.
            }
        }
        $shared = CarbonImmutable::createFromTimestampUTC($unix);
        if ($shared->greaterThan($this->localCooldownUntil)) {
            $this->localCooldownUntil = $shared;
        }

        return new ProviderDeferred($reason, $this->localCooldownUntil, $httpStatus);
    }

    private function consumeRateWindow(mixed $connection, string $prefix, string $priority): void
    {
        $configured = config('provider_backpressure.rate.requests');
        if ($configured === null || $configured === '') {
            return;
        }
        if ((! is_int($configured) && ! (is_string($configured) && ctype_digit($configured)))
            || (int) $configured < 2 || (int) $configured > 100000) {
            throw new InvalidArgumentException('The verified provider request-window allowance is invalid.');
        }
        $window = (int) config('provider_backpressure.rate.window_seconds', 60);
        if ($window < 1 || $window > 3600) {
            throw new InvalidArgumentException('The provider request-window duration is invalid.');
        }
        $limit = $priority === QueueLanes::PRIORITY_INTERACTIVE
            ? intdiv((int) $configured + 1, 2) : intdiv((int) $configured, 2);
        $until = (float) $connection->eval(
            <<<'LUA'
local clock = redis.call('TIME')
local now = tonumber(clock[1]) + tonumber(clock[2]) / 1000000
local window = tonumber(ARGV[1])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - window)
if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) then
    local first = redis.call('ZRANGE', KEYS[1], 0, 0, 'WITHSCORES')
    return tostring(tonumber(first[2]) + window)
end
redis.call('ZADD', KEYS[1], now, ARGV[3])
redis.call('EXPIRE', KEYS[1], window + 1)
return '0'
LUA,
            1, $prefix.':rate:'.$priority, $window, $limit, bin2hex(random_bytes(16))
        );
        if ($until > 0) {
            throw new ProviderDeferred(
                ProviderDeferred::RATE_WINDOW,
                CarbonImmutable::createFromTimestampUTC((int) ceil($until))->addSeconds($this->jitter())
            );
        }
    }

    private function localDeadline(): CarbonImmutable
    {
        return ProviderRetryAfter::notBefore(
            null, CarbonImmutable::now('UTC'),
            max(1, (int) config('provider_backpressure.backoff_seconds.0', 15)),
            $this->jitter()
        );
    }

    private function jitter(): int
    {
        $min = max(0, min(30, (int) config('provider_backpressure.jitter_min_seconds', 1)));
        $max = max($min, min(30, (int) config('provider_backpressure.jitter_max_seconds', 5)));

        return random_int($min, $max);
    }

    private function legacyMassive(callable $callback, ?int $blockForSeconds = null): mixed
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
