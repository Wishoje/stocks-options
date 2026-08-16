<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class CalculatorRefreshState
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Read every catalog state in one cache operation.
     *
     * @param  string[]  $symbols
     * @return array<string, array<string, mixed>|null>
     */
    public function many(array $symbols): array
    {
        $symbols = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $keyToSymbol = [];
        foreach ($symbols as $symbol) {
            $keyToSymbol[$this->stateKey($symbol)] = $symbol;
        }

        $values = $keyToSymbol === [] ? [] : Cache::many(array_keys($keyToSymbol));
        $states = [];

        foreach ($keyToSymbol as $key => $symbol) {
            $state = $values[$key] ?? null;
            $states[$symbol] = is_array($state) ? $state : null;
        }

        return $states;
    }

    /** @return array<string, mixed>|null */
    public function get(string $symbol): ?array
    {
        $state = Cache::get($this->stateKey($symbol));

        return is_array($state) ? $state : null;
    }

    public function isActive(?array $state, CarbonInterface $at): bool
    {
        if (! in_array($state['status'] ?? null, [self::STATUS_PENDING, self::STATUS_STARTED], true)) {
            return false;
        }

        $activeUntil = $this->timestamp($state['active_until'] ?? null);

        return $activeUntil !== null && $activeUntil->greaterThan($at);
    }

    public function isFresh(?array $state, CarbonInterface $cutoff): bool
    {
        if (($state['status'] ?? null) !== self::STATUS_COMPLETED) {
            return false;
        }

        $completedAt = $this->timestamp($state['completed_at'] ?? null);

        return $completedAt !== null && $completedAt->greaterThanOrEqualTo($cutoff);
    }

    public function isCoolingDown(?array $state, CarbonInterface $at): bool
    {
        if (($state['status'] ?? null) !== self::STATUS_FAILED) {
            return false;
        }

        $nextEligibleAt = $this->timestamp($state['next_eligible_at'] ?? null);

        return $nextEligibleAt !== null && $nextEligibleAt->greaterThan($at);
    }

    public function lastSuccessAt(?array $state): ?CarbonImmutable
    {
        return $this->timestamp($state['last_success_at'] ?? null);
    }

    public function lastDispatchedAt(?array $state): ?CarbonImmutable
    {
        return $this->timestamp($state['last_dispatched_at'] ?? null);
    }

    public function claim(
        string $symbol,
        string $generation,
        string $queue,
        CarbonInterface $at,
        ?CarbonInterface $freshCutoff = null
    ): ?string {
        $symbol = Symbols::canon($symbol);
        if ($symbol === '') {
            return null;
        }

        $claimToken = (string) Str::uuid();

        return $this->withLock($symbol, function () use (
            $symbol,
            $generation,
            $claimToken,
            $queue,
            $at,
            $freshCutoff
        ): ?string {
            $previous = $this->get($symbol) ?? [];
            if ($freshCutoff !== null && (
                $this->isActive($previous, $at)
                || $this->isFresh($previous, $freshCutoff)
                || $this->isCoolingDown($previous, $at)
            )) {
                return null;
            }

            $activeUntil = $this->immutable($at)->addSeconds($this->pendingTtlSeconds());
            $claim = [
                'generation' => $generation,
                'claim_token' => $claimToken,
            ];

            if (! Cache::add($this->activeKey($symbol), $claim, $activeUntil)) {
                return null;
            }

            $state = [
                'version' => 1,
                'symbol' => $symbol,
                'scope' => 'catalog',
                'purpose' => 'scheduled',
                'generation' => $generation,
                'claim_token' => $claimToken,
                'status' => self::STATUS_PENDING,
                'queue' => $queue,
                'requested_at' => $this->iso($at),
                'last_dispatched_at' => $this->iso($at),
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'active_until' => $activeUntil->toIso8601String(),
                'attempt' => 0,
                'failure_reason' => null,
                'last_success_at' => $previous['last_success_at'] ?? null,
                'last_success_generation' => $previous['last_success_generation'] ?? null,
                'failure_count' => max(0, (int) ($previous['failure_count'] ?? 0)),
                'next_eligible_at' => null,
            ];

            try {
                Cache::put($this->stateKey($symbol), $state, $this->stateExpiresAt($at));
            } catch (\Throwable $exception) {
                Cache::forget($this->activeKey($symbol));

                throw $exception;
            }

            return $claimToken;
        });
    }

    public function markStarted(
        string $symbol,
        string $generation,
        string $claimToken,
        int $attempt,
        CarbonInterface $at
    ): bool {
        return $this->transition($symbol, $generation, $claimToken, function (array $state) use ($attempt, $at): array {
            $activeUntil = $this->immutable($at)->addSeconds($this->startedTtlSeconds());
            $state['status'] = self::STATUS_STARTED;
            $state['started_at'] ??= $this->iso($at);
            $state['active_until'] = $activeUntil->toIso8601String();
            $state['attempt'] = max(1, $attempt);
            $state['failure_reason'] = null;

            return $state;
        }, [self::STATUS_PENDING, self::STATUS_STARTED], $at, keepActive: true);
    }

    public function markAttemptException(
        string $symbol,
        string $generation,
        string $claimToken,
        int $attempt,
        string $reason,
        CarbonInterface $at
    ): bool {
        return $this->transition($symbol, $generation, $claimToken, function (array $state) use ($attempt, $reason): array {
            $state['attempt'] = max(1, $attempt);
            $state['failure_reason'] = Str::limit($reason, 250, '');

            return $state;
        }, [self::STATUS_STARTED], $at, keepActive: true);
    }

    public function markCompleted(
        string $symbol,
        string $generation,
        string $claimToken,
        CarbonInterface $at
    ): bool {
        return $this->transition($symbol, $generation, $claimToken, function (array $state) use ($generation, $at): array {
            $completedAt = $this->iso($at);
            $state['status'] = self::STATUS_COMPLETED;
            $state['completed_at'] = $completedAt;
            $state['failed_at'] = null;
            $state['active_until'] = null;
            $state['failure_reason'] = null;
            $state['last_success_at'] = $completedAt;
            $state['last_success_generation'] = $generation;
            $state['failure_count'] = 0;
            $state['next_eligible_at'] = null;

            return $state;
        }, [self::STATUS_STARTED], $at);
    }

    public function markFailed(
        string $symbol,
        string $generation,
        string $claimToken,
        string $reason,
        CarbonInterface $at
    ): bool {
        return $this->transition($symbol, $generation, $claimToken, function (array $state) use ($reason, $at): array {
            $failureCount = max(0, (int) ($state['failure_count'] ?? 0)) + 1;
            $cooldown = min(
                $this->failureCooldownMaxSeconds(),
                $this->failureCooldownSeconds() * (2 ** min(10, $failureCount - 1))
            );

            $state['status'] = self::STATUS_FAILED;
            $state['failed_at'] = $this->iso($at);
            $state['active_until'] = null;
            $state['failure_reason'] = Str::limit($reason, 250, '');
            $state['failure_count'] = $failureCount;
            $state['next_eligible_at'] = $this->immutable($at)
                ->addSeconds($cooldown)
                ->toIso8601String();

            return $state;
        }, [self::STATUS_PENDING, self::STATUS_STARTED], $at);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     * @param  string[]  $fromStatuses
     */
    private function transition(
        string $symbol,
        string $generation,
        string $claimToken,
        callable $mutate,
        array $fromStatuses,
        CarbonInterface $at,
        bool $keepActive = false
    ): bool {
        $symbol = Symbols::canon($symbol);

        return $this->withLock($symbol, function () use (
            $symbol,
            $generation,
            $claimToken,
            $mutate,
            $fromStatuses,
            $at,
            $keepActive
        ): bool {
            $active = Cache::get($this->activeKey($symbol));
            if (! is_array($active)
                || ! hash_equals((string) ($active['generation'] ?? ''), $generation)
                || ! hash_equals((string) ($active['claim_token'] ?? ''), $claimToken)) {
                return false;
            }

            $state = $this->get($symbol) ?? [
                'version' => 1,
                'symbol' => $symbol,
                'scope' => 'catalog',
                'purpose' => 'scheduled',
                'generation' => $generation,
                'claim_token' => $claimToken,
            ];

            if (($state['generation'] ?? null) !== $generation
                || ($state['claim_token'] ?? null) !== $claimToken
                || ! in_array($state['status'] ?? null, $fromStatuses, true)) {
                return false;
            }

            $state = $mutate($state);
            if ($keepActive) {
                $state['active_until'] = $this->immutable($at)
                    ->addSeconds($this->startedTtlSeconds())
                    ->toIso8601String();
            }
            Cache::put($this->stateKey($symbol), $state, $this->stateExpiresAt($at));

            if ($keepActive) {
                Cache::put(
                    $this->activeKey($symbol),
                    $active,
                    $this->immutable($at)->addSeconds($this->startedTtlSeconds())
                );
            } else {
                Cache::forget($this->activeKey($symbol));
            }

            return true;
        });
    }

    private function withLock(string $symbol, callable $callback): mixed
    {
        return Cache::lock($this->lockKey($symbol), 10)->block(5, $callback);
    }

    private function pendingTtlSeconds(): int
    {
        return max(43200, (int) config('calculator.scheduler.pending_ttl_seconds', 43200));
    }

    private function startedTtlSeconds(): int
    {
        return max(3600, (int) config('calculator.scheduler.started_ttl_seconds', 3600));
    }

    private function failureCooldownSeconds(): int
    {
        return max(0, (int) config('calculator.scheduler.failure_cooldown_seconds', 300));
    }

    private function failureCooldownMaxSeconds(): int
    {
        return max(
            $this->failureCooldownSeconds(),
            (int) config('calculator.scheduler.failure_cooldown_max_seconds', 3600)
        );
    }

    private function stateExpiresAt(CarbonInterface $at): CarbonImmutable
    {
        $seconds = max(86400, (int) config('calculator.scheduler.state_ttl_seconds', 2592000));

        return $this->immutable($at)->addSeconds($seconds);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function immutable(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at->toIso8601String());
    }

    private function iso(CarbonInterface $at): string
    {
        return $this->immutable($at)->toIso8601String();
    }

    private function stateKey(string $symbol): string
    {
        return 'calculator:refresh-state:v1:catalog:'.Symbols::canon($symbol);
    }

    private function activeKey(string $symbol): string
    {
        return 'calculator:refresh-active:v1:catalog:'.Symbols::canon($symbol);
    }

    private function lockKey(string $symbol): string
    {
        return 'calculator:refresh-lock:v1:catalog:'.Symbols::canon($symbol);
    }
}
