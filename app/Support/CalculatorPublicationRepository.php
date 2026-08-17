<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class CalculatorPublicationRepository
{
    public const SCOPE_CATALOG = 'catalog';

    public const SCOPE_SELECTED_EXPIRY = 'selected_expiry';

    private const ROW_INSERT_CHUNK = 100;

    /**
     * Allocate an independent, monotonic calculator generation for a full-catalog run.
     *
     * @return array<string, mixed>
     */
    public function startCatalogRun(
        string $symbol,
        ?string $ownerKey = null,
        string $purpose = 'full_catalog',
        ?string $workRunId = null,
        ?DateTimeInterface $at = null
    ): array {
        return $this->startRun(
            $symbol,
            self::SCOPE_CATALOG,
            $purpose,
            null,
            $ownerKey,
            $workRunId,
            $at
        );
    }

    /**
     * Allocate a selected-expiry run. This scope can publish only its expiry head.
     *
     * @return array<string, mixed>
     */
    public function startSelectedExpiryRun(
        string $symbol,
        string $expiration,
        ?string $ownerKey = null,
        string $purpose = 'selected_expiry',
        ?string $workRunId = null,
        ?DateTimeInterface $at = null
    ): array {
        return $this->startRun(
            $symbol,
            self::SCOPE_SELECTED_EXPIRY,
            $purpose,
            $this->expiration($expiration),
            $ownerKey,
            $workRunId,
            $at
        );
    }

    /**
     * Freeze the authoritative catalog only after provider discovery reaches its terminal cursor.
     *
     * @param  list<string>  $expectedExpirations
     * @return array<string, mixed>
     */
    public function freezeCatalog(
        string $runId,
        array $expectedExpirations,
        string $catalogSource,
        DateTimeInterface $catalogSourceAsOf,
        bool $terminalCursorReached,
        ?string $discoveryHorizon = null,
        int $catalogPrecedence = 100,
        ?DateTimeInterface $at = null
    ): array {
        $expected = collect($expectedExpirations)
            ->map(fn (mixed $expiration): string => $this->expiration((string) $expiration))
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (! $terminalCursorReached) {
            throw new LogicException(
                'Calculator catalog membership cannot freeze before the terminal discovery cursor.'
            );
        }
        $source = $this->requiredText($catalogSource, 64, 'Catalog source');
        $sourceAsOf = $this->dateTime($catalogSourceAsOf);
        $horizon = $discoveryHorizon === null ? null : $this->expiration($discoveryHorizon);
        $precedence = max(0, min(65535, $catalogPrecedence));
        $frozenAt = $this->dateTime($at);
        $hash = hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $runId,
            $expected,
            $source,
            $sourceAsOf,
            $horizon,
            $precedence,
            $frozenAt,
            $hash
        ): array {
            $run = $this->lockedRun($runId);
            $this->requireScope($run, self::SCOPE_CATALOG);

            if ($run->expected_frozen_at !== null) {
                $same = hash_equals((string) $run->expected_expirations_hash, $hash)
                    && (string) $run->catalog_source === $source
                    && $this->sameDateTime($run->catalog_source_asof, $sourceAsOf)
                    && ($run->discovery_horizon === null ? null : (string) $run->discovery_horizon) === $horizon;

                if (! $same) {
                    throw new LogicException('A frozen calculator catalog cannot be changed.');
                }

                return $this->run($runId);
            }

            if (in_array((string) $run->status, ['failed', 'capped', 'complete', 'superseded'], true)) {
                throw new LogicException('A terminal calculator run cannot freeze a catalog.');
            }

            $rows = array_map(fn (string $expiration): array => [
                'run_id' => $runId,
                'symbol' => (string) $run->symbol,
                'expiration' => $expiration,
                'catalog_source' => $source,
                'catalog_precedence' => $precedence,
                'readiness' => 'pending',
                'publication_id' => null,
                'source_asof' => null,
                'failure_code' => null,
                'failure_reason' => null,
                'discovered_at' => $frozenAt,
                'last_seen_at' => $frozenAt,
                'ready_at' => null,
                'failed_at' => null,
                'created_at' => $frozenAt,
                'updated_at' => $frozenAt,
            ], $expected);

            if ($rows !== []) {
                DB::table('calculator_run_expirations')->insert($rows);
            }

            DB::table('calculator_publication_runs')->where('id', $runId)->update([
                'status' => 'preparing',
                'discovery_terminal' => true,
                'discovery_capped' => false,
                'catalog_source' => $source,
                'catalog_source_asof' => $sourceAsOf,
                'discovery_horizon' => $horizon,
                'expected_expirations_hash' => $hash,
                'expected_count' => count($expected),
                'expected_frozen_at' => $frozenAt,
                'heartbeat_at' => $frozenAt,
                'updated_at' => $frozenAt,
            ]);

            return $this->run($runId);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function markCapped(
        string $runId,
        string $reason = 'discovery_cursor_capped',
        ?DateTimeInterface $at = null
    ): array {
        return $this->finishRun($runId, 'capped', 'discovery_capped', $reason, true, $at);
    }

    /** @return array<string, mixed> */
    public function markRunFailed(
        string $runId,
        string $failureCode,
        string $reason,
        ?DateTimeInterface $at = null
    ): array {
        return $this->finishRun($runId, 'failed', $failureCode, $reason, false, $at);
    }

    public function heartbeat(string $runId, ?DateTimeInterface $at = null): bool
    {
        $heartbeatAt = $this->dateTime($at);

        return DB::transaction(function () use ($runId, $heartbeatAt): bool {
            $run = $this->lockedRun($runId);
            if (in_array((string) $run->status, ['failed', 'capped', 'complete', 'superseded'], true)) {
                return false;
            }

            DB::table('calculator_publication_runs')->where('id', $runId)->update([
                'heartbeat_at' => $heartbeatAt,
                'updated_at' => $heartbeatAt,
            ]);

            return true;
        }, 3);
    }

    /**
     * Commit immutable rows and advance the expiry pointer in one transaction.
     *
     * @param  list<array{ticker?:string|null,type:string,strike:int|float|string,bid?:int|float|string|null,ask?:int|float|string|null,mid?:int|float|string|null,implied_volatility?:int|float|string|null}>  $rows
     * @return array{publication_id:string,head_advanced:bool,idempotent:bool,run:array<string,mixed>}
     */
    public function stageAndPublishExpiry(
        string $runId,
        string $expiration,
        string $chainSource,
        DateTimeInterface $sourceAsOf,
        DateTimeInterface $snapshotAt,
        array $rows,
        ?DateTimeInterface $at = null
    ): array {
        $expiration = $this->expiration($expiration);
        $source = $this->requiredText($chainSource, 64, 'Chain source');
        $sourceAsOfValue = $this->dateTime($sourceAsOf);
        $snapshotAtValue = $this->dateTime($snapshotAt);
        $createdAt = $this->dateTime($at);
        $normalizedRows = $this->normalizeRows($rows);
        if ($normalizedRows === []) {
            throw new InvalidArgumentException('A ready expiration publication must contain at least one row.');
        }
        $contentHash = hash('sha256', json_encode($normalizedRows, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $runId,
            $expiration,
            $source,
            $sourceAsOfValue,
            $snapshotAtValue,
            $createdAt,
            $normalizedRows,
            $contentHash
        ): array {
            $run = $this->lockedRun($runId);
            if ((string) $run->scope === self::SCOPE_CATALOG) {
                if (! (bool) $run->discovery_terminal || (bool) $run->discovery_capped || $run->expected_frozen_at === null) {
                    throw new LogicException('A full-catalog run must freeze terminal discovery before staging.');
                }
            } elseif ((string) $run->scope === self::SCOPE_SELECTED_EXPIRY) {
                if ((string) $run->requested_expiry !== $expiration) {
                    throw new LogicException('A selected-expiry run cannot publish a different expiration.');
                }
            } else {
                throw new LogicException('Unknown calculator publication scope.');
            }

            $readiness = DB::table('calculator_run_expirations')
                ->where('run_id', $runId)
                ->where('expiration', $expiration)
                ->lockForUpdate()
                ->first();
            if (! $readiness) {
                throw new LogicException('The expiration is not part of the frozen run catalog.');
            }

            $existing = DB::table('calculator_expiry_publications')
                ->where('run_id', $runId)
                ->where('expiration', $expiration)
                ->first();
            if ($existing) {
                if (
                    ! hash_equals((string) $existing->content_hash, $contentHash)
                    || (string) $existing->chain_source !== $source
                    || ! $this->sameDateTime($existing->source_asof, $sourceAsOfValue)
                    || ! $this->sameDateTime($existing->snapshot_at, $snapshotAtValue)
                ) {
                    throw new LogicException('An immutable expiration publication cannot be replaced.');
                }

                $head = DB::table('calculator_expiry_heads')
                    ->where('symbol', $run->symbol)
                    ->where('expiration', $expiration)
                    ->first();

                return [
                    'publication_id' => (string) $existing->id,
                    'head_advanced' => $head && (string) $head->current_publication_id === (string) $existing->id,
                    'idempotent' => true,
                    'run' => $this->run($runId),
                ];
            }

            if (in_array((string) $run->status, ['failed', 'capped', 'complete', 'superseded'], true)) {
                throw new LogicException('A terminal calculator run cannot stage an expiration.');
            }

            $publicationId = (string) Str::uuid();
            DB::table('calculator_expiry_publications')->insert([
                'id' => $publicationId,
                'run_id' => $runId,
                'symbol' => (string) $run->symbol,
                'generation' => (int) $run->generation,
                'expiration' => $expiration,
                'chain_source' => $source,
                'source_asof' => $sourceAsOfValue,
                'snapshot_at' => $snapshotAtValue,
                'row_count' => count($normalizedRows),
                'content_hash' => $contentHash,
                'created_at' => $createdAt,
            ]);

            foreach (array_chunk($normalizedRows, self::ROW_INSERT_CHUNK) as $chunk) {
                DB::table('calculator_expiry_publication_rows')->insert(array_map(
                    fn (array $row): array => ['publication_id' => $publicationId] + $row,
                    $chunk
                ));
            }

            $headAdvanced = $this->advanceExpiryHead(
                (string) $run->symbol,
                $expiration,
                $publicationId,
                (int) $run->generation,
                $sourceAsOfValue,
                $createdAt
            );

            DB::table('calculator_run_expirations')->where('id', $readiness->id)->update([
                'readiness' => 'ready',
                'publication_id' => $publicationId,
                'source_asof' => $sourceAsOfValue,
                'failure_code' => null,
                'failure_reason' => null,
                'ready_at' => $createdAt,
                'failed_at' => null,
                'updated_at' => $createdAt,
            ]);

            [$completed, $failed] = $this->readinessCounts($runId);
            $runUpdate = [
                'completed_count' => $completed,
                'failed_count' => $failed,
                'heartbeat_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
            if ((string) $run->scope === self::SCOPE_SELECTED_EXPIRY) {
                $runUpdate['status'] = $headAdvanced ? 'complete' : 'superseded';
                $runUpdate['completed_at'] = $createdAt;
            } else {
                $runUpdate['status'] = $completed > 0 ? 'partial' : 'preparing';
            }
            DB::table('calculator_publication_runs')->where('id', $runId)->update($runUpdate);

            return [
                'publication_id' => $publicationId,
                'head_advanced' => $headAdvanced,
                'idempotent' => false,
                'run' => $this->run($runId),
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function markExpiryFailed(
        string $runId,
        string $expiration,
        string $failureCode,
        string $reason,
        ?DateTimeInterface $at = null
    ): array {
        $expiration = $this->expiration($expiration);
        $failedAt = $this->dateTime($at);
        $code = $this->requiredText($failureCode, 64, 'Failure code');
        $safeReason = $this->text($reason, 255);

        return DB::transaction(function () use ($runId, $expiration, $failedAt, $code, $safeReason): array {
            $run = $this->lockedRun($runId);
            if (in_array((string) $run->status, ['failed', 'capped', 'complete', 'superseded'], true)) {
                throw new LogicException('A terminal calculator run cannot record an expiry failure.');
            }

            $readiness = DB::table('calculator_run_expirations')
                ->where('run_id', $runId)
                ->where('expiration', $expiration)
                ->lockForUpdate()
                ->first();
            if (! $readiness) {
                throw new LogicException('The expiration is not part of this run.');
            }
            if ((string) $readiness->readiness === 'ready') {
                throw new LogicException('A ready immutable expiration cannot be marked failed.');
            }

            DB::table('calculator_run_expirations')->where('id', $readiness->id)->update([
                'readiness' => 'failed',
                'failure_code' => $code,
                'failure_reason' => $safeReason,
                'failed_at' => $failedAt,
                'updated_at' => $failedAt,
            ]);
            [$completed, $failed] = $this->readinessCounts($runId);
            $selected = (string) $run->scope === self::SCOPE_SELECTED_EXPIRY;
            DB::table('calculator_publication_runs')->where('id', $runId)->update([
                'status' => $selected ? 'failed' : ($completed > 0 ? 'partial' : 'preparing'),
                'completed_count' => $completed,
                'failed_count' => $failed,
                'failure_code' => $selected ? $code : null,
                'failure_reason' => $selected ? $safeReason : null,
                'heartbeat_at' => $failedAt,
                'completed_at' => $selected ? $failedAt : null,
                'updated_at' => $failedAt,
            ]);

            return $this->run($runId);
        }, 3);
    }

    /**
     * Advance the complete catalog only when every frozen expiration is ready.
     *
     * @return array{advanced:bool,idempotent:bool,reason:string|null,run:array<string,mixed>}
     */
    public function completeCatalog(string $runId, ?DateTimeInterface $at = null): array
    {
        $completedAt = $this->dateTime($at);

        return DB::transaction(function () use ($runId, $completedAt): array {
            $run = $this->lockedRun($runId);
            $this->requireScope($run, self::SCOPE_CATALOG);

            $head = DB::table('calculator_catalog_heads')
                ->where('symbol', $run->symbol)
                ->lockForUpdate()
                ->first();
            if ((string) $run->status === 'complete') {
                return [
                    'advanced' => $head && (string) $head->current_run_id === $runId,
                    'idempotent' => true,
                    'reason' => null,
                    'run' => $this->run($runId),
                ];
            }
            if ((string) $run->status === 'superseded') {
                return [
                    'advanced' => false,
                    'idempotent' => true,
                    'reason' => 'superseded',
                    'run' => $this->run($runId),
                ];
            }
            if ((bool) $run->discovery_capped || (string) $run->status === 'capped') {
                return $this->completionRejected($runId, 'capped');
            }
            if ((string) $run->status === 'failed') {
                return $this->completionRejected($runId, 'failed');
            }
            if (! (bool) $run->discovery_terminal || $run->expected_frozen_at === null) {
                return $this->completionRejected($runId, 'discovery_not_terminal');
            }

            [$completed, $failed] = $this->readinessCounts($runId);
            $expected = (int) $run->expected_count;
            if ($completed !== $expected || $failed !== 0) {
                $terminalFailure = $failed > 0;
                $update = [
                    'status' => $terminalFailure ? 'failed' : ($completed > 0 ? 'partial' : 'preparing'),
                    'completed_count' => $completed,
                    'failed_count' => $failed,
                    'heartbeat_at' => $completedAt,
                    'updated_at' => $completedAt,
                ];
                if ($terminalFailure) {
                    $update += [
                        'failure_code' => 'expiration_failed',
                        'failure_reason' => 'One or more expected calculator expirations failed.',
                        'completed_at' => $completedAt,
                    ];
                }
                DB::table('calculator_publication_runs')->where('id', $runId)->update($update);

                return $this->completionRejected($runId, 'expirations_not_ready');
            }
            if ($expected === 0) {
                $currentCatalogNonempty = false;
                if ($head) {
                    $currentExpected = DB::table('calculator_publication_runs')
                        ->where('id', $head->current_run_id)
                        ->value('expected_count');
                    if ($currentExpected === null) {
                        throw new RuntimeException('Calculator catalog head references a missing run.');
                    }
                    $currentCatalogNonempty = (int) $currentExpected > 0;
                }

                $exchangeDate = CarbonImmutable::parse($completedAt, 'UTC')
                    ->setTimezone('America/New_York')
                    ->toDateString();
                $publishedExpiryExists = ! $currentCatalogNonempty
                    && DB::table('calculator_expiry_heads')
                        ->where('symbol', $run->symbol)
                        ->where('expiration', '>=', $exchangeDate)
                        ->orderBy('expiration')
                        ->lockForUpdate()
                        ->value('id') !== null;
                $legacySnapshotExists = ! $currentCatalogNonempty
                    && ! $publishedExpiryExists
                    && DB::table('option_snapshots')
                        ->where('symbol', $run->symbol)
                        ->where('expiry', '>=', $exchangeDate)
                        ->orderBy('expiry')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->value('id') !== null;
                $eodExpirationExists = ! $currentCatalogNonempty
                    && ! $publishedExpiryExists
                    && ! $legacySnapshotExists
                    && DB::table('option_expirations')
                        ->where('symbol', $run->symbol)
                        ->where('expiration_date', '>=', $exchangeDate)
                        ->orderBy('expiration_date')
                        ->lockForUpdate()
                        ->value('id') !== null;
                if ($currentCatalogNonempty
                    || $publishedExpiryExists
                    || $legacySnapshotExists
                    || $eodExpirationExists
                ) {
                    DB::table('calculator_publication_runs')->where('id', $runId)->update([
                        'status' => 'failed',
                        'completed_count' => 0,
                        'failed_count' => 0,
                        'failure_code' => 'provider_empty_after_nonempty',
                        'failure_reason' => 'An empty calculator catalog cannot replace current or previously published nonempty calculator evidence.',
                        'completed_at' => $completedAt,
                        'heartbeat_at' => $completedAt,
                        'updated_at' => $completedAt,
                    ]);

                    return $this->completionRejected($runId, 'provider_empty_after_nonempty');
                }
            }

            $sourceAsOf = $this->dateTimeValue($run->catalog_source_asof);
            $advanced = $this->advanceCatalogHead(
                (string) $run->symbol,
                $runId,
                (int) $run->generation,
                $sourceAsOf,
                $completedAt,
                $head
            );
            DB::table('calculator_publication_runs')->where('id', $runId)->update([
                'status' => $advanced ? 'complete' : 'superseded',
                'completed_count' => $completed,
                'failed_count' => 0,
                'completed_at' => $completedAt,
                'heartbeat_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

            return [
                'advanced' => $advanced,
                'idempotent' => false,
                'reason' => $advanced ? null : 'older_than_catalog_head',
                'run' => $this->run($runId),
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function rollbackCatalog(
        string $symbol,
        ?string $expectedCurrentRunId = null,
        ?DateTimeInterface $at = null
    ): array {
        $symbol = $this->symbol($symbol);
        $rolledBackAt = $this->dateTime($at);

        return DB::transaction(function () use ($symbol, $expectedCurrentRunId, $rolledBackAt): array {
            $head = DB::table('calculator_catalog_heads')->where('symbol', $symbol)->lockForUpdate()->first();
            if (! $head || $head->previous_run_id === null) {
                throw new LogicException('No previous calculator catalog publication is available.');
            }
            if ($expectedCurrentRunId !== null && (string) $head->current_run_id !== $expectedCurrentRunId) {
                throw new LogicException('Calculator catalog rollback compare-and-swap failed.');
            }

            DB::table('calculator_catalog_heads')->where('symbol', $symbol)->update([
                'current_run_id' => $head->previous_run_id,
                'current_generation' => $head->previous_generation,
                'current_source_asof' => $head->previous_source_asof,
                'previous_run_id' => $head->current_run_id,
                'previous_generation' => $head->current_generation,
                'previous_source_asof' => $head->current_source_asof,
                'updated_at' => $rolledBackAt,
            ]);

            return $this->catalogHead($symbol) ?? [];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function rollbackExpiry(
        string $symbol,
        string $expiration,
        ?string $expectedCurrentPublicationId = null,
        ?DateTimeInterface $at = null
    ): array {
        $symbol = $this->symbol($symbol);
        $expiration = $this->expiration($expiration);
        $rolledBackAt = $this->dateTime($at);

        return DB::transaction(function () use (
            $symbol,
            $expiration,
            $expectedCurrentPublicationId,
            $rolledBackAt
        ): array {
            $head = DB::table('calculator_expiry_heads')
                ->where('symbol', $symbol)
                ->where('expiration', $expiration)
                ->lockForUpdate()
                ->first();
            if (! $head || $head->previous_publication_id === null) {
                throw new LogicException('No previous calculator expiration publication is available.');
            }
            if (
                $expectedCurrentPublicationId !== null
                && (string) $head->current_publication_id !== $expectedCurrentPublicationId
            ) {
                throw new LogicException('Calculator expiration rollback compare-and-swap failed.');
            }

            DB::table('calculator_expiry_heads')->where('id', $head->id)->update([
                'current_publication_id' => $head->previous_publication_id,
                'current_generation' => $head->previous_generation,
                'current_source_asof' => $head->previous_source_asof,
                'previous_publication_id' => $head->current_publication_id,
                'previous_generation' => $head->current_generation,
                'previous_source_asof' => $head->current_source_asof,
                'updated_at' => $rolledBackAt,
            ]);

            return $this->expiryHead($symbol, $expiration) ?? [];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function run(string $runId): array
    {
        $run = DB::table('calculator_publication_runs')->where('id', $runId)->first();
        if (! $run) {
            throw new InvalidArgumentException('Unknown calculator publication run.');
        }

        return (array) $run;
    }

    /** @return array<string, mixed>|null */
    public function catalogHead(string $symbol): ?array
    {
        $head = DB::table('calculator_catalog_heads')->where('symbol', $this->symbol($symbol))->first();

        return $head ? (array) $head : null;
    }

    /** @return array<string, mixed>|null */
    public function expiryHead(string $symbol, string $expiration): ?array
    {
        $head = DB::table('calculator_expiry_heads')
            ->where('symbol', $this->symbol($symbol))
            ->where('expiration', $this->expiration($expiration))
            ->first();

        return $head ? (array) $head : null;
    }

    /**
     * Return the catalog currently safe for menus and its independently published expiry heads.
     *
     * @return array<string, mixed>|null
     */
    public function authoritativeCatalog(string $symbol): ?array
    {
        $symbol = $this->symbol($symbol);
        $head = DB::table('calculator_catalog_heads')->where('symbol', $symbol)->first();
        if (! $head) {
            return null;
        }
        $run = DB::table('calculator_publication_runs')->where('id', $head->current_run_id)->first();
        if (! $run) {
            throw new RuntimeException('Calculator catalog head references a missing run.');
        }

        $expirations = DB::table('calculator_run_expirations as expected')
            ->leftJoin('calculator_expiry_heads as head', function ($join): void {
                $join->on('head.symbol', '=', 'expected.symbol')
                    ->on('head.expiration', '=', 'expected.expiration');
            })
            ->leftJoin(
                'calculator_expiry_publications as publication',
                'publication.id',
                '=',
                'head.current_publication_id'
            )
            ->where('expected.run_id', $head->current_run_id)
            ->orderBy('expected.expiration')
            ->get([
                'expected.expiration',
                'expected.catalog_source',
                'expected.catalog_precedence',
                'expected.readiness as catalog_run_readiness',
                'expected.publication_id as catalog_run_publication_id',
                'expected.source_asof as catalog_run_source_asof',
                'expected.discovered_at',
                'expected.last_seen_at',
                'head.current_publication_id as publication_id',
                'head.current_generation as publication_generation',
                'head.current_source_asof as publication_source_asof',
                'publication.run_id as publication_run_id',
                'publication.chain_source',
                'publication.snapshot_at',
                'publication.row_count',
                'publication.content_hash',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return [
            'symbol' => $symbol,
            'state' => 'complete',
            'run_id' => (string) $run->id,
            'generation' => (int) $run->generation,
            'catalog_source' => $run->catalog_source,
            'catalog_source_asof' => $run->catalog_source_asof,
            'discovery_horizon' => $run->discovery_horizon,
            'expected_count' => (int) $run->expected_count,
            'completed_count' => (int) $run->completed_count,
            'failed_count' => (int) $run->failed_count,
            'completed_at' => $run->completed_at,
            'previous_run_id' => $head->previous_run_id,
            'previous_generation' => $head->previous_generation === null
                ? null
                : (int) $head->previous_generation,
            'max_generation' => (int) $head->max_generation,
            'expirations' => $expirations,
        ];
    }

    /**
     * Return the current independently fenced publication for one expiration.
     *
     * @return array<string, mixed>|null
     */
    public function publishedExpiry(string $symbol, string $expiration, bool $includeRows = true): ?array
    {
        $symbol = $this->symbol($symbol);
        $expiration = $this->expiration($expiration);
        $head = DB::table('calculator_expiry_heads')
            ->where('symbol', $symbol)
            ->where('expiration', $expiration)
            ->first();
        if (! $head) {
            return null;
        }
        $publication = DB::table('calculator_expiry_publications')
            ->where('id', $head->current_publication_id)
            ->first();
        if (! $publication) {
            throw new RuntimeException('Calculator expiration head references a missing publication.');
        }

        return [
            'symbol' => $symbol,
            'expiration' => $expiration,
            'state' => 'ready',
            'publication_id' => (string) $publication->id,
            'run_id' => (string) $publication->run_id,
            'generation' => (int) $publication->generation,
            'chain_source' => $publication->chain_source,
            'source_asof' => $publication->source_asof,
            'snapshot_at' => $publication->snapshot_at,
            'row_count' => (int) $publication->row_count,
            'content_hash' => $publication->content_hash,
            'previous_publication_id' => $head->previous_publication_id,
            'previous_generation' => $head->previous_generation === null
                ? null
                : (int) $head->previous_generation,
            'max_generation' => (int) $head->max_generation,
            'rows' => $includeRows ? $this->publicationRows((string) $publication->id) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function publicationRows(string $publicationId): array
    {
        return DB::table('calculator_expiry_publication_rows')
            ->where('publication_id', $publicationId)
            ->orderBy('strike')
            ->orderBy('type')
            ->get([
                'ticker',
                'contract_key',
                'type',
                'strike',
                'bid',
                'ask',
                'mid',
                'implied_volatility',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return array{run:array<string,mixed>,expirations:list<array<string,mixed>>} */
    public function runManifest(string $runId): array
    {
        return [
            'run' => $this->run($runId),
            'expirations' => DB::table('calculator_run_expirations')
                ->where('run_id', $runId)
                ->orderBy('expiration')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /** @return array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null */
    public function runForWorkRun(string $workRunId): ?array
    {
        $workRunId = $this->requiredText($workRunId, 36, 'Work-run ID');
        $runId = DB::table('calculator_publication_runs')
            ->where('work_run_id', $workRunId)
            ->orderByDesc('generation')
            ->value('id');

        return $runId === null ? null : $this->runManifest((string) $runId);
    }

    /** @return array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null */
    public function latestRunForSymbol(string $symbol): ?array
    {
        $runId = DB::table('calculator_publication_runs')
            ->where('symbol', $this->symbol($symbol))
            ->orderByDesc('generation')
            ->value('id');

        return $runId === null ? null : $this->runManifest((string) $runId);
    }

    /** @return array<string, mixed> */
    private function startRun(
        string $symbol,
        string $scope,
        string $purpose,
        ?string $requestedExpiry,
        ?string $ownerKey,
        ?string $workRunId,
        ?DateTimeInterface $at
    ): array {
        $symbol = $this->symbol($symbol);
        $purpose = $this->requiredText($purpose, 64, 'Purpose');
        $workRunId = $workRunId === null ? null : $this->requiredText($workRunId, 36, 'Work-run ID');
        $ownerReference = $ownerKey === null
            ? ($workRunId === null ? null : 'work-run:'.$workRunId)
            : $this->requiredText($ownerKey, 191, 'Owner key');
        if ($ownerReference === null) {
            throw new InvalidArgumentException(
                'A calculator publication run requires a stable owner key or work-run ID.'
            );
        }
        $ownerHash = hash('sha256', $ownerReference);
        $startedAt = $this->dateTime($at);

        return DB::transaction(function () use (
            $symbol,
            $scope,
            $purpose,
            $requestedExpiry,
            $ownerReference,
            $ownerHash,
            $workRunId,
            $startedAt
        ): array {
            $existing = $this->reusableRun($symbol, $scope, $ownerHash, $workRunId);
            if ($existing) {
                $this->assertOwnedRunMatches(
                    $existing,
                    $purpose,
                    $requestedExpiry,
                    $ownerReference,
                    $workRunId
                );

                return (array) $existing;
            }

            DB::table('calculator_symbol_generations')->insertOrIgnore([
                'symbol' => $symbol,
                'last_generation' => 0,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
            $sequence = DB::table('calculator_symbol_generations')
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();
            if (! $sequence) {
                throw new RuntimeException('Calculator generation allocation failed.');
            }

            $existing = $this->reusableRun($symbol, $scope, $ownerHash, $workRunId);
            if ($existing) {
                $this->assertOwnedRunMatches(
                    $existing,
                    $purpose,
                    $requestedExpiry,
                    $ownerReference,
                    $workRunId
                );

                return (array) $existing;
            }

            $generation = (int) $sequence->last_generation + 1;
            $updated = DB::table('calculator_symbol_generations')
                ->where('symbol', $symbol)
                ->where('last_generation', $sequence->last_generation)
                ->update([
                    'last_generation' => $generation,
                    'updated_at' => $startedAt,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Calculator generation compare-and-swap failed.');
            }

            $runId = (string) Str::uuid();
            DB::table('calculator_publication_runs')->insert([
                'id' => $runId,
                'symbol' => $symbol,
                'generation' => $generation,
                'scope' => $scope,
                'purpose' => $purpose,
                'owner_key' => $ownerHash,
                'owner_reference' => $ownerReference,
                'work_run_id' => $workRunId,
                'requested_expiry' => $requestedExpiry,
                'status' => $scope === self::SCOPE_CATALOG ? 'discovering' : 'preparing',
                'discovery_terminal' => false,
                'discovery_capped' => false,
                'catalog_source' => null,
                'catalog_source_asof' => null,
                'discovery_horizon' => null,
                'expected_expirations_hash' => null,
                'expected_count' => $requestedExpiry === null ? 0 : 1,
                'completed_count' => 0,
                'failed_count' => 0,
                'failure_code' => null,
                'failure_reason' => null,
                'expected_frozen_at' => $requestedExpiry === null ? null : $startedAt,
                'started_at' => $startedAt,
                'heartbeat_at' => $startedAt,
                'completed_at' => null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            if ($requestedExpiry !== null) {
                DB::table('calculator_run_expirations')->insert([
                    'run_id' => $runId,
                    'symbol' => $symbol,
                    'expiration' => $requestedExpiry,
                    'catalog_source' => 'selected-request',
                    'catalog_precedence' => 0,
                    'readiness' => 'pending',
                    'publication_id' => null,
                    'source_asof' => null,
                    'failure_code' => null,
                    'failure_reason' => null,
                    'discovered_at' => $startedAt,
                    'last_seen_at' => $startedAt,
                    'ready_at' => null,
                    'failed_at' => null,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]);
            }

            return $this->run($runId);
        }, 3);
    }

    private function ownedRun(string $symbol, string $scope, string $ownerHash): ?object
    {
        return DB::table('calculator_publication_runs')
            ->where('symbol', $symbol)
            ->where('scope', $scope)
            ->where('owner_key', $ownerHash)
            ->lockForUpdate()
            ->first();
    }

    private function reusableRun(
        string $symbol,
        string $scope,
        string $ownerHash,
        ?string $workRunId
    ): ?object {
        $run = $this->ownedRun($symbol, $scope, $ownerHash);
        if ($run || $workRunId === null) {
            return $run;
        }

        $run = DB::table('calculator_publication_runs')
            ->where('work_run_id', $workRunId)
            ->lockForUpdate()
            ->first();
        if ($run && ((string) $run->symbol !== $symbol || (string) $run->scope !== $scope)) {
            throw new LogicException('A work run cannot own multiple calculator publication scopes.');
        }

        return $run;
    }

    private function assertOwnedRunMatches(
        object $run,
        string $purpose,
        ?string $requestedExpiry,
        string $ownerReference,
        ?string $workRunId
    ): void {
        $same = (string) $run->purpose === $purpose
            && ($run->requested_expiry === null ? null : (string) $run->requested_expiry) === $requestedExpiry
            && (string) $run->owner_reference === $ownerReference
            && ($run->work_run_id === null ? null : (string) $run->work_run_id) === $workRunId;
        if (! $same) {
            throw new LogicException('Calculator publication owner key conflicts with an existing run.');
        }
    }

    /** @return array<string, mixed> */
    private function finishRun(
        string $runId,
        string $status,
        string $failureCode,
        string $reason,
        bool $capped,
        ?DateTimeInterface $at
    ): array {
        $completedAt = $this->dateTime($at);
        $code = $this->requiredText($failureCode, 64, 'Failure code');
        $safeReason = $this->text($reason, 255);

        return DB::transaction(function () use (
            $runId,
            $status,
            $code,
            $safeReason,
            $capped,
            $completedAt
        ): array {
            $run = $this->lockedRun($runId);
            if (in_array((string) $run->status, ['complete', 'superseded'], true)) {
                throw new LogicException('A published calculator run cannot be failed or capped.');
            }
            if (in_array((string) $run->status, ['failed', 'capped'], true)) {
                return $this->run($runId);
            }

            DB::table('calculator_publication_runs')->where('id', $runId)->update([
                'status' => $status,
                'discovery_capped' => $capped,
                'failure_code' => $code,
                'failure_reason' => $safeReason,
                'heartbeat_at' => $completedAt,
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

            return $this->run($runId);
        }, 3);
    }

    private function advanceExpiryHead(
        string $symbol,
        string $expiration,
        string $publicationId,
        int $generation,
        string $sourceAsOf,
        string $at
    ): bool {
        $head = DB::table('calculator_expiry_heads')
            ->where('symbol', $symbol)
            ->where('expiration', $expiration)
            ->lockForUpdate()
            ->first();

        if (! $head) {
            DB::table('calculator_expiry_heads')->insertOrIgnore([
                'symbol' => $symbol,
                'expiration' => $expiration,
                'current_publication_id' => $publicationId,
                'current_generation' => $generation,
                'current_source_asof' => $sourceAsOf,
                'previous_publication_id' => null,
                'previous_generation' => null,
                'previous_source_asof' => null,
                'max_generation' => $generation,
                'max_source_asof' => $sourceAsOf,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $head = DB::table('calculator_expiry_heads')
                ->where('symbol', $symbol)
                ->where('expiration', $expiration)
                ->lockForUpdate()
                ->first();
            if (! $head) {
                throw new RuntimeException('Calculator expiration head allocation failed.');
            }
        }

        if ((string) $head->current_publication_id === $publicationId) {
            return true;
        }
        if (
            $generation <= (int) $head->max_generation
            || $this->compareDateTime($sourceAsOf, $head->max_source_asof) < 0
        ) {
            return false;
        }

        DB::table('calculator_expiry_heads')->where('id', $head->id)->update([
            'current_publication_id' => $publicationId,
            'current_generation' => $generation,
            'current_source_asof' => $sourceAsOf,
            'previous_publication_id' => $head->current_publication_id,
            'previous_generation' => $head->current_generation,
            'previous_source_asof' => $head->current_source_asof,
            'max_generation' => $generation,
            'max_source_asof' => $sourceAsOf,
            'updated_at' => $at,
        ]);

        return true;
    }

    private function advanceCatalogHead(
        string $symbol,
        string $runId,
        int $generation,
        string $sourceAsOf,
        string $at,
        ?object $head = null
    ): bool {
        $head ??= DB::table('calculator_catalog_heads')->where('symbol', $symbol)->lockForUpdate()->first();
        if (! $head) {
            DB::table('calculator_catalog_heads')->insertOrIgnore([
                'symbol' => $symbol,
                'current_run_id' => $runId,
                'current_generation' => $generation,
                'current_source_asof' => $sourceAsOf,
                'previous_run_id' => null,
                'previous_generation' => null,
                'previous_source_asof' => null,
                'max_generation' => $generation,
                'max_source_asof' => $sourceAsOf,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $head = DB::table('calculator_catalog_heads')->where('symbol', $symbol)->lockForUpdate()->first();
            if (! $head) {
                throw new RuntimeException('Calculator catalog head allocation failed.');
            }
        }

        if ((string) $head->current_run_id === $runId) {
            return true;
        }
        if (
            $generation <= (int) $head->max_generation
            || $this->compareDateTime($sourceAsOf, $head->max_source_asof) < 0
        ) {
            return false;
        }

        DB::table('calculator_catalog_heads')->where('symbol', $symbol)->update([
            'current_run_id' => $runId,
            'current_generation' => $generation,
            'current_source_asof' => $sourceAsOf,
            'previous_run_id' => $head->current_run_id,
            'previous_generation' => $head->current_generation,
            'previous_source_asof' => $head->current_source_asof,
            'max_generation' => $generation,
            'max_source_asof' => $sourceAsOf,
            'updated_at' => $at,
        ]);

        return true;
    }

    /** @return array{int, int} */
    private function readinessCounts(string $runId): array
    {
        $row = DB::table('calculator_run_expirations')
            ->where('run_id', $runId)
            ->selectRaw("SUM(CASE WHEN readiness = 'ready' THEN 1 ELSE 0 END) AS ready_count")
            ->selectRaw("SUM(CASE WHEN readiness = 'failed' THEN 1 ELSE 0 END) AS failed_count")
            ->first();

        return [(int) ($row->ready_count ?? 0), (int) ($row->failed_count ?? 0)];
    }

    /** @return array{advanced:false,idempotent:false,reason:string,run:array<string,mixed>} */
    private function completionRejected(string $runId, string $reason): array
    {
        return [
            'advanced' => false,
            'idempotent' => false,
            'reason' => $reason,
            'run' => $this->run($runId),
        ];
    }

    private function lockedRun(string $runId): object
    {
        $run = DB::table('calculator_publication_runs')->where('id', $runId)->lockForUpdate()->first();
        if (! $run) {
            throw new InvalidArgumentException('Unknown calculator publication run.');
        }

        return $run;
    }

    private function requireScope(object $run, string $scope): void
    {
        if ((string) $run->scope !== $scope) {
            throw new LogicException("Calculator run scope must be {$scope}.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{contract_key:string,ticker:string|null,type:string,strike:string,bid:string|null,ask:string|null,mid:string|null,implied_volatility:string|null}>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = array_map(function (array $row): array {
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! in_array($type, ['call', 'put'], true)) {
                throw new InvalidArgumentException('Calculator publication rows require call or put type.');
            }
            $ticker = $this->nullableText($row['ticker'] ?? null, 128);
            $ticker = $ticker === null ? null : strtoupper($ticker);
            $strike = $this->decimal($row['strike'] ?? null, false, 'strike');

            return [
                'contract_key' => $this->contractKey($ticker, $type, $strike),
                'ticker' => $ticker,
                'type' => $type,
                'strike' => $strike,
                'bid' => $this->nullableDecimal($row['bid'] ?? null, 'bid'),
                'ask' => $this->nullableDecimal($row['ask'] ?? null, 'ask'),
                'mid' => $this->nullableDecimal($row['mid'] ?? null, 'mid'),
                'implied_volatility' => $this->nullableDecimal(
                    $row['implied_volatility'] ?? null,
                    'implied volatility'
                ),
            ];
        }, $rows);

        $byContract = [];
        foreach ($normalized as $row) {
            $key = $row['contract_key'];
            if (! isset($byContract[$key])) {
                $byContract[$key] = $row;

                continue;
            }
            if ($byContract[$key] !== $row) {
                throw new LogicException('Conflicting rows share one calculator contract identity.');
            }
        }

        $normalized = array_values($byContract);
        usort($normalized, fn (array $left, array $right): int => [
            $left['type'],
            (float) $left['strike'],
            $left['ticker'] ?? '',
        ] <=> [
            $right['type'],
            (float) $right['strike'],
            $right['ticker'] ?? '',
        ]);

        return $normalized;
    }

    private function contractKey(?string $ticker, string $type, string $strike): string
    {
        $identity = $ticker === null
            ? 'fallback:'.$type.':'.$strike
            : 'ticker:'.strtoupper($ticker);

        return hash('sha256', $identity);
    }

    private function decimal(mixed $value, bool $allowZero, string $field): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Calculator publication {$field} must be numeric.");
        }
        $number = (float) $value;
        if (! is_finite($number) || ($allowZero ? $number < 0 : $number <= 0)) {
            throw new InvalidArgumentException("Calculator publication {$field} is outside its valid range.");
        }

        return number_format($number, 6, '.', '');
    }

    private function nullableDecimal(mixed $value, string $field): ?string
    {
        return $value === null ? null : $this->decimal($value, true, $field);
    }

    private function symbol(string $symbol): string
    {
        $symbol = Symbols::canon($symbol);
        if ($symbol === '' || strlen($symbol) > 32) {
            throw new InvalidArgumentException('Calculator publication symbol is invalid.');
        }

        return $symbol;
    }

    private function expiration(string $expiration): string
    {
        $expiration = trim($expiration);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $expiration, 'UTC');
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->format('Y-m-d') !== $expiration) {
            throw new InvalidArgumentException('Calculator expiration must use YYYY-MM-DD.');
        }

        return $expiration;
    }

    private function dateTime(?DateTimeInterface $value): string
    {
        return $value === null
            ? CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')
            : CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s.u');
    }

    private function dateTimeValue(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            throw new LogicException('Calculator publication source timestamp is missing.');
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    private function compareDateTime(mixed $left, mixed $right): int
    {
        $left = CarbonImmutable::parse((string) $left, 'UTC');
        $right = CarbonImmutable::parse((string) $right, 'UTC');

        return $left->equalTo($right) ? 0 : ($left->lessThan($right) ? -1 : 1);
    }

    private function sameDateTime(mixed $left, mixed $right): bool
    {
        return $this->compareDateTime($left, $right) === 0;
    }

    private function requiredText(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $value;
    }

    private function text(string $value, int $maxLength): string
    {
        return substr(trim($value), 0, $maxLength);
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, $maxLength);
    }
}
