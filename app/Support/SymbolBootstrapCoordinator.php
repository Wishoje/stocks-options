<?php

namespace App\Support;

use App\Models\SymbolBootstrapExpiration;
use App\Models\SymbolBootstrapHead;
use App\Models\SymbolBootstrapPhase;
use App\Models\SymbolBootstrapRun;
use App\Models\WorkRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class SymbolBootstrapCoordinator
{
    public const PHASE_QUOTE = 'quote';

    public const PHASE_CATALOG = 'catalog';

    public const PHASE_FAST_EOD = 'fast_eod';

    public const PHASE_INTRADAY = 'intraday';

    public const PHASE_FILL = 'fill';

    public const PHASE_ENRICHMENT = 'enrichment';

    public const PHASES = [
        self::PHASE_QUOTE,
        self::PHASE_CATALOG,
        self::PHASE_FAST_EOD,
        self::PHASE_INTRADAY,
        self::PHASE_FILL,
        self::PHASE_ENRICHMENT,
    ];

    public function __construct(private readonly WorkRunCoordinator $workRuns) {}

    public function initialize(WorkRun|string $workRun, ?CarbonInterface $at = null): SymbolBootstrapRun
    {
        $at = $this->at($at);
        $workRunId = $workRun instanceof WorkRun ? $workRun->id : $workRun;

        return DB::transaction(function () use ($workRunId, $at): SymbolBootstrapRun {
            $parent = WorkRun::query()->lockForUpdate()->findOrFail($workRunId);
            if ($parent->kind !== 'symbol_bootstrap') {
                throw new InvalidArgumentException('Only symbol bootstrap work runs can own a bootstrap manifest.');
            }
            if ($parent->provider !== 'massive') {
                throw new InvalidArgumentException('Phased symbol bootstrap requires the Massive provider.');
            }

            $parameters = $parent->parameters ?? [];
            $parameterKeys = array_keys($parameters);
            sort($parameterKeys, SORT_STRING);
            if ($parameterKeys !== ['purpose', 'session_date']) {
                throw new InvalidArgumentException(
                    'A phased bootstrap is keyed only by purpose and session date.'
                );
            }
            $purpose = trim((string) ($parameters['purpose'] ?? SymbolBootstrapPolicy::PURPOSE));
            $sessionDate = $this->date((string) ($parameters['session_date'] ?? ''));
            if ($sessionDate === null || $purpose === '') {
                throw new InvalidArgumentException('A phased bootstrap requires a valid purpose and session date.');
            }

            $policy = app(SymbolBootstrapPolicy::class);
            $fillDays = $policy->fillHorizonDays();
            $fastDays = $policy->fastHorizonDays();
            // The EOD publication stays anchored to the last completed market
            // session. Option snapshots, however, can only fetch contracts that
            // are still live when this run starts. Freeze that separate floor so
            // a Monday bootstrap never asks Massive for Friday-expired options.
            $session = $sessionDate->toDateString();
            $catalogStartDate = $at->setTimezone('America/New_York')->startOfDay();
            $horizonStart = $catalogStartDate->toDateString();
            $horizonEnd = $catalogStartDate->addDays($fillDays)->toDateString();

            $manifest = SymbolBootstrapRun::query()->lockForUpdate()->find($parent->id);
            if ($manifest) {
                if ($manifest->symbol !== $parent->symbol
                    || $manifest->purpose !== $purpose
                    || $manifest->session_date->toDateString() !== $session
                    || $manifest->generation !== $parent->generation) {
                    throw new LogicException('Bootstrap manifest ownership does not match its WorkRun.');
                }

                return $manifest;
            }
            if (! in_array($parent->status, WorkRun::ACTIVE_STATUSES, true)) {
                throw new LogicException('A terminal WorkRun cannot acquire a bootstrap manifest.');
            }

            $manifest = SymbolBootstrapRun::query()->create([
                'work_run_id' => $parent->id,
                'symbol' => $parent->symbol,
                'purpose' => $purpose,
                'session_date' => $session,
                'generation' => $parent->generation,
                'status' => 'preparing',
                'current_phase' => self::PHASE_QUOTE,
                'fast_horizon_days' => $fastDays,
                'fill_horizon_days' => $fillDays,
                'catalog_horizon_start' => $horizonStart,
                'catalog_horizon_end' => $horizonEnd,
                'heartbeat_at' => $at,
            ]);

            foreach (self::PHASES as $phase) {
                SymbolBootstrapPhase::query()->create([
                    'work_run_id' => $parent->id,
                    'phase' => $phase,
                    'status' => $phase === self::PHASE_QUOTE
                        ? SymbolBootstrapPhase::STATUS_PENDING
                        : SymbolBootstrapPhase::STATUS_BLOCKED,
                    'queue_connection' => (string) config('symbol_bootstrap.queue_connection', 'redis'),
                    'queue' => (string) config("symbol_bootstrap.queues.{$phase}", 'default'),
                    'next_dispatch_at' => $phase === self::PHASE_QUOTE ? $at : null,
                ]);
            }

            return $manifest;
        }, 3);
    }

    /**
     * Freeze the terminal provider catalog. An exact retry is idempotent; a
     * different set for the same run is rejected.
     *
     * @param  string[]  $expirations
     * @param  array<string,mixed>  $meta
     */
    public function freezeCatalog(
        string $workRunId,
        string $phaseToken,
        array $expirations,
        array $meta,
        ?CarbonInterface $at = null
    ): SymbolBootstrapRun {
        $at = $this->at($at);

        return DB::transaction(function () use (
            $workRunId,
            $phaseToken,
            $expirations,
            $meta,
            $at
        ): SymbolBootstrapRun {
            $parent = WorkRun::query()->lockForUpdate()->findOrFail($workRunId);
            if ($parent->status !== WorkRun::STATUS_RUNNING) {
                throw new LogicException('The parent WorkRun no longer owns catalog publication.');
            }
            $manifest = SymbolBootstrapRun::query()->lockForUpdate()->findOrFail($workRunId);
            $phase = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->where('phase', self::PHASE_CATALOG)
                ->lockForUpdate()
                ->firstOrFail();
            if ($phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                || ! hash_equals((string) $phase->delivery_token, $phaseToken)) {
                throw new LogicException('The catalog phase no longer owns publication.');
            }
            if (! ($meta['complete'] ?? false) || ($meta['capped'] ?? false)) {
                throw new LogicException('A non-terminal provider catalog cannot be frozen.');
            }

            $dates = $this->normalizeCatalog($manifest, $expirations);
            $hash = hash('sha256', implode("\n", $dates));
            if ($manifest->catalog_frozen_at !== null) {
                if (! hash_equals((string) $manifest->expected_expirations_hash, $hash)) {
                    throw new LogicException('A frozen bootstrap catalog is immutable.');
                }

                return $manifest;
            }

            $fastEnd = $manifest->catalog_horizon_start
                ->addDays($manifest->fast_horizon_days)
                ->toDateString();
            $fastDates = array_values(array_filter(
                $dates,
                static fn (string $date): bool => $date <= $fastEnd
            ));
            // A symbol whose first listed contract is outside the normal fast
            // horizon still needs one useful expiry in its initial view.
            if ($fastDates === [] && $dates !== []) {
                $fastDates = [$dates[0]];
            }
            $fastLookup = array_fill_keys($fastDates, true);
            foreach ($dates as $date) {
                SymbolBootstrapExpiration::query()->create([
                    'work_run_id' => $workRunId,
                    'expiration_date' => $date,
                    'fast_scope' => isset($fastLookup[$date]),
                ]);
            }

            $manifest->catalog_source = substr((string) ($meta['source'] ?? 'massive_reference'), 0, 64);
            $manifest->catalog_source_asof = $at;
            $manifest->expected_expirations_hash = $hash;
            $manifest->expected_count = count($dates);
            $manifest->fast_expected_count = count($fastDates);
            $manifest->catalog_frozen_at = $at;
            $manifest->heartbeat_at = $at;
            $manifest->save();

            return $manifest;
        }, 3);
    }

    /** @return string[] */
    public function expirations(string $workRunId, string $scope): array
    {
        $query = SymbolBootstrapExpiration::query()
            ->where('work_run_id', $workRunId)
            ->orderBy('expiration_date');

        if ($scope === 'fast') {
            $query->where('fast_scope', true);
        } elseif ($scope === 'fill') {
            $query->where('fast_scope', false);
        } elseif ($scope !== 'all') {
            throw new InvalidArgumentException('Unknown bootstrap expiration scope.');
        }

        return $query->pluck('expiration_date')
            ->map(static fn ($date): string => substr((string) $date, 0, 10))
            ->all();
    }

    /**
     * Prove every expected expiration has both call and put rows, then record
     * readiness as one transactionally consistent manifest checkpoint.
     *
     * @return string[]
     */
    public function publishCoverage(
        string $workRunId,
        string $scope,
        string $phaseToken,
        int $attempt,
        ?CarbonInterface $at = null
    ): array {
        $at = $this->at($at);
        $phaseName = match ($scope) {
            'fast' => self::PHASE_FAST_EOD,
            'fill' => self::PHASE_FILL,
            default => throw new InvalidArgumentException('Unknown bootstrap coverage scope.'),
        };

        return DB::transaction(function () use (
            $workRunId,
            $scope,
            $phaseName,
            $phaseToken,
            $attempt,
            $at
        ): array {
            $parent = WorkRun::query()->lockForUpdate()->findOrFail($workRunId);
            if ($parent->status !== WorkRun::STATUS_RUNNING) {
                throw new LogicException('The parent WorkRun no longer owns coverage publication.');
            }
            $manifest = SymbolBootstrapRun::query()->lockForUpdate()->findOrFail($workRunId);
            $phase = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->firstOrFail();
            if ($phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                || $phase->attempt !== $attempt
                || ! hash_equals((string) $phase->delivery_token, $phaseToken)) {
                throw new LogicException('The phase no longer owns coverage publication.');
            }
            if ($manifest->catalog_frozen_at === null) {
                throw new LogicException('Bootstrap coverage requires a frozen catalog.');
            }

            $expirationQuery = SymbolBootstrapExpiration::query()
                ->where('work_run_id', $workRunId)
                ->orderBy('expiration_date');
            if ($scope === 'fast') {
                $expirationQuery->where('fast_scope', true);
            } else {
                $expirationQuery->where('fast_scope', false);
            }
            $expected = $expirationQuery->pluck('expiration_date')
                ->map(static fn ($date): string => substr((string) $date, 0, 10))
                ->all();

            if ($expected !== []) {
                $sides = DB::table('option_chain_data as o')
                    ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                    ->where('e.symbol', $manifest->symbol)
                    ->where('o.data_date', $manifest->session_date->toDateString())
                    ->whereIn('e.expiration_date', $expected)
                    ->whereIn('o.option_type', ['call', 'put'])
                    ->select(['e.expiration_date', 'o.option_type'])
                    ->distinct()
                    ->get()
                    ->groupBy(static fn ($row): string => substr((string) $row->expiration_date, 0, 10));

                foreach ($expected as $expiration) {
                    $types = collect($sides->get($expiration, []))
                        ->pluck('option_type')->unique()->sort()->values()->all();
                    if ($types !== ['call', 'put']) {
                        throw new LogicException("Expiration {$expiration} is not ready on both sides.");
                    }
                }
            }

            $column = $scope === 'fast' ? 'fast_ready_at' : 'fill_ready_at';
            if ($expected !== []) {
                SymbolBootstrapExpiration::query()
                    ->where('work_run_id', $workRunId)
                    ->whereIn('expiration_date', $expected)
                    ->whereNull($column)
                    ->update([$column => $at, 'updated_at' => $at]);
            }
            $manifest->fast_ready_count = (int) SymbolBootstrapExpiration::query()
                ->where('work_run_id', $workRunId)
                ->where('fast_scope', true)
                ->whereNotNull('fast_ready_at')
                ->count();
            $manifest->fill_ready_count = (int) SymbolBootstrapExpiration::query()
                ->where('work_run_id', $workRunId)
                ->where(function ($query): void {
                    $query->where(function ($fast): void {
                        $fast->where('fast_scope', true)->whereNotNull('fast_ready_at');
                    })->orWhere(function ($fill): void {
                        $fill->where('fast_scope', false)->whereNotNull('fill_ready_at');
                    });
                })
                ->count();
            $manifest->heartbeat_at = $at;
            $manifest->save();

            return $expected;
        }, 3);
    }

    /**
     * @param  array{delivery_token:string,attempt:int,orchestration_token:string}|null  $expectedParentFence
     * @return array{
     *     phase:SymbolBootstrapPhase,
     *     delivery_token:string,
     *     parent_delivery_token:string,
     *     parent_attempt:int,
     *     parent_orchestration_token:string
     * }|null
     */
    public function reservePhase(
        string $workRunId,
        string $phaseName,
        ?CarbonInterface $at = null,
        ?array $expectedParentFence = null
    ): ?array {
        $at = $this->at($at);
        $terminalized = false;

        $reserved = DB::transaction(function () use (
            $workRunId,
            $phaseName,
            $at,
            $expectedParentFence,
            &$terminalized
        ): ?array {
            $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
            if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
                return null;
            }
            $parentDeliveryToken = (string) $parent->delivery_token;
            $parentAttempt = (int) $parent->attempt;
            $parentOrchestrationToken = (string) $parent->orchestration_token;
            $phase = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->first();
            $runningExpired = $phase?->status === SymbolBootstrapPhase::STATUS_RUNNING
                && $phase->lease_expires_at !== null
                && ! $phase->lease_expires_at->isAfter($at);
            if (! $phase || $phase->status === SymbolBootstrapPhase::STATUS_BLOCKED
                || $phase->status === SymbolBootstrapPhase::STATUS_COMPLETED
                || ($phase->status === SymbolBootstrapPhase::STATUS_RUNNING && ! $runningExpired)
                || ($phase->next_dispatch_at && $phase->next_dispatch_at->isAfter($at))) {
                return null;
            }

            $reservation = max(30, (int) config('symbol_bootstrap.dispatch_reservation_seconds', 120));
            $pendingDeliveryLive = $phase->status === SymbolBootstrapPhase::STATUS_PENDING
                && $phase->delivery_token !== null
                && $phase->dispatched_at !== null
                && ($phase->lease_expires_at === null || $phase->lease_expires_at->isAfter($at));
            if ($pendingDeliveryLive) {
                return null;
            }
            if ($phase->delivery_token !== null
                && $phase->dispatching_at
                && $phase->dispatching_at->addSeconds($reservation)->isAfter($at)) {
                return null;
            }

            $maxAttempts = max(1, (int) config('symbol_bootstrap.max_phase_attempts', 5));
            if ($phase->dispatch_attempts >= $maxAttempts) {
                $abandonedCode = match (true) {
                    $phase->status === SymbolBootstrapPhase::STATUS_RUNNING => 'running_lease_expired',
                    $phase->dispatched_at !== null => 'pending_lease_expired',
                    $phase->dispatching_at !== null => 'dispatch_reservation_expired',
                    default => 'dispatch_attempts_exhausted',
                };
                $cooldown = max(0, (int) config('symbol_bootstrap.failure_cooldown_seconds', 300));

                $phase->status = SymbolBootstrapPhase::STATUS_FAILED;
                $phase->delivery_token = null;
                $phase->orchestration_token = null;
                $phase->orchestration_attempt = 0;
                $phase->orchestration_reserved_at = null;
                $phase->orchestration_dispatched_at = null;
                $phase->dispatching_at = null;
                $phase->failed_at = $at;
                $phase->heartbeat_at = $at;
                $phase->lease_expires_at = null;
                $phase->retry_not_before = $at->addSeconds($cooldown);
                $phase->next_dispatch_at = null;
                $phase->error_category = 'abandoned';
                $phase->error_code = substr('max_attempts:'.$abandonedCode, 0, 128);
                $phase->save();

                $parent->status = WorkRun::STATUS_FAILED;
                $parent->failed_at = $at;
                $parent->retry_not_before = $at->addSeconds($cooldown);
                $parent->reusable_until = null;
                $parent->heartbeat_at = $at;
                $parent->lease_expires_at = null;
                $parent->error_category = 'bootstrap_abandoned';
                $parent->error_code = substr($phaseName.':'.$abandonedCode, 0, 128);
                $parent->save();
                $terminalized = true;

                return null;
            }

            if ($parentDeliveryToken === ''
                || $parentAttempt < 1
                || $parentOrchestrationToken === ''
                || (int) $parent->orchestration_attempt !== $parentAttempt) {
                return null;
            }
            if ($expectedParentFence !== null
                && (! hash_equals($parentDeliveryToken, (string) ($expectedParentFence['delivery_token'] ?? ''))
                    || $parentAttempt !== (int) ($expectedParentFence['attempt'] ?? 0)
                    || ! hash_equals(
                        $parentOrchestrationToken,
                        (string) ($expectedParentFence['orchestration_token'] ?? '')
                    ))) {
                return null;
            }

            $token = (string) Str::uuid();
            $phase->status = SymbolBootstrapPhase::STATUS_PENDING;
            $phase->delivery_token = $token;
            $phase->orchestration_token = null;
            $phase->orchestration_attempt = 0;
            $phase->orchestration_reserved_at = null;
            $phase->orchestration_dispatched_at = null;
            $phase->dispatch_attempts++;
            $phase->dispatching_at = $at;
            $phase->dispatched_at = null;
            $phase->lease_expires_at = $at->addSeconds(max(
                300,
                (int) config('symbol_bootstrap.pending_lease_seconds', 3600)
            ));
            $phase->next_dispatch_at = null;
            $phase->retry_not_before = null;
            $phase->error_category = null;
            $phase->error_code = null;
            $phase->save();

            // Once a durable phase reservation owns the handoff, keep the
            // parent fence immutable. If this process dies before the queue
            // push, the phase reservation itself is what the reconciler
            // reclaims; replaying the root would only invalidate that handoff.
            $parent->orchestration_dispatched_at ??= $at;
            $parent->heartbeat_at = $at;
            $parent->save();

            return [
                'phase' => $phase,
                'delivery_token' => $token,
                'parent_delivery_token' => $parentDeliveryToken,
                'parent_attempt' => $parentAttempt,
                'parent_orchestration_token' => $parentOrchestrationToken,
            ];
        }, 3);

        if ($terminalized) {
            $this->refreshRunState($workRunId, $at);
        }

        return $reserved;
    }

    public function markPhaseDispatched(
        string $workRunId,
        string $phaseName,
        string $token,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->updatePhaseToken($workRunId, $phaseName, $token, function (SymbolBootstrapPhase $phase) use ($at): void {
            if ($phase->status !== SymbolBootstrapPhase::STATUS_PENDING) {
                throw new LogicException('Phase is not pending dispatch.');
            }
            $phase->dispatched_at ??= $at;
            $phase->dispatching_at = null;
        });
    }

    public function markPhaseDispatchFailed(
        string $workRunId,
        string $phaseName,
        string $token,
        Throwable $exception,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->updatePhaseToken($workRunId, $phaseName, $token, function (SymbolBootstrapPhase $phase) use ($at, $exception): void {
            if ($phase->status !== SymbolBootstrapPhase::STATUS_PENDING) {
                throw new LogicException('Phase is not pending dispatch.');
            }
            $phase->delivery_token = null;
            $phase->dispatching_at = null;
            $phase->dispatched_at = null;
            $phase->lease_expires_at = null;
            $phase->next_dispatch_at = $at->addSeconds(15);
            $phase->error_category = 'dispatch_failed';
            $phase->error_code = substr('dispatch_failed:'.$exception::class, 0, 128);
        });
    }

    public function markPhaseStarted(
        string $workRunId,
        string $phaseName,
        string $token,
        int $attempt,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->updatePhaseToken($workRunId, $phaseName, $token, function (SymbolBootstrapPhase $phase) use ($phaseName, $attempt, $at): void {
            if (! in_array($phase->status, [
                SymbolBootstrapPhase::STATUS_PENDING,
                SymbolBootstrapPhase::STATUS_RUNNING,
            ], true) || ($phase->status === SymbolBootstrapPhase::STATUS_RUNNING && $attempt <= $phase->attempt)) {
                throw new LogicException('Phase delivery is stale.');
            }
            $lease = max(300, (int) config("symbol_bootstrap.running_lease_seconds.{$phaseName}", 1080));
            $phase->status = SymbolBootstrapPhase::STATUS_RUNNING;
            $phase->attempt = max(1, $attempt);
            $phase->started_at ??= $at;
            $phase->heartbeat_at = $at;
            $phase->lease_expires_at = $at->addSeconds($lease);
            $phase->error_category = null;
            $phase->error_code = null;
        });
    }

    /** @param array<string,mixed> $outcome */
    public function markPhaseCompleted(
        string $workRunId,
        string $phaseName,
        string $token,
        int $attempt,
        array $outcome = [],
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);
        try {
            $completed = DB::transaction(function () use (
                $workRunId,
                $phaseName,
                $token,
                $attempt,
                $outcome,
                $at
            ): bool {
                $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
                if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
                    return false;
                }
                $manifest = SymbolBootstrapRun::query()->lockForUpdate()->find($workRunId);
                $phase = SymbolBootstrapPhase::query()
                    ->where('work_run_id', $workRunId)
                    ->where('phase', $phaseName)
                    ->lockForUpdate()
                    ->first();
                if (! $manifest || ! $phase
                    || ! hash_equals((string) $phase->delivery_token, $token)
                    || $phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                    || $phase->attempt !== $attempt) {
                    return false;
                }

                if ($phaseName === self::PHASE_CATALOG && $manifest->catalog_frozen_at === null) {
                    throw new LogicException('The catalog phase cannot complete before its catalog is frozen.');
                }
                if ($phaseName === self::PHASE_FAST_EOD
                    && $manifest->fast_ready_count !== $manifest->fast_expected_count) {
                    throw new LogicException('The fast EOD phase cannot complete before its scope is ready.');
                }
                if ($phaseName === self::PHASE_FILL
                    && $manifest->fill_ready_count !== $manifest->expected_count) {
                    throw new LogicException('The fill phase cannot complete before its full scope is ready.');
                }

                $phase->status = SymbolBootstrapPhase::STATUS_COMPLETED;
                $phase->outcome = $outcome;
                $phase->completed_at = $at;
                $phase->heartbeat_at = $at;
                $phase->lease_expires_at = null;
                $phase->retry_not_before = null;
                $phase->failed_at = null;
                $phase->error_category = null;
                $phase->error_code = null;
                $phase->save();

                // Keep the completed checkpoint and its dependency release in
                // one commit. A killed worker cannot leave completed work with
                // every successor permanently blocked.
                $this->unlockAfter($workRunId, $phaseName, $at);
                $this->refreshRunState($workRunId, $at);

                return true;
            }, 3);
        } catch (LogicException) {
            return false;
        }
        if (! $completed) {
            return false;
        }

        $this->completeIfReady($workRunId, $at);

        return true;
    }

    public function markPhaseFailed(
        string $workRunId,
        string $phaseName,
        string $token,
        int $attempt,
        string $category,
        string $code,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);
        $intradayFallbackQueue = null;
        if ($phaseName === self::PHASE_INTRADAY) {
            $symbol = SymbolBootstrapRun::query()->whereKey($workRunId)->value('symbol');
            if (is_string($symbol) && $symbol !== '') {
                $intradayFallbackQueue = QueueLanes::intraday($symbol);
            }
        }
        $failed = DB::transaction(function () use (
            $workRunId,
            $phaseName,
            $token,
            $attempt,
            $category,
            $code,
            $at,
            $intradayFallbackQueue
        ): bool {
            $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
            if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
                return false;
            }
            $phase = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->first();
            if (! $phase
                || ! hash_equals((string) $phase->delivery_token, $token)
                || $phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                || $phase->attempt !== $attempt) {
                return false;
            }

            $backoffs = array_values((array) config('symbol_bootstrap.retry_backoff_seconds', [15, 60, 180]));
            $backoff = (int) ($backoffs[min(max(0, $phase->dispatch_attempts - 1), count($backoffs) - 1)] ?? 180);
            $maxAttempts = max(1, (int) config('symbol_bootstrap.max_phase_attempts', 5));
            $terminalCategory = in_array($category, [
                'provider_authentication',
                'configuration',
                'validation',
            ], true);
            $terminal = $terminalCategory || $phase->dispatch_attempts >= $maxAttempts;
            $cooldown = max(0, (int) config('symbol_bootstrap.failure_cooldown_seconds', 300));

            $phase->status = SymbolBootstrapPhase::STATUS_FAILED;
            if (! $terminal && $phase->dispatch_attempts === 1 && $intradayFallbackQueue !== null) {
                $phase->queue = $intradayFallbackQueue;
            }
            $phase->delivery_token = null;
            $phase->orchestration_token = null;
            $phase->orchestration_attempt = 0;
            $phase->orchestration_reserved_at = null;
            $phase->orchestration_dispatched_at = null;
            $phase->failed_at = $at;
            $phase->heartbeat_at = $at;
            $phase->lease_expires_at = null;
            $phase->retry_not_before = $at->addSeconds($terminal ? $cooldown : max(5, $backoff));
            $phase->next_dispatch_at = $terminal ? null : $phase->retry_not_before;
            $phase->error_category = substr($category, 0, 64);
            $phase->error_code = substr($code, 0, 128);
            $phase->save();

            if ($terminal) {
                $parent->status = WorkRun::STATUS_FAILED;
                $parent->failed_at = $at;
                $parent->retry_not_before = $at->addSeconds($cooldown);
                $parent->reusable_until = null;
                $parent->heartbeat_at = $at;
                $parent->lease_expires_at = null;
                $parent->error_category = substr('bootstrap_'.$category, 0, 64);
                $parent->error_code = substr($phaseName.':'.$code, 0, 128);
                $parent->save();
            }

            return true;
        }, 3);
        if ($failed) {
            $this->refreshRunState($workRunId, $at);
        }

        return $failed;
    }

    public function isPhaseCurrent(string $workRunId, string $phaseName, string $token): bool
    {
        return SymbolBootstrapPhase::query()
            ->whereHas('bootstrapRun.workRun', fn ($query) => $query->where('status', WorkRun::STATUS_RUNNING))
            ->where('work_run_id', $workRunId)
            ->where('phase', $phaseName)
            ->where('delivery_token', $token)
            ->whereIn('status', [
                SymbolBootstrapPhase::STATUS_PENDING,
                SymbolBootstrapPhase::STATUS_RUNNING,
            ])
            ->exists();
    }

    public function hasDispatchedPhaseOrchestration(
        string $workRunId,
        string $phaseName,
        string $phaseToken
    ): bool {
        return SymbolBootstrapPhase::query()
            ->whereHas('bootstrapRun.workRun', fn ($query) => $query->where('status', WorkRun::STATUS_RUNNING))
            ->where('work_run_id', $workRunId)
            ->where('phase', $phaseName)
            ->where('delivery_token', $phaseToken)
            ->whereNotNull('orchestration_dispatched_at')
            ->exists();
    }

    public function reservePhaseOrchestration(
        string $workRunId,
        string $phaseName,
        string $phaseToken,
        int $attempt,
        ?CarbonInterface $at = null
    ): ?string {
        $at = $this->at($at);

        return DB::transaction(function () use (
            $workRunId,
            $phaseName,
            $phaseToken,
            $attempt,
            $at
        ): ?string {
            $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
            if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
                return null;
            }
            $phase = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->where('phase', $phaseName)
                ->lockForUpdate()
                ->first();
            if (! $phase
                || $phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                || $phase->attempt !== $attempt
                || ! hash_equals((string) $phase->delivery_token, $phaseToken)
                || $phase->orchestration_dispatched_at !== null) {
                return null;
            }

            $reservation = max(30, (int) config('symbol_bootstrap.dispatch_reservation_seconds', 120));
            if ($phase->orchestration_reserved_at
                && $phase->orchestration_reserved_at->addSeconds($reservation)->isAfter($at)) {
                return null;
            }

            $token = (string) Str::uuid();
            $phase->orchestration_token = $token;
            $phase->orchestration_attempt = $attempt;
            $phase->orchestration_reserved_at = $at;
            $phase->heartbeat_at = $at;
            $phase->save();

            return $token;
        }, 3);
    }

    public function markPhaseOrchestrationDispatched(
        string $workRunId,
        string $phaseName,
        string $phaseToken,
        int $attempt,
        string $orchestrationToken,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);

        return $this->updatePhaseToken(
            $workRunId,
            $phaseName,
            $phaseToken,
            function (SymbolBootstrapPhase $phase) use ($attempt, $orchestrationToken, $at): void {
                if ($phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                    || $phase->attempt !== $attempt
                    || $phase->orchestration_attempt !== $attempt
                    || ! hash_equals((string) $phase->orchestration_token, $orchestrationToken)) {
                    throw new LogicException('Phase orchestration confirmation is stale.');
                }
                $phase->orchestration_dispatched_at ??= $at;
                $phase->heartbeat_at = $at;
            }
        );
    }

    public function markPhaseOrchestrationDispatchFailed(
        string $workRunId,
        string $phaseName,
        string $phaseToken,
        int $attempt,
        string $orchestrationToken
    ): bool {
        return $this->updatePhaseToken(
            $workRunId,
            $phaseName,
            $phaseToken,
            function (SymbolBootstrapPhase $phase) use ($attempt, $orchestrationToken): void {
                if ($phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                    || $phase->attempt !== $attempt
                    || ! hash_equals((string) $phase->orchestration_token, $orchestrationToken)) {
                    throw new LogicException('Phase orchestration failure is stale.');
                }
                $phase->orchestration_token = null;
                $phase->orchestration_attempt = 0;
                $phase->orchestration_reserved_at = null;
                $phase->orchestration_dispatched_at = null;
            }
        );
    }

    public function isPhaseOrchestrationCurrent(
        string $workRunId,
        string $phaseName,
        string $phaseToken,
        int $attempt,
        string $orchestrationToken
    ): bool {
        return SymbolBootstrapPhase::query()
            ->whereHas('bootstrapRun.workRun', fn ($query) => $query->where('status', WorkRun::STATUS_RUNNING))
            ->where('work_run_id', $workRunId)
            ->where('phase', $phaseName)
            ->where('status', SymbolBootstrapPhase::STATUS_RUNNING)
            ->where('delivery_token', $phaseToken)
            ->where('attempt', $attempt)
            ->where('orchestration_attempt', $attempt)
            ->where('orchestration_token', $orchestrationToken)
            ->exists();
    }

    public function heartbeatPhaseOrchestration(
        string $workRunId,
        string $phaseName,
        string $phaseToken,
        int $attempt,
        string $orchestrationToken,
        ?CarbonInterface $at = null
    ): bool {
        $at = $this->at($at);
        $updated = $this->updatePhaseToken(
            $workRunId,
            $phaseName,
            $phaseToken,
            function (SymbolBootstrapPhase $phase) use (
                $phaseName,
                $attempt,
                $orchestrationToken,
                $at
            ): void {
                if ($phase->status !== SymbolBootstrapPhase::STATUS_RUNNING
                    || $phase->attempt !== $attempt
                    || $phase->orchestration_attempt !== $attempt
                    || ! hash_equals((string) $phase->orchestration_token, $orchestrationToken)) {
                    throw new LogicException('Phase orchestration heartbeat is stale.');
                }
                $lease = max(300, (int) config("symbol_bootstrap.running_lease_seconds.{$phaseName}", 1080));
                $phase->heartbeat_at = $at;
                $phase->lease_expires_at = $at->addSeconds($lease);
            }
        );
        if ($updated) {
            SymbolBootstrapRun::query()->whereKey($workRunId)->update([
                'heartbeat_at' => $at,
                'updated_at' => $at,
            ]);
        }

        return $updated;
    }

    /** @return Collection<int,SymbolBootstrapPhase> */
    public function dispatchable(int $limit = 100, ?CarbonInterface $at = null): Collection
    {
        $at = $this->at($at);
        $reservationCutoff = $at->subSeconds(max(
            30,
            (int) config('symbol_bootstrap.dispatch_reservation_seconds', 120)
        ));

        return SymbolBootstrapPhase::query()
            ->whereHas('bootstrapRun.workRun', fn ($query) => $query->where('status', WorkRun::STATUS_RUNNING))
            ->where(function ($query) use ($at): void {
                $query->whereIn('status', [
                    SymbolBootstrapPhase::STATUS_PENDING,
                    SymbolBootstrapPhase::STATUS_FAILED,
                ])->orWhere(function ($expired) use ($at): void {
                    $expired->where('status', SymbolBootstrapPhase::STATUS_RUNNING)
                        ->whereNotNull('lease_expires_at')
                        ->where('lease_expires_at', '<=', $at);
                });
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', $at);
            })
            ->where(function ($query) use ($at, $reservationCutoff): void {
                $query->whereNull('delivery_token')
                    ->orWhere(function ($abandonedReservation) use ($reservationCutoff): void {
                        $abandonedReservation
                            ->where('status', SymbolBootstrapPhase::STATUS_PENDING)
                            ->whereNull('dispatched_at')
                            ->whereNotNull('dispatching_at')
                            ->where('dispatching_at', '<=', $reservationCutoff);
                    })
                    ->orWhere(function ($expired) use ($at): void {
                        $expired->whereIn('status', [
                            SymbolBootstrapPhase::STATUS_PENDING,
                            SymbolBootstrapPhase::STATUS_RUNNING,
                        ])
                            ->where('lease_expires_at', '<=', $at);
                    });
            })
            ->orderBy('next_dispatch_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /** Repair an interruption between a committed phase and dependency release. */
    public function reconcileRun(string $workRunId, ?CarbonInterface $at = null): void
    {
        $at = $this->at($at);
        $parent = WorkRun::query()->find($workRunId);
        if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
            return;
        }

        $completed = SymbolBootstrapPhase::query()
            ->where('work_run_id', $workRunId)
            ->where('status', SymbolBootstrapPhase::STATUS_COMPLETED)
            ->pluck('phase');
        foreach ($completed as $phase) {
            $this->unlockAfter($workRunId, (string) $phase, $at);
        }

        $this->refreshRunState($workRunId, $at);
        $this->completeIfReady($workRunId, $at);
    }

    /** @return array<string,mixed>|null */
    public function payload(WorkRun|string $workRun): ?array
    {
        if (! Schema::hasTable('symbol_bootstrap_runs')) {
            return null;
        }
        $workRunId = $workRun instanceof WorkRun ? $workRun->id : $workRun;
        $manifest = SymbolBootstrapRun::query()
            ->with(['workRun', 'phases', 'expirations'])
            ->find($workRunId);
        if (! $manifest) {
            return null;
        }

        $phases = $manifest->phases->keyBy('phase');
        $phasePayload = [];
        foreach (self::PHASES as $name) {
            $phase = $phases->get($name);
            $phasePayload[$name] = $phase ? [
                'status' => $phase->status,
                'queue' => $phase->queue,
                'attempt' => $phase->attempt,
                'dispatch_attempts' => $phase->dispatch_attempts,
                'started_at' => $phase->started_at?->toIso8601String(),
                'completed_at' => $phase->completed_at?->toIso8601String(),
                'failed_at' => $phase->failed_at?->toIso8601String(),
                'error_category' => $phase->error_category,
                'error_code' => $phase->error_code,
                'outcome' => $phase->outcome,
            ] : null;
        }

        $fastReady = collect([self::PHASE_QUOTE, self::PHASE_CATALOG, self::PHASE_FAST_EOD])
            ->every(fn (string $name): bool => $phases->get($name)?->status === SymbolBootstrapPhase::STATUS_COMPLETED);
        $fullReady = $manifest->full_ready_at !== null;
        $noOptions = $manifest->catalog_frozen_at !== null && $manifest->expected_count === 0;
        $terminal = $fullReady || ($manifest->workRun && ! $manifest->workRun->isActive());
        $fastFailed = collect([self::PHASE_QUOTE, self::PHASE_CATALOG, self::PHASE_FAST_EOD])
            ->contains(fn (string $name): bool => $phases->get($name)?->status === SymbolBootstrapPhase::STATUS_FAILED);
        $fillFailed = collect([self::PHASE_INTRADAY, self::PHASE_FILL, self::PHASE_ENRICHMENT])
            ->contains(fn (string $name): bool => $phases->get($name)?->status === SymbolBootstrapPhase::STATUS_FAILED);
        $state = match (true) {
            $fullReady && $noOptions => 'no_options',
            $fullReady => 'full_ready',
            $fastFailed => 'fast_failed',
            $fillFailed => 'fill_failed',
            $fastReady => 'filling',
            $phases->contains(fn (SymbolBootstrapPhase $phase): bool => $phase->status === SymbolBootstrapPhase::STATUS_RUNNING) => 'fast_running',
            default => 'queued',
        };

        $expirations = $manifest->expirations->sortBy('expiration_date')->values();
        $fastDates = $expirations->where('fast_scope', true)->pluck('expiration_date')
            ->map(fn ($date): string => $date->toDateString())->values()->all();
        $fastReadyDates = $expirations->where('fast_scope', true)->whereNotNull('fast_ready_at')
            ->pluck('expiration_date')->map(fn ($date): string => $date->toDateString())->values()->all();
        $filledDates = $expirations->filter(fn (SymbolBootstrapExpiration $expiration): bool => $expiration->fast_scope ? $expiration->fast_ready_at !== null : $expiration->fill_ready_at !== null
        )->pluck('expiration_date')->map(fn ($date): string => $date->toDateString())->values()->all();

        return [
            'run_id' => $manifest->work_run_id,
            'status_url' => route('api.work-runs.show', ['runId' => $manifest->work_run_id]),
            'retry_after_seconds' => $terminal
                ? null
                : max(1, (int) config('work_runs.status_poll_seconds', 2)),
            'terminal' => $terminal,
            'state' => $state,
            'fast_ready' => $fastReady,
            'full_ready' => $fullReady,
            'no_options' => $noOptions,
            'retryable' => ($fastFailed || $fillFailed)
                && (bool) $manifest->workRun?->isActive(),
            'catalog' => [
                'session_date' => $manifest->session_date->toDateString(),
                'generation' => $manifest->generation,
                'frozen_at' => $manifest->catalog_frozen_at?->toIso8601String(),
                'source' => $manifest->catalog_source,
                'expected_expirations' => $manifest->expected_count,
                'fast_expected_expirations' => $manifest->fast_expected_count,
                'horizon_days' => $manifest->fill_horizon_days,
            ],
            'coverage' => [
                'fast_expirations' => $fastDates,
                'fast_ready_expirations' => $fastReadyDates,
                'filled_expirations' => $filledDates,
                'fast_ready_count' => $manifest->fast_ready_count,
                'completed_expirations' => count($filledDates),
                'expected_expirations' => $manifest->expected_count,
            ],
            'phases' => $phasePayload,
        ];
    }

    public function latestForSymbol(
        string $symbol,
        string $sessionDate,
        string $purpose = SymbolBootstrapPolicy::PURPOSE
    ): ?SymbolBootstrapRun {
        return SymbolBootstrapRun::query()
            ->where('symbol', Symbols::canon($symbol))
            ->whereDate('session_date', $sessionDate)
            ->where('purpose', $purpose)
            ->orderByDesc('generation')
            ->first();
    }

    public function authoritativeWorkRun(
        string $symbol,
        string $sessionDate,
        string $purpose = SymbolBootstrapPolicy::PURPOSE
    ): ?WorkRun {
        $runId = SymbolBootstrapHead::query()
            ->where('symbol', Symbols::canon($symbol))
            ->whereDate('session_date', $sessionDate)
            ->where('purpose', $purpose)
            ->value('current_work_run_id');

        return $runId ? WorkRun::query()->find($runId) : null;
    }

    public function completeIfReady(string $workRunId, ?CarbonInterface $at = null): bool
    {
        $at = $this->at($at);

        try {
            return DB::transaction(function () use ($workRunId, $at): bool {
                $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
                $manifest = SymbolBootstrapRun::query()->lockForUpdate()->find($workRunId);
                if (! $parent || ! $manifest) {
                    return false;
                }
                if ($manifest->full_ready_at !== null) {
                    if ($parent->status === WorkRun::STATUS_COMPLETED) {
                        return true;
                    }
                    if ($parent->status !== WorkRun::STATUS_RUNNING) {
                        return false;
                    }

                    return $this->workRuns->markCompleted(
                        $parent->id,
                        (string) $parent->delivery_token,
                        (int) $parent->attempt,
                        $at
                    );
                }
                if ($parent->status !== WorkRun::STATUS_RUNNING) {
                    return false;
                }

                $phaseStatuses = SymbolBootstrapPhase::query()
                    ->where('work_run_id', $workRunId)
                    ->lockForUpdate()
                    ->pluck('status', 'phase');
                foreach (self::PHASES as $phase) {
                    if (($phaseStatuses[$phase] ?? null) !== SymbolBootstrapPhase::STATUS_COMPLETED) {
                        return false;
                    }
                }
                if ($manifest->fill_ready_count !== $manifest->expected_count) {
                    return false;
                }

                $head = SymbolBootstrapHead::query()
                    ->where('symbol', $manifest->symbol)
                    ->whereDate('session_date', $manifest->session_date->toDateString())
                    ->where('purpose', $manifest->purpose)
                    ->lockForUpdate()
                    ->first();
                if ($head && $head->current_generation > $manifest->generation) {
                    $manifest->status = 'superseded';
                    $manifest->heartbeat_at = $at;
                    $manifest->save();
                    if (! $this->workRuns->markCompleted(
                        $parent->id,
                        (string) $parent->delivery_token,
                        (int) $parent->attempt,
                        $at
                    )) {
                        throw new LogicException('The superseded parent WorkRun could not be completed.');
                    }

                    return false;
                }

                if (! $head) {
                    SymbolBootstrapHead::query()->create([
                        'symbol' => $manifest->symbol,
                        'session_date' => $manifest->session_date->toDateString(),
                        'purpose' => $manifest->purpose,
                        'current_work_run_id' => $manifest->work_run_id,
                        'current_generation' => $manifest->generation,
                        'current_full_ready_at' => $at,
                    ]);
                } elseif ($head->current_work_run_id !== $manifest->work_run_id) {
                    $head->previous_work_run_id = $head->current_work_run_id;
                    $head->previous_generation = $head->current_generation;
                    $head->previous_full_ready_at = $head->current_full_ready_at;
                    $head->current_work_run_id = $manifest->work_run_id;
                    $head->current_generation = $manifest->generation;
                    $head->current_full_ready_at = $at;
                    $head->save();
                }

                $manifest->status = 'full_ready';
                $manifest->current_phase = null;
                $manifest->full_ready_at = $at;
                $manifest->heartbeat_at = $at;
                $manifest->save();

                if (! $this->workRuns->markCompleted(
                    $parent->id,
                    (string) $parent->delivery_token,
                    (int) $parent->attempt,
                    $at
                )) {
                    throw new LogicException('The parent WorkRun could not be completed.');
                }

                return true;
            }, 3);
        } catch (LogicException) {
            return false;
        }
    }

    private function unlockAfter(string $workRunId, string $phaseName, CarbonImmutable $at): void
    {
        $next = match ($phaseName) {
            self::PHASE_QUOTE => [self::PHASE_CATALOG],
            self::PHASE_CATALOG => [self::PHASE_FAST_EOD],
            self::PHASE_FAST_EOD => [self::PHASE_INTRADAY, self::PHASE_FILL],
            self::PHASE_FILL => [self::PHASE_ENRICHMENT],
            default => [],
        };
        if ($next === []) {
            return;
        }

        SymbolBootstrapPhase::query()
            ->where('work_run_id', $workRunId)
            ->whereIn('phase', $next)
            ->where('status', SymbolBootstrapPhase::STATUS_BLOCKED)
            ->update([
                'status' => SymbolBootstrapPhase::STATUS_PENDING,
                'next_dispatch_at' => $at,
                'updated_at' => $at,
            ]);
    }

    private function refreshRunState(string $workRunId, CarbonImmutable $at): void
    {
        DB::transaction(function () use ($workRunId, $at): void {
            $manifest = SymbolBootstrapRun::query()->lockForUpdate()->find($workRunId);
            if (! $manifest || $manifest->full_ready_at !== null) {
                return;
            }
            $phases = SymbolBootstrapPhase::query()
                ->where('work_run_id', $workRunId)
                ->get()
                ->keyBy('phase');
            $fastComplete = collect([self::PHASE_QUOTE, self::PHASE_CATALOG, self::PHASE_FAST_EOD])
                ->every(fn (string $phase): bool => $phases->get($phase)?->status === SymbolBootstrapPhase::STATUS_COMPLETED);
            $fastFailed = collect([self::PHASE_QUOTE, self::PHASE_CATALOG, self::PHASE_FAST_EOD])
                ->contains(fn (string $phase): bool => $phases->get($phase)?->status === SymbolBootstrapPhase::STATUS_FAILED);
            $fillFailed = collect([self::PHASE_INTRADAY, self::PHASE_FILL, self::PHASE_ENRICHMENT])
                ->contains(fn (string $phase): bool => $phases->get($phase)?->status === SymbolBootstrapPhase::STATUS_FAILED);
            $manifest->status = $fastFailed ? 'fast_failed' : ($fillFailed ? 'fill_failed' : ($fastComplete ? 'filling' : 'preparing'));
            $manifest->current_phase = optional($phases->first(fn (SymbolBootstrapPhase $phase): bool => in_array($phase->status, [SymbolBootstrapPhase::STATUS_PENDING, SymbolBootstrapPhase::STATUS_RUNNING], true)
            ))->phase;
            $manifest->heartbeat_at = $at;
            $manifest->save();
        }, 3);
    }

    private function updatePhaseToken(
        string $workRunId,
        string $phaseName,
        string $token,
        callable $mutate
    ): bool {
        try {
            return DB::transaction(function () use ($workRunId, $phaseName, $token, $mutate): bool {
                $parent = WorkRun::query()->lockForUpdate()->find($workRunId);
                if (! $parent || $parent->status !== WorkRun::STATUS_RUNNING) {
                    return false;
                }
                $phase = SymbolBootstrapPhase::query()
                    ->where('work_run_id', $workRunId)
                    ->where('phase', $phaseName)
                    ->lockForUpdate()
                    ->first();
                if (! $phase || ! hash_equals((string) $phase->delivery_token, $token)) {
                    return false;
                }
                $mutate($phase);
                $phase->save();

                return true;
            }, 3);
        } catch (LogicException) {
            return false;
        }
    }

    /** @param string[] $expirations @return string[] */
    private function normalizeCatalog(SymbolBootstrapRun $manifest, array $expirations): array
    {
        $start = $manifest->catalog_horizon_start->toDateString();
        $end = $manifest->catalog_horizon_end->toDateString();
        $dates = [];
        foreach ($expirations as $value) {
            $date = $this->date((string) $value);
            if ($date === null || $date->toDateString() < $start || $date->toDateString() > $end) {
                throw new InvalidArgumentException('Catalog expiration is outside the frozen horizon.');
            }
            $dates[$date->toDateString()] = true;
        }
        $dates = array_keys($dates);
        sort($dates, SORT_STRING);

        return $dates;
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'America/New_York');
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === trim($value) ? $date : null;
    }

    private function at(?CarbonInterface $at): CarbonImmutable
    {
        return ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now('UTC'))->utc();
    }
}
