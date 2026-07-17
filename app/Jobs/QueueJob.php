<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Throwable;

/**
 * Shared, conservative defaults for queued application work.
 *
 * Queue-specific exceptions must override these properties explicitly and be
 * listed in config/queue_contracts.php. The identity is diagnostic: it gives
 * retries and failure logs a stable natural key without changing dispatch or
 * coalescing behavior owned by later queue-isolation work.
 */
abstract class QueueJob implements ShouldQueue
{
    public int $timeout = 540;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [15, 60, 180];

    // Leave timed-out jobs reserved until retry_after, then let the queue
    // retry them up to $tries. Setting this true fails on the first timeout.
    public bool $failOnTimeout = false;

    public function withJobTimeout(int $seconds): static
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Job timeout must be at least one second.');
        }

        $this->timeout = $seconds;

        return $this;
    }

    public function idempotencyKey(): string
    {
        return hash('sha256', static::class.'|'.json_encode(
            $this->canonicalize($this->identityPayload()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /** @return string[] */
    public function tags(): array
    {
        $context = $this->queueContext();

        return array_values(array_filter([
            'phase:'.$context['phase'],
            isset($context['symbol']) ? 'symbol:'.$context['symbol'] : null,
            isset($context['symbols']) ? 'symbols:'.implode(',', $context['symbols']) : null,
            'identity:'.$context['idempotency_key'],
        ]));
    }

    /** @return array<string, mixed> */
    public function queueContext(): array
    {
        $payload = $this->identityPayload();
        $symbols = $this->normalizedSymbols($payload);
        $job = property_exists($this, 'job') ? $this->job : null;
        $runId = is_object($job) && method_exists($job, 'uuid') ? $job->uuid() : null;
        $attempt = is_object($job) && method_exists($job, 'attempts') ? $job->attempts() : null;

        $context = [
            'run_id' => $runId,
            'phase' => (new ReflectionClass($this))->getShortName(),
            'attempt' => $attempt,
            'idempotency_key' => $this->idempotencyKey(),
            'connection' => property_exists($this, 'connection') ? $this->connection : null,
            'queue' => property_exists($this, 'queue') ? $this->queue : null,
        ];

        if (count($symbols) === 1) {
            $context['symbol'] = $symbols[0];
        } elseif ($symbols !== []) {
            $context['symbols'] = $symbols;
        }

        if ($runId) {
            $context['replay_command'] = 'php artisan queue:retry '.$runId;
        }

        return array_filter($context, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('queue_monitor')->error('queue.job.terminal_failure', array_merge(
            $this->queueContext(),
            [
                'error_category' => $this->errorCategory($exception),
                'exception' => $exception::class,
            ]
        ));
    }

    /** @return array<string, mixed> */
    protected function identityPayload(): array
    {
        $reflection = new ReflectionClass($this);
        $constructor = $reflection->getConstructor();
        $payload = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            if ($name === 'timeoutSeconds' || ! $reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            if ($property->isInitialized($this)) {
                $payload[$name] = $property->getValue($this);
            }
        }

        return $payload;
    }

    /** @return string[] */
    protected function normalizedSymbols(array $payload): array
    {
        $symbols = [];
        if (isset($payload['symbol']) && is_scalar($payload['symbol'])) {
            $symbols[] = strtoupper(trim((string) $payload['symbol']));
        }
        if (isset($payload['symbols']) && is_array($payload['symbols'])) {
            foreach ($payload['symbols'] as $symbol) {
                if (is_scalar($symbol)) {
                    $symbols[] = strtoupper(trim((string) $symbol));
                }
            }
        }

        $symbols = array_values(array_unique(array_filter($symbols)));
        sort($symbols, SORT_STRING);

        return $symbols;
    }

    protected function errorCategory(Throwable $exception): string
    {
        $haystack = strtolower($exception::class.' '.$exception->getMessage());

        return match (true) {
            str_contains($haystack, 'timeout'), str_contains($haystack, 'timed out') => 'timeout',
            str_contains($haystack, '429'), str_contains($haystack, 'rate limit') => 'provider_rate_limited',
            str_contains($haystack, '401'), str_contains($haystack, '403'), str_contains($haystack, 'unauthorized') => 'provider_authentication',
            str_contains($haystack, 'deadlock'), str_contains($haystack, 'sqlstate') => 'database',
            str_contains($haystack, 'connection'), str_contains($haystack, 'network') => 'network',
            default => 'unexpected',
        };
    }

    protected function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            if (array_reduce($items, static fn (bool $carry, mixed $item): bool => $carry && is_scalar($item), true)) {
                sort($items);
            }

            return $items;
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
