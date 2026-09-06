<?php

namespace App\Support;

use App\Models\WorkRun;
use App\Models\WorkRunSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Durable evidence for completed EOD publications; Redis is not the authority. */
final class EodSnapshotHealth
{
    private ?bool $schemaPresent = null;

    public static function enabled(): bool
    {
        return (bool) config('eod_snapshot_health.enabled', false);
    }

    public static function readsEnabled(): bool
    {
        return self::enabled() && (bool) config('eod_snapshot_health.read_enabled', false);
    }

    public function begin(string $symbol, string $scope, array $metadata = []): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $this->requireSchema();
        $symbol = $this->symbol($symbol);
        if ($scope === '' || strlen($scope) > 4096) {
            throw new InvalidArgumentException('EOD mutation scope is invalid.');
        }
        $safeMetadata = [];
        if (isset($metadata['source']) && is_string($metadata['source'])
            && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $metadata['source'])) {
            $safeMetadata['source'] = $metadata['source'];
        }
        if (isset($metadata['data_date']) && is_string($metadata['data_date'])
            && EodSnapshotManifestBuilder::isDate($metadata['data_date'])) {
            $safeMetadata['data_date'] = $metadata['data_date'];
        }

        return DB::transaction(function () use ($symbol, $scope, $safeMetadata): string {
            DB::table('eod_snapshot_states')->insertOrIgnore(['symbol' => $symbol, 'revision' => 0]);
            $state = DB::table('eod_snapshot_states')->where('symbol', $symbol)->lockForUpdate()->first();
            $revision = (int) $state->revision + 1;
            $token = (string) Str::uuid();
            $now = $this->now();
            DB::table('eod_snapshot_mutations')->insert([
                'id' => $token,
                'symbol' => $symbol,
                'revision' => $revision,
                'scope_hash' => hash('sha256', $scope),
                'status' => 'active',
                'metadata' => EodSnapshotManifestBuilder::canonicalJson($safeMetadata),
                'started_at' => $now,
            ]);
            DB::table('eod_snapshot_states')->where('symbol', $symbol)->update([
                'revision' => $revision, 'mutated_at' => $now,
            ]);

            return $token;
        }, 3);
    }

    public function complete(?string $token): void
    {
        $this->finishMutation($token, true);
    }

    /**
     * Recovery-only idempotency. The caller must hold the recovery slice lock
     * and verify its exact prepared pre/postcondition before calling.
     * A completed receipt may only be reused for a verified no-write resume.
     */
    public function beginRecovery(
        string $symbol,
        string $intentSha,
        string $operation,
        bool $postconditionVerified,
        array $metadata = []
    ): ?string {
        if (! self::enabled()) {
            return null;
        }
        $this->requireSchema();
        $symbol = $this->symbol($symbol);
        if (! preg_match('/^[a-f0-9]{64}$/D', strtolower($intentSha))
            || ! in_array($operation, ['publish', 'rollback'], true)) {
            throw new InvalidArgumentException('EOD recovery mutation identity is invalid.');
        }
        $scope = 'recovery:'.$operation.':'.strtolower($intentSha);
        $key = hash('sha256', $symbol.':'.$scope);

        return DB::transaction(function () use ($symbol, $scope, $key, $metadata, $postconditionVerified): string {
            DB::table('eod_snapshot_states')->insertOrIgnore(['symbol' => $symbol, 'revision' => 0]);
            DB::table('eod_snapshot_states')->where('symbol', $symbol)->lockForUpdate()->first();
            $existing = DB::table('eod_snapshot_mutations')->where('recovery_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === 'complete' && ! $postconditionVerified) {
                    throw new RuntimeException('A completed EOD recovery receipt cannot authorize new raw writes.');
                }
                if (! in_array($existing->status, ['active', 'failed', 'complete'], true)) {
                    throw new RuntimeException('The EOD recovery mutation receipt is not resumable.');
                }
                if ($existing->status === 'failed') {
                    DB::table('eod_snapshot_mutations')->where('id', $existing->id)
                        ->update(['status' => 'active', 'failed_at' => null]);
                }

                return (string) $existing->id;
            }
            $token = $this->begin($symbol, $scope, $metadata);
            DB::table('eod_snapshot_mutations')->where('id', $token)->update(['recovery_key' => $key]);

            return $token;
        }, 3);
    }

    public function fail(?string $token): void
    {
        $this->finishMutation($token, false);
    }

    /** Only observed, fully finished mutations can advance a publication head. */
    public function certify(string $symbol, string $cacheVersion, int $issuedAtMicroseconds): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $this->requireSchema();
        $symbol = $this->symbol($symbol);
        $this->validateVersion($cacheVersion);
        if ($issuedAtMicroseconds < 1) {
            throw new InvalidArgumentException('EOD publication ordering is invalid.');
        }

        return DB::transaction(function () use ($symbol, $cacheVersion, $issuedAtMicroseconds): bool {
            $state = DB::table('eod_snapshot_states')->where('symbol', $symbol)->lockForUpdate()->first();
            if (! $state || (int) $state->revision < 1
                || ! DB::table('eod_snapshot_mutations')->where('symbol', $symbol)->where('status', 'complete')->exists()
                || (int) DB::table('eod_snapshot_mutations')->where('symbol', $symbol)->max('revision') !== (int) $state->revision
                || DB::table('eod_snapshot_mutations')->where('symbol', $symbol)->whereNotIn('status', ['complete', 'superseded'])->exists()) {
                return false;
            }
            if ($state->certified_version !== null) {
                $order = (int) $state->certified_issued_at_microseconds;
                $sameVersion = hash_equals((string) $state->certified_version, $cacheVersion);
                if ($sameVersion) {
                    return (int) $state->certified_revision === (int) $state->revision
                        && $order === $issuedAtMicroseconds;
                }
                if ($order > $issuedAtMicroseconds
                    || ($order === $issuedAtMicroseconds && strcmp((string) $state->certified_version, $cacheVersion) >= 0)) {
                    return false;
                }
            }
            DB::table('eod_snapshot_states')->where('symbol', $symbol)->update([
                'certified_revision' => (int) $state->revision,
                'certified_version' => $cacheVersion,
                'certified_issued_at_microseconds' => $issuedAtMicroseconds,
                'certified_at' => $this->now(),
            ]);

            return true;
        }, 3);
    }

    public function policy(?string $anchorDate = null, ?float $minSideRatio = null): array
    {
        $selector = app(EodSnapshotSelector::class);

        return EodSnapshotManifestBuilder::policy(
            $anchorDate ?? $selector->resolvedAnchorDate(),
            $selector->minSideRatio($minSideRatio)
        );
    }

    /** @return array{symbol:string,revision:int,cache_version:string,issued_at_microseconds:int,dirty:bool}|null */
    public function head(string $symbol): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        try {
            $state = DB::table('eod_snapshot_states')->where('symbol', $this->symbol($symbol))->first();

            return $this->headFromState($state);
        } catch (Throwable) {
            // Reads fall back on unavailable tables without a negative
            // capability cache. A later request can observe a repaired schema.
            return null;
        }
    }

    /** requireCurrent=false is only for an existing last-good response-cache lookup. */
    public function read(string $symbol, array $policy, bool $requireCurrent = true): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        try {
            $policy = EodSnapshotManifestBuilder::normalizePolicy($policy);
            $row = DB::table('eod_snapshot_states as s')
                ->join('eod_snapshot_manifests as m', function ($join): void {
                    $join->on('m.symbol', '=', 's.symbol')
                        ->on('m.revision', '=', 's.certified_revision')
                        ->on('m.cache_version', '=', 's.certified_version');
                })
                ->where('s.symbol', $this->symbol($symbol))
                ->where('m.policy_hash', EodSnapshotManifestBuilder::policyHash($policy))
                ->first(['s.*', 'm.id', 'm.policy_hash', 'm.anchor_date', 'm.policy', 'm.facts', 'm.facts_sha256']);
            $head = $this->headFromState($row);
            if (! $head || ($requireCurrent && $head['dirty'])) {
                return null;
            }
            $facts = $this->validatedFacts($row, $head, $policy);
            if ($facts === null) {
                return null;
            }
            // Detect a concurrent publication or begin() after the initial read.
            $after = $this->head($symbol);
            if ($after === null || $after['revision'] !== $head['revision']
                || $after['cache_version'] !== $head['cache_version']
                || ($requireCurrent && $after['dirty'])) {
                return null;
            }

            return $facts + [
                'manifest_id' => (string) $row->id,
                'policy_hash' => (string) $row->policy_hash,
                'dirty' => $after['dirty'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Coalesce recovery through the existing durable WorkRun slot and reconciler. */
    public function requestRebuild(string $symbol, array $policy): ?WorkRun
    {
        if (! self::enabled() || ! $this->schemaAvailable()) {
            return null;
        }
        try {
            $policy = EodSnapshotManifestBuilder::normalizePolicy($policy);
            $head = $this->head($symbol);
            if (! $head || $head['dirty'] || $this->read($symbol, $policy) !== null) {
                return null;
            }
            $claimed = app(WorkRunCoordinator::class)->claim(
                'eod_manifest_rebuild',
                $head['symbol'],
                ['revision' => $head['revision'], 'cache_version' => $head['cache_version'], 'policy' => $policy],
                QueueLanes::enrichment(),
                provider: 'internal',
                applyAdmissionLimits: false,
                reuseCompleted: false
            );
            $run = $claimed['run'];
            try {
                app(WorkRunDispatcher::class)->dispatch($run);
            } catch (Throwable $exception) {
                // markDispatchFailed leaves the durable intent available to the
                // reconciler. Request fallback must not depend on Redis health.
                $this->warn('eod.manifest.dispatch_failed', ['exception' => $exception::class]);
            }

            return $run;
        } catch (Throwable $exception) {
            $this->warn('eod.manifest.rebuild_unavailable', ['exception' => $exception::class]);

            return null;
        }
    }

    /**
     * Build against a certified revision and publish only after a final locked
     * revision check. The final ownership fence precedes the state-row lock.
     *
     * @param  null|array{run_id:string,delivery_token:string,attempt:int}  $deliveryFence
     */
    public function rebuild(
        string $symbol,
        int $revision,
        string $cacheVersion,
        array $policy,
        ?array $deliveryFence = null,
        ?callable $checkpoint = null
    ): ?array {
        if (! self::enabled() || ! $this->schemaAvailable()) {
            return null;
        }
        $symbol = $this->symbol($symbol);
        $policy = EodSnapshotManifestBuilder::normalizePolicy($policy);
        $this->validateVersion($cacheVersion);

        return DB::transaction(function () use ($symbol, $revision, $cacheVersion, $policy, $deliveryFence, $checkpoint): ?array {
            $state = DB::table('eod_snapshot_states')->where('symbol', $symbol)->first();
            if (! $this->matchesCertified($state, $revision, $cacheVersion)) {
                return null;
            }
            $facts = app(EodSnapshotManifestBuilder::class)->build($symbol, $revision, $cacheVersion, $policy);
            if ($checkpoint !== null) {
                $checkpoint('after_build');
            }
            if ($deliveryFence !== null && ! $this->lockDelivery($deliveryFence, $symbol, $revision, $cacheVersion, $policy)) {
                return null;
            }
            $current = DB::table('eod_snapshot_states')->where('symbol', $symbol)->lockForUpdate()->first();
            if (! $this->matchesCertified($current, $revision, $cacheVersion)) {
                return null;
            }
            $head = ['symbol' => $symbol, 'revision' => $revision, 'cache_version' => $cacheVersion];
            $query = $this->manifestQuery($head, $policy);
            $existing = (clone $query)->lockForUpdate()->first();
            if ($existing && ($valid = $this->validatedFacts($existing, $head, $policy)) !== null) {
                return $valid;
            }
            // Valid materializations are immutable. A corrupt materialization
            // may be replaced atomically from the same unchanged certificate.
            if ($existing) {
                $query->delete();
            }
            $json = EodSnapshotManifestBuilder::canonicalJson($facts);
            DB::table('eod_snapshot_manifests')->insert([
                'id' => (string) Str::uuid(),
                'symbol' => $symbol,
                'revision' => $revision,
                'cache_version' => $cacheVersion,
                'policy_hash' => EodSnapshotManifestBuilder::policyHash($policy),
                'anchor_date' => $policy['anchor_date'],
                'policy' => EodSnapshotManifestBuilder::canonicalJson($policy),
                'facts' => $json,
                'facts_sha256' => hash('sha256', $json),
                'built_at' => $this->now(),
            ]);

            return $facts;
        }, 3);
    }

    private function finishMutation(?string $token, bool $complete): void
    {
        if ($token === null || ! self::enabled()) {
            return;
        }
        $this->requireSchema();
        DB::transaction(function () use ($token, $complete): void {
            $identity = DB::table('eod_snapshot_mutations')->where('id', $token)->first(['symbol']);
            if (! $identity) {
                throw new RuntimeException('EOD mutation fence was not found.');
            }
            DB::table('eod_snapshot_states')->where('symbol', $identity->symbol)->lockForUpdate()->first();
            $mutation = DB::table('eod_snapshot_mutations')->where('id', $token)->lockForUpdate()->first();
            if ($mutation->status !== 'active') {
                if ($complete && $mutation->status === 'failed') {
                    throw new RuntimeException('A failed EOD mutation requires a new successful scoped retry.');
                }

                return;
            }
            DB::table('eod_snapshot_mutations')->where('id', $token)->update([
                'status' => $complete ? 'complete' : 'failed',
                $complete ? 'completed_at' : 'failed_at' => $this->now(),
            ]);
            if ($complete) {
                DB::table('eod_snapshot_mutations')->where('symbol', $mutation->symbol)
                    ->where('scope_hash', $mutation->scope_hash)->where('status', 'failed')
                    ->where('revision', '<', $mutation->revision)
                    ->update(['status' => 'superseded', 'superseded_by' => $token]);
            }
        }, 3);
    }

    private function matchesCertified(?object $state, int $revision, string $version): bool
    {
        return $state !== null && $revision > 0
            && (int) $state->revision === $revision && (int) $state->certified_revision === $revision
            && is_string($state->certified_version) && hash_equals($state->certified_version, $version);
    }

    private function headFromState(?object $state): ?array
    {
        if (! $state || $state->certified_revision === null || (int) $state->certified_revision < 1
            || ! is_string($state->certified_version) || $state->certified_version === ''
            || (int) $state->certified_issued_at_microseconds < 1) {
            return null;
        }

        return [
            'symbol' => (string) $state->symbol,
            'revision' => (int) $state->certified_revision,
            'cache_version' => $state->certified_version,
            'issued_at_microseconds' => (int) $state->certified_issued_at_microseconds,
            'dirty' => (int) $state->revision !== (int) $state->certified_revision,
        ];
    }

    private function manifestQuery(array $head, array $policy): \Illuminate\Database\Query\Builder
    {
        return DB::table('eod_snapshot_manifests')->where('symbol', $head['symbol'])
            ->where('revision', $head['revision'])->where('cache_version', $head['cache_version'])
            ->where('policy_hash', EodSnapshotManifestBuilder::policyHash($policy));
    }

    private function validatedFacts(object $row, array $head, array $policy): ?array
    {
        try {
            $facts = json_decode((string) $row->facts, true, 64, JSON_THROW_ON_ERROR);
            $savedPolicy = json_decode((string) $row->policy, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($facts) || ! is_array($savedPolicy)
                || ! hash_equals((string) $row->facts_sha256, hash('sha256', EodSnapshotManifestBuilder::canonicalJson($facts)))
                || EodSnapshotManifestBuilder::policyHash($savedPolicy) !== EodSnapshotManifestBuilder::policyHash($policy)
                || ($facts['schema_version'] ?? null) !== 1 || ($facts['symbol'] ?? null) !== $head['symbol']
                || ($facts['revision'] ?? null) !== $head['revision'] || ($facts['cache_version'] ?? null) !== $head['cache_version']
                || substr((string) $row->anchor_date, 0, 10) !== $policy['anchor_date']
                || ($facts['data_timestamp_semantics'] ?? null) !== 'legacy_ingestion_or_recovery_timestamp'
                || ! array_key_exists('latest_data_timestamp', $facts)
                || ! $this->validTimestamp($facts['latest_data_timestamp'])
                || ! is_array($facts['policy'] ?? null)
                || EodSnapshotManifestBuilder::policyHash($facts['policy']) !== EodSnapshotManifestBuilder::policyHash($policy)
                || ! is_array($facts['catalog'] ?? null) || ! is_array($facts['expirations'] ?? null)
                || ($facts['expiration_count'] ?? null) !== count($facts['catalog'])
                || count($facts['catalog']) !== count($facts['expirations'])) {
                return null;
            }
            $seen = [];
            foreach ($facts['catalog'] as $expiration) {
                $id = $expiration['expiration_id'] ?? null;
                $date = $expiration['expiration_date'] ?? null;
                $health = $facts['expirations'][$id] ?? null;
                if (! is_int($id) || $id < 1 || isset($seen[$id]) || ! is_string($date)
                    || ! EodSnapshotManifestBuilder::isDate($date) || ! is_array($health)
                    || ($health['expiration_id'] ?? null) !== $id || ($health['expiration_date'] ?? null) !== $date
                    || ! in_array($health['selection_state'] ?? null, ['missing', 'balanced', 'fallback_partial'], true)) {
                    return null;
                }
                foreach (['latest_any_date', 'latest_balanced_date', 'latest_selector_balanced_date', 'selected_date'] as $field) {
                    if (! array_key_exists($field, $health) || ($health[$field] !== null
                        && (! is_string($health[$field]) || ! EodSnapshotManifestBuilder::isDate($health[$field])
                            || $health[$field] > $policy['anchor_date']))) {
                        return null;
                    }
                }
                foreach (['latest_unbounded_date', 'latest_gamma_date'] as $field) {
                    if (! array_key_exists($field, $health) || ($health[$field] !== null
                        && (! is_string($health[$field]) || ! EodSnapshotManifestBuilder::isDate($health[$field])))) {
                        return null;
                    }
                }
                foreach (['latest_data_timestamp', 'selected_data_timestamp'] as $field) {
                    if (! array_key_exists($field, $health) || ! $this->validTimestamp($health[$field])) {
                        return null;
                    }
                }
                foreach (['latest_any_side_ratio', 'selected_side_ratio'] as $field) {
                    if (! array_key_exists($field, $health) || ($health[$field] !== null
                        && ((! is_float($health[$field]) && ! is_int($health[$field]))
                            || ! is_finite((float) $health[$field]) || $health[$field] < 0 || $health[$field] > 1))) {
                        return null;
                    }
                }
                foreach (['latest_any_call_rows', 'latest_any_put_rows', 'latest_any_strike_count',
                    'selected_call_rows', 'selected_put_rows', 'selected_strike_count',
                    'selected_gamma_rows', 'selected_missing_gamma_rows', 'selected_row_count',
                    'latest_any_gamma_rows', 'latest_unbounded_gamma_rows'] as $field) {
                    if (! is_int($health[$field] ?? null) || $health[$field] < 0) {
                        return null;
                    }
                }
                $seen[$id] = true;
            }

            return $facts;
        } catch (Throwable) {
            return null;
        }
    }

    private function lockDelivery(array $fence, string $symbol, int $revision, string $version, array $policy): bool
    {
        $identity = WorkRun::query()->find($fence['run_id'] ?? '', ['slot_key']);
        if (! $identity) {
            return false;
        }
        $slot = WorkRunSlot::query()->whereKey($identity->slot_key)->lockForUpdate()->first();
        if (! $slot || $slot->current_run_id !== ($fence['run_id'] ?? null)) {
            return false;
        }
        $run = WorkRun::query()->lockForUpdate()->find($fence['run_id']);

        return $run !== null && $run->kind === 'eod_manifest_rebuild' && $run->symbol === $symbol
            && $run->status === WorkRun::STATUS_RUNNING && $run->attempt === ($fence['attempt'] ?? null)
            && hash_equals((string) $run->delivery_token, (string) ($fence['delivery_token'] ?? ''))
            && ($run->parameters['revision'] ?? null) === $revision
            && ($run->parameters['cache_version'] ?? null) === $version
            && is_array($run->parameters['policy'] ?? null)
            && EodSnapshotManifestBuilder::policyHash($run->parameters['policy']) === EodSnapshotManifestBuilder::policyHash($policy);
    }

    private function validTimestamp(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_string($value)
            || ! preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?$/D', $value)) {
            return false;
        }

        return EodSnapshotManifestBuilder::isDate(substr($value, 0, 10));
    }

    private function schemaAvailable(): bool
    {
        if ($this->schemaPresent !== null) {
            return $this->schemaPresent;
        }
        try {
            return $this->schemaPresent = Schema::hasTable('eod_snapshot_states')
                && Schema::hasTable('eod_snapshot_mutations') && Schema::hasTable('eod_snapshot_manifests');
        } catch (Throwable) {
            return false;
        }
    }

    private function requireSchema(): void
    {
        if (! $this->schemaAvailable()) {
            throw new RuntimeException('EOD snapshot write tracking is enabled but its durable schema is unavailable.');
        }
    }

    private function symbol(string $symbol): string
    {
        $symbol = Symbols::canon($symbol);
        if (! Symbols::isValid($symbol)) {
            throw new InvalidArgumentException('EOD snapshot symbol is invalid.');
        }

        return $symbol;
    }

    private function validateVersion(string $version): void
    {
        if ($version === '' || $version === 'initial' || strlen($version) > 128) {
            throw new InvalidArgumentException('EOD completed publication version is invalid.');
        }
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }

    private function warn(string $event, array $context): void
    {
        try {
            Log::channel('queue_monitor')->warning($event, $context);
        } catch (Throwable) {
            // Read fallback must not depend on the logging backend.
        }
    }
}
