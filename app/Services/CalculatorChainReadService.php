<?php

namespace App\Services;

use App\Support\CalculatorPublicationRepository;
use App\Support\CalculatorUnderlyingResolver;
use App\Support\ExchangeCalendarDte;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CalculatorChainReadService
{
    public function __construct(
        private readonly CalculatorPublicationRepository $publications,
        private readonly CalculatorUnderlyingResolver $underlyingResolver,
        private readonly ExchangeCalendarDte $calendar,
        private readonly WorkRunCoordinator $workRuns,
    ) {}

    /** @return array{payload:array<string, mixed>, status:int} */
    public function read(string $requestedSymbol, ?string $requestedExpiry): array
    {
        $symbol = Symbols::canon($requestedSymbol);
        $requestedExpiry = $requestedExpiry === null ? null : substr($requestedExpiry, 0, 10);
        $now = CarbonImmutable::now('UTC');
        $exchangeDate = $now->setTimezone(ExchangeCalendarDte::TIMEZONE)->toDateString();
        $underlying = $this->underlyingResolver->resolve($symbol, $now);
        $activeWorkRun = $this->workRuns->active(
            'calculator_refresh',
            $symbol,
            ['expiry' => $requestedExpiry]
        );
        $catalog = $this->publications->authoritativeCatalog($symbol);
        $latestManifest = $this->publications->latestRunForSymbol($symbol);
        $latestCatalogManifest = $this->compatibleManifest($symbol, null, $latestManifest);
        $relevantManifest = $this->relevantManifest(
            $symbol,
            $requestedExpiry,
            $activeWorkRun?->id,
            $latestManifest
        );
        $menuManifest = $latestCatalogManifest ?? $relevantManifest;

        $expirationRows = $catalog
            ? $this->canonicalExpirations($catalog, $exchangeDate, $now)
            : $this->fallbackExpirations($symbol, $menuManifest, $exchangeDate, $now);
        $resolvedExpiry = $this->resolveExpiry($requestedExpiry, $expirationRows);
        $publication = $resolvedExpiry === null
            ? null
            : $this->publications->publishedExpiry($symbol, $resolvedExpiry);

        $selection = $publication
            ? $this->canonicalSelection($symbol, $publication, $resolvedExpiry, $now, $underlying)
            : $this->legacySelection($symbol, $resolvedExpiry, $now, $underlying);
        $selection['health']['expirations_count'] = count($expirationRows);
        $run = $this->runMetadata($relevantManifest);
        $catalogMetadata = $this->catalogMetadata(
            $catalog,
            $latestCatalogManifest,
            $expirationRows,
            $selection['chain'] !== []
        );
        $selectedState = $this->selectedState(
            $resolvedExpiry,
            $expirationRows,
            $selection['chain'] !== []
        );
        $fetchMeta = $this->fetchMeta($symbol, $resolvedExpiry ?? $requestedExpiry);

        $legacyStatus = $this->legacyStatus(
            $selection,
            $catalogMetadata,
            $resolvedExpiry,
            $expirationRows
        );
        $httpStatus = $selection['chain'] !== [] || $legacyStatus === 'no_options' ? 200 : 202;

        $payload = [
            'underlying' => $underlying,
            'chain' => $selection['chain'],
            'expirations' => $expirationRows,
            'status' => $legacyStatus,
            'snapshot_at' => $selection['snapshot_at'],
            'snapshot_stats' => $selection['snapshot_stats'],
            'requested_expiry' => $requestedExpiry,
            'resolved_expiry' => $resolvedExpiry,
            'refresh_queued' => $activeWorkRun !== null,
            'health' => $selection['health'],
            'fetch_meta' => $fetchMeta,
            'as_of_exchange_date' => $exchangeDate,
            'catalog_state' => $catalogMetadata['state'],
            'run_state' => $run['state'],
            'selected_chain_state' => $selectedState,
            'run_id' => $run['id'],
            'expected_count' => $run['expected_count'],
            'completed_count' => $run['completed_count'],
            'failed_count' => $run['failed_count'],
            'publication_generation' => $selection['publication']['generation'],
            'source_asof' => $selection['publication']['source_asof'],
            'failure_reason' => $run['failure_reason'],
            'catalog' => $catalogMetadata,
            'run' => $run,
            'publication' => $selection['publication'],
            'work_run' => $activeWorkRun === null
                ? null
                : $this->workRuns->payload($activeWorkRun),
        ];

        return ['payload' => $payload, 'status' => $httpStatus];
    }

    /**
     * Prefer the publication manifest owned by the exact active work run. Otherwise, use the
     * newest publication run compatible with this read. An unrelated selected-expiry run must
     * not describe a full-catalog or different-expiry response.
     *
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $latest
     * @return array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null
     */
    private function relevantManifest(
        string $symbol,
        ?string $requestedExpiry,
        ?string $activeWorkRunId,
        ?array $latest
    ): ?array {
        if ($activeWorkRunId !== null) {
            $owned = $this->publications->runForWorkRun($activeWorkRunId);
            if ($owned !== null) {
                return $owned;
            }
        }

        return $this->compatibleManifest($symbol, $requestedExpiry, $latest);
    }

    /**
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $latest
     * @return array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null
     */
    private function compatibleManifest(string $symbol, ?string $requestedExpiry, ?array $latest): ?array
    {
        $run = $latest['run'] ?? null;
        if ($run !== null && $this->scopeMatches($run, $requestedExpiry)) {
            return $latest;
        }

        $runId = DB::table('calculator_publication_runs')
            ->where('symbol', $symbol)
            ->when(
                $requestedExpiry === null,
                fn ($query) => $query->where('scope', CalculatorPublicationRepository::SCOPE_CATALOG),
                fn ($query) => $query->where(function ($scope) use ($requestedExpiry): void {
                    $scope->where('scope', CalculatorPublicationRepository::SCOPE_CATALOG)
                        ->orWhere(function ($selected) use ($requestedExpiry): void {
                            $selected->where('scope', CalculatorPublicationRepository::SCOPE_SELECTED_EXPIRY)
                                ->whereDate('requested_expiry', $requestedExpiry);
                        });
                })
            )
            ->orderByDesc('generation')
            ->value('id');

        return $runId === null ? null : $this->publications->runManifest((string) $runId);
    }

    /** @param array<string, mixed> $run */
    private function scopeMatches(array $run, ?string $requestedExpiry): bool
    {
        if ($requestedExpiry === null) {
            return ($run['scope'] ?? null) === CalculatorPublicationRepository::SCOPE_CATALOG;
        }

        return ($run['scope'] ?? null) === CalculatorPublicationRepository::SCOPE_CATALOG
            || (
                ($run['scope'] ?? null) === CalculatorPublicationRepository::SCOPE_SELECTED_EXPIRY
                && substr((string) ($run['requested_expiry'] ?? ''), 0, 10) === $requestedExpiry
            );
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return list<array<string, mixed>>
     */
    private function canonicalExpirations(array $catalog, string $exchangeDate, CarbonImmutable $now): array
    {
        return collect($catalog['expirations'] ?? [])
            ->filter(fn (array $row): bool => (string) ($row['expiration'] ?? '') >= $exchangeDate)
            ->map(function (array $row) use ($now): array {
                $expiration = (string) $row['expiration'];
                $readiness = $row['publication_id'] !== null
                    ? 'ready'
                    : $this->readiness((string) ($row['catalog_run_readiness'] ?? 'pending'));

                return $this->expirationPayload($expiration, $now, $readiness, [
                    'publication_id' => $row['publication_id'],
                    'publication_generation' => $this->nullableInt($row['publication_generation']),
                    'publication_run_id' => $row['publication_run_id'],
                    'source' => $row['chain_source'],
                    'source_asof' => $this->iso($row['publication_source_asof']),
                    'snapshot_at' => $this->iso($row['snapshot_at']),
                    'row_count' => $this->nullableInt($row['row_count']),
                    'catalog_source' => $row['catalog_source'],
                    'catalog_precedence' => $this->nullableInt($row['catalog_precedence']),
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $latestManifest
     * @return list<array<string, mixed>>
     */
    private function fallbackExpirations(
        string $symbol,
        ?array $latestManifest,
        string $exchangeDate,
        CarbonImmutable $now
    ): array {
        $legacySnapshotDates = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('expiry', '>=', $exchangeDate)
            ->distinct()
            ->orderBy('expiry')
            ->pluck('expiry')
            ->map(fn (mixed $value): string => substr((string) $value, 0, 10));
        $eodDates = DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->whereDate('expiration_date', '>=', $exchangeDate)
            ->orderBy('expiration_date')
            ->pluck('expiration_date')
            ->map(fn (mixed $value): string => substr((string) $value, 0, 10));
        $manifestRows = collect($latestManifest['expirations'] ?? [])
            ->filter(fn (array $row): bool => (string) ($row['expiration'] ?? '') >= $exchangeDate)
            ->keyBy(fn (array $row): string => (string) $row['expiration']);
        $publishedHeads = DB::table('calculator_expiry_heads as head')
            ->join(
                'calculator_expiry_publications as publication',
                'publication.id',
                '=',
                'head.current_publication_id'
            )
            ->where('head.symbol', $symbol)
            ->whereDate('head.expiration', '>=', $exchangeDate)
            ->get([
                'head.expiration',
                'publication.id as publication_id',
                'publication.run_id',
                'publication.generation',
                'publication.chain_source',
                'publication.source_asof',
                'publication.snapshot_at',
                'publication.row_count',
            ])
            ->keyBy(fn (object $row): string => substr((string) $row->expiration, 0, 10));

        return $legacySnapshotDates
            ->merge($eodDates)
            ->merge($manifestRows->keys())
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $expiration) use (
                $legacySnapshotDates,
                $manifestRows,
                $publishedHeads,
                $now
            ): array {
                $manifest = $manifestRows->get($expiration);
                $published = $publishedHeads->get($expiration);
                $readiness = $published !== null
                    ? 'ready'
                    : ($legacySnapshotDates->contains($expiration)
                        ? 'ready'
                        : $this->readiness((string) ($manifest['readiness'] ?? 'pending')));

                return $this->expirationPayload($expiration, $now, $readiness, [
                    'publication_id' => $published->publication_id ?? null,
                    'publication_generation' => $this->nullableInt($published->generation ?? null),
                    'publication_run_id' => $published->run_id ?? null,
                    'source' => $published->chain_source ?? ($manifest ? null : 'legacy-option-snapshots'),
                    'source_asof' => $this->iso($published->source_asof ?? ($manifest['source_asof'] ?? null)),
                    'snapshot_at' => $this->iso($published->snapshot_at ?? null),
                    'row_count' => $this->nullableInt($published->row_count ?? null),
                    'catalog_source' => $manifest['catalog_source'] ?? ($legacySnapshotDates->contains($expiration)
                        ? 'legacy-option-snapshots'
                        : 'eod-option-expirations'),
                    'catalog_precedence' => $this->nullableInt($manifest['catalog_precedence'] ?? null),
                ]);
            })
            ->all();
    }

    /** @param list<array<string, mixed>> $expirations */
    private function resolveExpiry(?string $requested, array $expirations): ?string
    {
        $available = collect($expirations);
        if ($requested !== null && $available->contains('value', $requested)) {
            return $requested;
        }

        return data_get($available->firstWhere('readiness', 'ready'), 'value')
            ?? data_get($available->first(), 'value')
            ?? null;
    }

    /**
     * @param  array<string, mixed>  $publication
     * @param  array<string, mixed>  $underlying
     * @return array<string, mixed>
     */
    private function canonicalSelection(
        string $symbol,
        array $publication,
        string $expiration,
        CarbonImmutable $now,
        array $underlying
    ): array {
        $rows = collect($publication['rows'] ?? [])
            ->map(fn (array $row): array => $this->chainRow($symbol, $row, $expiration, $now))
            ->values();
        $stats = $this->stats($rows);

        return [
            'chain' => $rows->all(),
            'snapshot_at' => $this->iso($publication['snapshot_at']),
            'snapshot_stats' => $stats,
            'health' => $this->health($stats, $underlying, false),
            'publication' => [
                'state' => 'ready',
                'source' => 'canonical',
                'id' => $publication['publication_id'],
                'run_id' => $publication['run_id'],
                'generation' => (int) $publication['generation'],
                'chain_source' => $publication['chain_source'],
                'source_asof' => $this->iso($publication['source_asof']),
                'snapshot_at' => $this->iso($publication['snapshot_at']),
                'row_count' => (int) $publication['row_count'],
                'previous_publication_id' => $publication['previous_publication_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $underlying
     * @return array<string, mixed>
     */
    private function legacySelection(
        string $symbol,
        ?string $expiration,
        CarbonImmutable $now,
        array $underlying
    ): array {
        $empty = $this->emptySelection($underlying);
        if ($expiration === null) {
            return $empty;
        }

        $groups = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('expiry', $expiration)
            ->selectRaw('fetched_at, COUNT(*) AS row_count, MIN(strike) AS min_strike, MAX(strike) AS max_strike')
            ->groupBy('fetched_at')
            ->orderByDesc('fetched_at')
            ->get();
        if ($groups->isEmpty()) {
            return $empty;
        }

        $spot = $underlying['price'];
        $best = $groups->first(function (object $group) use ($spot): bool {
            return $spot !== null
                && (int) $group->row_count >= 40
                && (float) $group->min_strike <= ((float) $spot * 0.95)
                && (float) $group->max_strike >= ((float) $spot * 1.05);
        }) ?? $groups->sortByDesc('row_count')->first();
        $snapshotAt = (string) $best->fetched_at;
        $rows = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('expiry', $expiration)
            ->where('fetched_at', $snapshotAt)
            ->orderBy('strike')
            ->orderBy('type')
            ->get([
                'ticker',
                'type',
                'strike',
                'bid',
                'ask',
                'mid',
                'implied_volatility',
            ])
            ->map(fn (object $row): array => $this->chainRow($symbol, (array) $row, $expiration, $now));
        $stats = $this->stats($rows);
        $partial = $this->legacyPartial($stats, $underlying);

        return [
            'chain' => $rows->all(),
            'snapshot_at' => $this->iso($snapshotAt),
            'snapshot_stats' => $stats,
            'health' => $this->health($stats, $underlying, $partial),
            'publication' => [
                'state' => 'ready',
                'source' => 'legacy',
                'id' => null,
                'run_id' => null,
                'generation' => null,
                'chain_source' => 'legacy-option-snapshots',
                'source_asof' => $this->iso($snapshotAt),
                'snapshot_at' => $this->iso($snapshotAt),
                'row_count' => $rows->count(),
                'previous_publication_id' => null,
            ],
        ];
    }

    /** @param array<string, mixed> $underlying */
    private function emptySelection(array $underlying): array
    {
        $stats = ['row_count' => 0, 'min_strike' => null, 'max_strike' => null];

        return [
            'chain' => [],
            'snapshot_at' => null,
            'snapshot_stats' => $stats,
            'health' => $this->health($stats, $underlying, true),
            'publication' => [
                'state' => 'unavailable',
                'source' => null,
                'id' => null,
                'run_id' => null,
                'generation' => null,
                'chain_source' => null,
                'source_asof' => null,
                'snapshot_at' => null,
                'row_count' => 0,
                'previous_publication_id' => null,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function chainRow(
        string $symbol,
        array $row,
        string $expiration,
        CarbonImmutable $now
    ): array {
        $ticker = trim((string) ($row['ticker'] ?? '')) ?: null;
        $type = strtolower((string) $row['type']);
        $strike = (float) $row['strike'];
        $identity = $ticker ?? sprintf(
            '%s|%s|%s|%.6F',
            $symbol,
            $expiration,
            $type,
            $strike
        );
        $calendar = $this->calendar->resolve($now, $expiration);

        return [
            'strike' => $strike,
            'type' => $type,
            'bid' => $this->nullableFloat($row['bid'] ?? null),
            'ask' => $this->nullableFloat($row['ask'] ?? null),
            'mid' => $this->nullableFloat($row['mid'] ?? null),
            'expiry' => $expiration,
            'label' => CarbonImmutable::parse($expiration)->format('m-d'),
            'ticker' => $ticker,
            'contract_symbol' => $identity,
            'expiration_date' => $calendar['expiration_date'],
            'dte' => $calendar['dte'],
            'iv' => $this->nullableFloat($row['implied_volatility'] ?? null),
            'implied_volatility' => $this->nullableFloat($row['implied_volatility'] ?? null),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function stats(Collection $rows): array
    {
        return [
            'row_count' => $rows->count(),
            'min_strike' => $rows->isEmpty() ? null : (float) $rows->min('strike'),
            'max_strike' => $rows->isEmpty() ? null : (float) $rows->max('strike'),
        ];
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $underlying */
    private function health(array $stats, array $underlying, bool $partial): array
    {
        $spot = $underlying['price'];
        $min = $stats['min_strike'];
        $max = $stats['max_strike'];
        $looksTruncated = $spot !== null && $max !== null && $max < ((float) $spot * 0.85);
        $coverageTooNarrow = $spot !== null && $min !== null && $max !== null
            && ($min >= ((float) $spot * 0.99) || $max <= ((float) $spot * 1.01));

        return [
            'expirations_count' => null,
            'row_count' => $stats['row_count'],
            'min_strike' => $min,
            'max_strike' => $max,
            'spot_price' => $spot,
            'coverage_too_narrow' => $coverageTooNarrow,
            'looks_truncated' => $looksTruncated,
            'is_partial' => $partial,
        ];
    }

    /** @param array<string, mixed> $stats @param array<string, mixed> $underlying */
    private function legacyPartial(array $stats, array $underlying): bool
    {
        $health = $this->health($stats, $underlying, false);

        return $stats['row_count'] < 40
            || $health['coverage_too_narrow']
            || $health['looks_truncated'];
    }

    /**
     * @param  array<string, mixed>|null  $catalog
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $latest
     * @param  list<array<string, mixed>>  $expirations
     * @return array<string, mixed>
     */
    private function catalogMetadata(?array $catalog, ?array $latest, array $expirations, bool $hasChain): array
    {
        $latestRun = $latest['run'] ?? null;
        $newerIncompleteCatalog = $catalog !== null
            && ($latestRun['scope'] ?? null) === CalculatorPublicationRepository::SCOPE_CATALOG
            && (int) ($latestRun['generation'] ?? 0) > (int) $catalog['generation']
            && ! in_array((string) ($latestRun['status'] ?? ''), ['complete', 'superseded'], true);
        $state = match (true) {
            $catalog !== null && $newerIncompleteCatalog => 'stale',
            $catalog !== null => 'complete',
            $latestRun !== null && ($latestRun['status'] ?? null) === 'capped' => 'capped',
            $latestRun !== null && ($latestRun['status'] ?? null) === 'failed' => 'failed',
            $latestRun !== null && ($latestRun['status'] ?? null) === 'partial' => 'partial',
            $latestRun !== null => 'preparing',
            $hasChain || $expirations !== [] => 'stale',
            default => 'no_data',
        };

        return [
            'state' => $state,
            'source' => $catalog === null ? ($expirations === [] ? null : 'legacy') : 'canonical',
            'run_id' => $catalog['run_id'] ?? null,
            'generation' => isset($catalog['generation']) ? (int) $catalog['generation'] : null,
            'catalog_source' => $catalog['catalog_source'] ?? null,
            'source_asof' => $this->iso($catalog['catalog_source_asof'] ?? null),
            'completed_at' => $this->iso($catalog['completed_at'] ?? null),
            'expected_count' => isset($catalog['expected_count']) ? (int) $catalog['expected_count'] : count($expirations),
            'completed_count' => isset($catalog['completed_count']) ? (int) $catalog['completed_count'] : null,
            'failed_count' => isset($catalog['failed_count']) ? (int) $catalog['failed_count'] : null,
            'previous_run_id' => $catalog['previous_run_id'] ?? null,
            'is_last_known_good' => $newerIncompleteCatalog,
            'candidate_run_id' => $latestRun['id'] ?? null,
            'candidate_generation' => isset($latestRun['generation'])
                ? (int) $latestRun['generation']
                : null,
            'candidate_state' => $latestRun['status'] ?? null,
            'candidate_expected_count' => isset($latestRun['expected_count'])
                ? (int) $latestRun['expected_count']
                : null,
            'candidate_completed_count' => isset($latestRun['completed_count'])
                ? (int) $latestRun['completed_count']
                : null,
            'candidate_failed_count' => isset($latestRun['failed_count'])
                ? (int) $latestRun['failed_count']
                : null,
            'candidate_failure_code' => $latestRun['failure_code'] ?? null,
            'candidate_failure_reason' => $this->safeFailureReason($latestRun['failure_reason'] ?? null),
        ];
    }

    /**
     * @param  array{run:array<string,mixed>,expirations:list<array<string,mixed>>}|null  $manifest
     * @return array<string, mixed>
     */
    private function runMetadata(?array $manifest): array
    {
        $run = $manifest['run'] ?? null;
        if ($run === null) {
            return [
                'id' => null,
                'state' => 'no_data',
                'generation' => null,
                'scope' => null,
                'purpose' => null,
                'requested_expiry' => null,
                'expected_count' => 0,
                'completed_count' => 0,
                'failed_count' => 0,
                'discovery_terminal' => false,
                'discovery_capped' => false,
                'started_at' => null,
                'heartbeat_at' => null,
                'completed_at' => null,
                'failure_code' => null,
                'failure_reason' => null,
                'expirations' => [],
            ];
        }

        return [
            'id' => $run['id'],
            'state' => (string) $run['status'],
            'generation' => (int) $run['generation'],
            'scope' => $run['scope'],
            'purpose' => $run['purpose'],
            'requested_expiry' => $run['requested_expiry'],
            'expected_count' => (int) $run['expected_count'],
            'completed_count' => (int) $run['completed_count'],
            'failed_count' => (int) $run['failed_count'],
            'discovery_terminal' => (bool) $run['discovery_terminal'],
            'discovery_capped' => (bool) $run['discovery_capped'],
            'started_at' => $this->iso($run['started_at']),
            'heartbeat_at' => $this->iso($run['heartbeat_at']),
            'completed_at' => $this->iso($run['completed_at']),
            'failure_code' => $run['failure_code'],
            'failure_reason' => $this->safeFailureReason($run['failure_reason'] ?? null),
            'expirations' => collect($manifest['expirations'] ?? [])->map(fn (array $row): array => [
                'expiration_date' => (string) $row['expiration'],
                'readiness' => $this->readiness((string) $row['readiness']),
                'publication_id' => $row['publication_id'],
                'source_asof' => $this->iso($row['source_asof']),
                'failure_code' => $row['failure_code'],
                'failure_reason' => $this->safeFailureReason($row['failure_reason'] ?? null),
            ])->values()->all(),
        ];
    }

    /** @param list<array<string, mixed>> $expirations */
    private function selectedState(?string $resolved, array $expirations, bool $hasChain): string
    {
        if ($resolved === null) {
            return 'no_data';
        }
        if ($hasChain) {
            return 'ready';
        }

        return data_get(collect($expirations)->firstWhere('value', $resolved), 'readiness', 'preparing');
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $expirations
     */
    private function legacyStatus(
        array $selection,
        array $catalog,
        ?string $resolvedExpiry,
        array $expirations
    ): string {
        if ($expirations === []) {
            return $catalog['state'] === 'complete'
                && (int) ($catalog['expected_count'] ?? 0) === 0
                    ? 'no_options'
                    : 'no_snapshot';
        }
        if ($resolvedExpiry === null || $selection['chain'] === []) {
            return 'no_expiry_snapshot';
        }
        if (
            $catalog['state'] === 'partial'
            || $catalog['is_last_known_good']
            || $selection['health']['is_partial']
        ) {
            return 'partial';
        }

        return 'ok';
    }

    /** @return array<string, mixed>|null */
    private function fetchMeta(string $symbol, ?string $expiration): ?array
    {
        $key = static fn (?string $expiry): string => 'calculator:fetch-meta:'.md5($symbol.'|'.($expiry ?? '*'));

        return Cache::get($key($expiration)) ?? Cache::get($key(null));
    }

    /** @param array<string, mixed> $publication */
    private function expirationPayload(
        string $expiration,
        CarbonImmutable $now,
        string $readiness,
        array $publication
    ): array {
        $calendar = $this->calendar->resolve($now, $expiration);

        return [
            'value' => $expiration,
            'label' => CarbonImmutable::parse($expiration)->format('m-d'),
            'expiration_date' => $calendar['expiration_date'],
            'dte' => $calendar['dte'],
            'readiness' => $readiness,
            'publication' => $publication,
        ];
    }

    private function readiness(string $state): string
    {
        return match ($state) {
            'ready' => 'ready',
            'failed' => 'failed',
            default => 'preparing',
        };
    }

    private function safeFailureReason(mixed $reason): ?string
    {
        $value = trim((string) ($reason ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->toIso8601String();
    }
}
