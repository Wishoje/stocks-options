<?php

namespace App\Support;

use App\Exceptions\WorkRunRateLimited;
use App\Models\User;
use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class WorkRunCoordinator
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{run: WorkRun, created: bool, deferred: bool}
     */
    public function claim(
        string $kind,
        string $symbol,
        array $parameters,
        string $queue,
        ?User $requestedBy = null,
        string $provider = 'massive',
        ?CarbonInterface $at = null,
        bool $applyAdmissionLimits = true,
        bool $deferWhenRateLimited = false,
        bool $reuseCompleted = true
    ): array {
        $at = $this->at($at);
        $symbol = Symbols::canon($symbol);
        $parameters = $this->canonicalize($parameters);
        $slotKey = $this->slotKey($kind, (string) $symbol, $parameters, $provider);
        $scopeHash = hash('sha256', json_encode([
            'kind' => $kind,
            'provider' => $provider,
            'symbol' => $symbol,
            'parameters' => $parameters,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use (
            $kind,
            $symbol,
            $parameters,
            $queue,
            $requestedBy,
            $provider,
            $at,
            $slotKey,
            $scopeHash,
            $applyAdmissionLimits,
            $deferWhenRateLimited,
            $reuseCompleted
        ): array {
            $slot = $this->lockedSlot($slotKey, $kind, $provider, $symbol, $parameters);
            $current = $slot->current_run_id
                ? WorkRun::query()->lockForUpdate()->find($slot->current_run_id)
                : null;

            if ($current && $this->isReusable($current, $at, $reuseCompleted)) {
                return ['run' => $current, 'created' => false, 'deferred' => false];
            }

            $deferSeconds = 0;
            if ($applyAdmissionLimits) {
                try {
                    $this->admitAcceptedRun($kind, $symbol, $provider);
                } catch (WorkRunRateLimited $exception) {
                    if (! $deferWhenRateLimited) {
                        throw $exception;
                    }

                    $deferSeconds = max(1, $exception->retryAfterSeconds);
                }
            }

            $generation = ((int) $slot->generation) + 1;
            $pendingTtl = max(3600, (int) config('work_runs.pending_ttl_seconds', 43200));
            $run = WorkRun::query()->create([
                'requested_by_user_id' => $requestedBy?->getKey(),
                'slot_key' => $slotKey,
                'generation' => $generation,
                'kind' => $kind,
                'provider' => $provider,
                'symbol' => $symbol,
                'scope_hash' => $scopeHash,
                'status' => WorkRun::STATUS_PENDING,
                'queue_connection' => (string) config('queue.default', 'redis'),
                'queue' => $queue,
                'parameters' => $parameters,
                'requested_at' => $at,
                'next_dispatch_at' => $at->addSeconds($deferSeconds),
                'lease_expires_at' => $at->addSeconds($pendingTtl),
                'error_category' => $deferSeconds > 0 ? 'admission_deferred' : null,
                'error_code' => $deferSeconds > 0 ? 'accepted_start_budget' : null,
            ]);

            $slot->generation = $generation;
            $slot->current_run_id = $run->id;
            $slot->save();

            return ['run' => $run, 'created' => true, 'deferred' => $deferSeconds > 0];
        }, 3);
    }

    /** @return array{run: WorkRun, delivery_token: string}|null */
    public function reserveDispatch(string $runId, ?CarbonInterface $at = null): ?array
    {
        $at = $this->at($at);

        return DB::transaction(function () use ($runId, $at): ?array {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run || $run->status !== WorkRun::STATUS_PENDING) {
                return null;
            }
            if ($run->next_dispatch_at && $run->next_dispatch_at->isAfter($at)) {
                return null;
            }

            $redispatching = $run->dispatched_at !== null;
            if ($redispatching && (! $run->lease_expires_at || $run->lease_expires_at->isAfter($at))) {
                return null;
            }

            $reservation = max(30, (int) config('work_runs.dispatch_reservation_seconds', 120));
            if ($run->dispatching_at && $run->dispatching_at->addSeconds($reservation)->isAfter($at)) {
                return null;
            }

            $token = (string) Str::uuid();
            $run->delivery_token = $token;
            $run->dispatching_at = $at;
            $run->dispatched_at = null;
            $run->next_dispatch_at = $at;
            $run->lease_expires_at = $at->addSeconds(
                max(3600, (int) config('work_runs.pending_ttl_seconds', 43200))
            );
            $run->dispatch_attempts++;
            $run->save();

            return ['run' => $run, 'delivery_token' => $token];
        });
    }

    public function markDispatched(string $runId, string $deliveryToken, ?CarbonInterface $at = null): bool
    {
        $at = $this->at($at);

        return DB::transaction(function () use ($runId, $deliveryToken, $at): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || ! in_array($run->status, WorkRun::ACTIVE_STATUSES, true)
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)) {
                return false;
            }

            $run->dispatched_at ??= $at;
            $run->dispatching_at = null;
            $run->save();

            return true;
        });
    }

    public function markDispatchFailed(
        string $runId,
        string $deliveryToken,
        Throwable $exception,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return DB::transaction(function () use ($runId, $deliveryToken, $exception, $at): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || $run->status !== WorkRun::STATUS_PENDING
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)) {
                return false;
            }

            $run->delivery_token = null;
            $run->dispatching_at = null;
            $run->next_dispatch_at = $at->addSeconds(max(5, (int) config('work_runs.dispatch_retry_seconds', 15)));
            $run->error_category = 'dispatch_failed';
            $run->error_code = substr('dispatch_failed:'.$exception::class, 0, 128);
            $run->save();

            return true;
        });
    }

    public function markStarted(
        string $runId,
        string $deliveryToken,
        int $attempt,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return DB::transaction(function () use ($runId, $deliveryToken, $attempt, $at): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)
                || ! in_array($run->status, WorkRun::ACTIVE_STATUSES, true)
                || ($run->status === WorkRun::STATUS_RUNNING
                    && $run->orchestration_dispatched_at !== null)
                || ($run->status === WorkRun::STATUS_RUNNING && $attempt <= $run->attempt)) {
                return false;
            }

            $runningTtl = max(300, (int) config("work_runs.running_ttl_seconds.{$run->kind}", 3600));
            $run->status = WorkRun::STATUS_RUNNING;
            $run->attempt = max(1, $attempt);
            $run->started_at ??= $at;
            $run->heartbeat_at = $at;
            $run->lease_expires_at = $at->addSeconds($runningTtl);
            $run->error_category = null;
            $run->error_code = null;
            $run->save();

            return true;
        });
    }

    public function reserveOrchestration(
        string $runId,
        string $deliveryToken,
        int $attempt,
        ?CarbonInterface $at = null
    ): ?string {
        $at = $this->at($at);

        return DB::transaction(function () use ($runId, $deliveryToken, $attempt, $at): ?string {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || $run->status !== WorkRun::STATUS_RUNNING
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)
                || $run->attempt !== $attempt) {
                return null;
            }

            if ($run->orchestration_dispatched_at !== null) {
                return null;
            }

            $reservation = max(30, (int) config('work_runs.dispatch_reservation_seconds', 120));
            if ($run->orchestration_reserved_at
                && $run->orchestration_reserved_at->addSeconds($reservation)->isAfter($at)) {
                return null;
            }

            $token = (string) Str::uuid();
            $run->orchestration_token = $token;
            $run->orchestration_attempt = $attempt;
            $run->orchestration_reserved_at = $at;
            $run->heartbeat_at = $at;
            $run->save();

            return $token;
        });
    }

    public function hasDispatchedOrchestration(string $runId, string $deliveryToken): bool
    {
        return WorkRun::query()
            ->whereKey($runId)
            ->where('delivery_token', $deliveryToken)
            ->whereNotNull('orchestration_dispatched_at')
            ->exists();
    }

    public function markOrchestrationDispatched(
        string $runId,
        string $deliveryToken,
        int $attempt,
        string $orchestrationToken,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return DB::transaction(function () use (
            $runId,
            $deliveryToken,
            $attempt,
            $orchestrationToken,
            $at
        ): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || $run->status !== WorkRun::STATUS_RUNNING
                || $run->attempt !== $attempt
                || $run->orchestration_attempt !== $attempt
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)
                || ! hash_equals((string) $run->orchestration_token, $orchestrationToken)) {
                return false;
            }

            $run->orchestration_dispatched_at ??= $at;
            $run->heartbeat_at = $at;
            $run->save();

            return true;
        });
    }

    public function markOrchestrationDispatchFailed(
        string $runId,
        string $deliveryToken,
        int $attempt,
        string $orchestrationToken
    ): bool {
        return DB::transaction(function () use (
            $runId,
            $deliveryToken,
            $attempt,
            $orchestrationToken
        ): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || $run->status !== WorkRun::STATUS_RUNNING
                || $run->attempt !== $attempt
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)
                || ! hash_equals((string) $run->orchestration_token, $orchestrationToken)) {
                return false;
            }

            $run->orchestration_token = null;
            $run->orchestration_attempt = 0;
            $run->orchestration_reserved_at = null;
            $run->orchestration_dispatched_at = null;
            $run->save();

            return true;
        });
    }

    public function isOrchestrationCurrent(
        string $runId,
        string $deliveryToken,
        int $attempt,
        string $orchestrationToken
    ): bool {
        $run = WorkRun::query()->find($runId);

        return $run !== null
            && $run->status === WorkRun::STATUS_RUNNING
            && $run->attempt === $attempt
            && hash_equals((string) $run->delivery_token, $deliveryToken)
            && hash_equals((string) $run->orchestration_token, $orchestrationToken)
            && $run->orchestration_attempt === $attempt;
    }

    public function markCompleted(
        string $runId,
        string $deliveryToken,
        int $attempt,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->finish($runId, $deliveryToken, $attempt, function (WorkRun $run) use ($at): void {
            $reuse = max(0, (int) config("work_runs.reusable_seconds.{$run->kind}", 0));
            $run->status = WorkRun::STATUS_COMPLETED;
            $run->completed_at = $at;
            $run->reusable_until = $at->addSeconds($reuse);
            $run->retry_not_before = null;
            $run->error_category = null;
            $run->error_code = null;
        }, $at);
    }

    public function markFailed(
        string $runId,
        string $deliveryToken,
        int $attempt,
        string $errorCategory,
        string $errorCode,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->finish($runId, $deliveryToken, $attempt, function (WorkRun $run) use (
            $errorCategory,
            $errorCode,
            $at
        ): void {
            $cooldown = max(0, (int) config('work_runs.failure_cooldown_seconds', 300));
            $run->status = WorkRun::STATUS_FAILED;
            $run->failed_at = $at;
            $run->retry_not_before = $at->addSeconds($cooldown);
            $run->reusable_until = null;
            $run->error_category = substr($errorCategory, 0, 64);
            $run->error_code = substr($errorCode, 0, 128);
        }, $at);
    }

    public function markTerminalException(
        string $runId,
        string $deliveryToken,
        int $attempt,
        Throwable $exception
    ): bool {
        return $this->markFailed(
            $runId,
            $deliveryToken,
            $attempt,
            $this->errorCategory($exception),
            'terminal_exception:'.$exception::class
        );
    }

    public function active(string $kind, string $symbol, array $parameters = [], string $provider = 'massive'): ?WorkRun
    {
        $slotKey = $this->slotKey($kind, $symbol, $parameters, $provider);
        $runId = WorkRunSlot::query()->whereKey($slotKey)->value('current_run_id');
        $run = $runId ? WorkRun::query()->find($runId) : null;

        return $run && $run->isActive() ? $run : null;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, WorkRun> */
    public function dispatchable(int $limit = 100, ?CarbonInterface $at = null)
    {
        $at = $this->at($at);

        return WorkRun::query()
            ->where('status', WorkRun::STATUS_PENDING)
            ->where(function ($query) use ($at): void {
                $query->whereNull('next_dispatch_at')
                    ->orWhere('next_dispatch_at', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('dispatched_at')
                    ->orWhere('lease_expires_at', '<=', $at);
            })
            ->orderBy('requested_at')
            ->limit(max(1, $limit))
            ->get();
    }

    public function markAbandoned(string $runId, ?CarbonInterface $at = null): bool
    {
        $at = $this->at($at);
        $abandonAfter = max(3600, (int) config('work_runs.abandon_after_seconds', 86400));
        $cutoff = $at->subSeconds($abandonAfter);

        return DB::transaction(function () use ($runId, $at, $cutoff): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run || ! $run->isActive() || ! $run->lease_expires_at?->isBefore($at)) {
                return false;
            }

            $lastActivity = $run->heartbeat_at ?? $run->dispatched_at ?? $run->requested_at;
            if (! $lastActivity || $lastActivity->isAfter($cutoff)) {
                return false;
            }

            $run->status = WorkRun::STATUS_FAILED;
            $run->failed_at = $at;
            $run->lease_expires_at = null;
            $run->retry_not_before = $at->addSeconds(
                max(0, (int) config('work_runs.failure_cooldown_seconds', 300))
            );
            $run->error_category = 'abandoned';
            $run->error_code = 'lease_abandoned';
            $run->save();

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function payload(WorkRun $run): array
    {
        $terminal = ! $run->isActive();

        return [
            'run_id' => $run->id,
            'generation' => $run->generation,
            'kind' => $run->kind,
            'symbol' => $run->symbol,
            'status' => $run->status,
            'queue' => $run->queue,
            'parameters' => $run->parameters ?? [],
            'attempt' => $run->attempt,
            'requested_at' => $run->requested_at?->toIso8601String(),
            'dispatched_at' => $run->dispatched_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'failed_at' => $run->failed_at?->toIso8601String(),
            'retry_not_before' => $run->retry_not_before?->toIso8601String(),
            'error_category' => $run->error_category,
            'error_code' => $run->error_code,
            'terminal' => $terminal,
            'retry_after_seconds' => $terminal
                ? null
                : max(1, (int) config('work_runs.status_poll_seconds', 2)),
            'status_url' => route('api.work-runs.show', ['runId' => $run->id]),
        ];
    }

    private function lockedSlot(
        string $key,
        string $kind,
        string $provider,
        ?string $symbol,
        array $parameters
    ): WorkRunSlot {
        $slot = WorkRunSlot::query()->lockForUpdate()->find($key);
        if ($slot) {
            return $slot;
        }

        try {
            WorkRunSlot::query()->create([
                'key' => $key,
                'kind' => $kind,
                'provider' => $provider,
                'symbol' => $symbol,
                'parameters' => $parameters,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
        }

        return WorkRunSlot::query()->lockForUpdate()->findOrFail($key);
    }

    private function isReusable(WorkRun $run, CarbonInterface $at, bool $reuseCompleted): bool
    {
        if ($run->isActive()) {
            return true;
        }
        if ($run->status === WorkRun::STATUS_COMPLETED) {
            return $reuseCompleted
                && $run->reusable_until !== null
                && $run->reusable_until->isAfter($at);
        }
        if ($run->status === WorkRun::STATUS_FAILED) {
            return $run->retry_not_before !== null && $run->retry_not_before->isAfter($at);
        }

        return false;
    }

    private function admitAcceptedRun(string $kind, ?string $symbol, string $provider): void
    {
        $limits = [
            [
                'key' => "accepted-work:symbol:{$kind}:{$symbol}",
                'max' => max(1, (int) config('work_runs.rate_limits.accepted_symbol_per_minute', 12)),
            ],
            [
                'key' => "accepted-work:provider:{$provider}",
                'max' => max(1, (int) config('work_runs.rate_limits.accepted_provider_per_minute', 120)),
            ],
        ];

        foreach ($limits as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                throw new WorkRunRateLimited(max(1, RateLimiter::availableIn($limit['key'])));
            }
        }
        foreach ($limits as $limit) {
            RateLimiter::hit($limit['key'], 60);
        }
    }

    private function finish(
        string $runId,
        string $deliveryToken,
        int $attempt,
        callable $callback,
        CarbonInterface $at
    ): bool {
        return DB::transaction(function () use ($runId, $deliveryToken, $attempt, $callback, $at): bool {
            $run = WorkRun::query()->lockForUpdate()->find($runId);
            if (! $run
                || $run->status !== WorkRun::STATUS_RUNNING
                || ! hash_equals((string) $run->delivery_token, $deliveryToken)
                || $run->attempt !== $attempt) {
                return false;
            }

            $callback($run);
            $run->heartbeat_at = $at;
            $run->lease_expires_at = null;
            $run->save();

            return true;
        });
    }

    private function slotKey(string $kind, string $symbol, array $parameters, string $provider): string
    {
        $payload = $this->canonicalize([
            'kind' => $kind,
            'provider' => $provider,
            'symbol' => Symbols::canon($symbol),
            'parameters' => $parameters,
        ]);

        return hash('sha256', 'work-run-slot:v1|'.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    private function at(?CarbonInterface $at): CarbonImmutable
    {
        return $at
            ? CarbonImmutable::parse($at->toIso8601String())->utc()
            : now('UTC')->toImmutable();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true);
    }

    private function errorCategory(Throwable $exception): string
    {
        $haystack = strtolower($exception::class.' '.$exception->getMessage());

        return match (true) {
            str_contains($haystack, 'timeout'), str_contains($haystack, 'timed out') => 'timeout',
            str_contains($haystack, '429'), str_contains($haystack, 'rate limit') => 'provider_rate_limited',
            str_contains($haystack, '401'), str_contains($haystack, '403') => 'provider_authentication',
            str_contains($haystack, 'deadlock'), str_contains($haystack, 'sqlstate') => 'database',
            str_contains($haystack, 'connection'), str_contains($haystack, 'network') => 'network',
            default => 'unexpected',
        };
    }

    private function canonicalize(mixed $value): mixed
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
