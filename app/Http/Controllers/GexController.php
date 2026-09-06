<?php

namespace App\Http\Controllers;

use App\Models\OptionChainData;
use App\Models\OptionExpiration;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotSelector;
use App\Support\GexExpirationUniverse;
use App\Support\GexSnapshotCache;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GexController extends Controller
{
    // Keep in sync with the frontend timeframe options
    protected array $uiTimeframes = ['0d', '1d', '7d', '14d', '30d', '90d'];

    protected const CACHE_HOURS = 8;

    protected const PERF_SLOW_MS = 1200;

    protected const PERF_SAMPLE_PERCENT = 10;

    // Set only during shadow comparison so both resolvers use one instant.
    private ?Carbon $expirationResolutionClock = null;

    // A racing mutation gets one legacy retry, never an unbounded read loop.
    private bool $skipSnapshotHealth = false;

    public function getGexLevels(Request $request)
    {
        $startedAt = microtime(true);
        $symbol = Symbols::canon((string) $request->query('symbol', 'SPY'));
        if (! Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }
        $timeframe = $request->query('timeframe', '90d');
        $forceRefresh = (bool) $request->boolean('refresh', false);
        $workRuns = app(WorkRunCoordinator::class);
        $bootstrapPolicy = app(SymbolBootstrapPolicy::class);
        $bootstrap = app(SymbolBootstrapCoordinator::class);
        $parameters = $bootstrapPolicy->claimParameters();
        $activeRun = $workRuns->active('symbol_bootstrap', $symbol, $parameters);
        $authoritativeRun = null;
        $statusRun = $activeRun;
        $bootstrapPayload = null;
        if ($bootstrapPolicy->enabled()) {
            $authoritativeRun = $bootstrap->authoritativeWorkRun(
                $symbol,
                (string) $parameters['session_date']
            );
            $statusRun ??= $authoritativeRun;
            if (! $statusRun) {
                $statusRun = $bootstrap->latestForSymbol(
                    $symbol,
                    (string) $parameters['session_date']
                )?->workRun;
            }
            $bootstrapPayload = $statusRun ? $bootstrap->payload($statusRun) : null;
        }
        $runPayload = $statusRun
            ? $workRuns->payload($statusRun)
            : null;

        // A new symbol must not expose in-place candidate rows before the
        // fast phase signs its exact catalog coverage. When an older complete
        // head exists, continue serving that last-good data while the newer
        // run prepares.
        if ($bootstrapPayload && ! $bootstrapPayload['fast_ready'] && ! $authoritativeRun) {
            $terminal = (bool) $bootstrapPayload['terminal'];
            $headers = [];
            if (! $terminal && $bootstrapPayload['retry_after_seconds'] !== null) {
                $headers['Retry-After'] = (string) $bootstrapPayload['retry_after_seconds'];
            }

            return response()->json([
                'error' => $terminal
                    ? "Initial data failed for {$symbol}"
                    : "Initial data is preparing for {$symbol}",
                'status' => $terminal ? 'failed' : 'fetching',
                'run' => $runPayload,
                'bootstrap' => $bootstrapPayload,
            ], $terminal ? 503 : 202, $headers);
        }
        if ($bootstrapPayload && $bootstrapPayload['full_ready'] && $bootstrapPayload['no_options']) {
            return response()->json([
                'status' => 'no_options',
                'symbol' => $symbol,
                'run' => $runPayload,
                'bootstrap' => $bootstrapPayload,
                'strike_data' => [],
            ]);
        }

        $manifest = null;
        $manifestSelection = null;
        $manifestCacheKey = null;
        $health = EodSnapshotHealth::readsEnabled() && ! $this->skipSnapshotHealth
            ? app(EodSnapshotHealth::class) : null;
        $snapshotCache = app(GexSnapshotCache::class);
        if ($health !== null) {
            $policy = $health->policy();
            // Dirty facts are allowed only for an existing last-good payload.
            // They must never choose dates for newly read in-place raw rows.
            $manifest = $health->read($symbol, $policy, requireCurrent: false);
            if ($manifest !== null) {
                $clock = Carbon::now();
                $manifestSelection = app(GexExpirationUniverse::class)->resolveFromCatalog(
                    $manifest['catalog'], $timeframe, $this->uiTimeframes, $clock
                );
                $manifestCacheKey = $snapshotCache->key($symbol, $timeframe, $manifest, $clock);
                $warm = ! $forceRefresh ? $snapshotCache->get($manifestCacheKey) : null;
                if ($warm !== null) {
                    $this->logPerf($symbol, $timeframe, $startedAt, [
                        'status_code' => 200, 'result' => 'manifest_cache_hit',
                        'cache_hit' => true, 'force_refresh' => false,
                        'expiration_count' => count($manifestSelection['expiration_ids']),
                        'strike_count' => count($warm['strike_data'] ?? []),
                        'data_date' => $warm['data_date'] ?? null,
                        'cache_version' => $manifest['cache_version'],
                    ]);
                    if ($bootstrapPayload !== null) {
                        $warm['run'] = $runPayload;
                        $warm['bootstrap'] = $bootstrapPayload;
                    }

                    return response()->json($warm, 200);
                }
                if ($manifest['dirty']) {
                    $manifest = $manifestSelection = $manifestCacheKey = null;
                }
            }
            if ($manifest === null) {
                $health->requestRebuild($symbol, $policy);
            }
        }

        $expirationSelection = $manifestSelection ?? $this->resolveGexExpirationSelection($symbol, $timeframe);
        $timeframeExpirations = $expirationSelection['timeframe_expirations'];
        $dates = $timeframeExpirations[$timeframe] ?? [];

        if (empty($dates)) {
            $payload = array_merge([
                'error' => "No expirations found for {$symbol}/{$timeframe}",
                'status' => $activeRun ? 'queued' : 'missing',
                'run' => $runPayload,
                'available_timeframes' => array_keys($timeframeExpirations),
                'timeframe_expirations' => $timeframeExpirations,
            ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []);
            $this->logPerf($symbol, $timeframe, $startedAt, [
                'status_code' => 404,
                'result' => 'no_expirations',
                'cache_hit' => false,
                'force_refresh' => $forceRefresh,
                'expiration_count' => 0,
            ]);

            return response()->json($payload, 404);
        }

        $expirationIds = $expirationSelection['expiration_ids'] ?? OptionExpiration::where('symbol', $symbol)
            ->whereIn('expiration_date', $dates)
            ->pluck('id')
            ->toArray();

        $selector = app(EodSnapshotSelector::class);
        $anchorDate = $manifest['policy']['anchor_date'] ?? $selector->resolvedAnchorDate();
        $latestDate = $manifest === null ? OptionChainData::whereIn('expiration_id', $expirationIds)
            ->whereDate('data_date', '<=', $anchorDate)
            ->max('data_date') : collect($expirationIds)
            ->map(fn ($id) => $manifest['expirations'][$id]['latest_unbounded_date'])
            ->filter()->max();

        if ($manifest === null && ! $latestDate) {
            $latestDate = OptionChainData::whereIn('expiration_id', $expirationIds)->max('data_date');
        }
        if ($manifest === null && $latestDate && ! $this->hasUsableGreeks($expirationIds, $latestDate)) {
            $latestDate = OptionChainData::whereIn('expiration_id', $expirationIds)
                ->whereNotNull('gamma')
                ->where('gamma', '!=', 0)
                ->max('data_date')
                ?: $latestDate;
        }
        if (! $latestDate) {
            $payload = array_merge([
                'error' => "No data for {$symbol}/{$timeframe}",
                'status' => $activeRun ? 'fetching' : 'incomplete',
                'run' => $runPayload,
            ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []);
            $this->logPerf($symbol, $timeframe, $startedAt, [
                'status_code' => 404,
                'result' => 'no_data',
                'cache_hit' => false,
                'force_refresh' => $forceRefresh,
                'expiration_count' => count($expirationIds),
            ]);

            return response()->json($payload, 404);
        }

        // The publication token is advanced by the last job in a successful
        // EOD chain. In-place writes made by a failed chain must not create a
        // new readable cache generation.
        $version = $manifest['cache_version']
            ?? app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, $symbol);
        $cacheKey = $manifestCacheKey ?? "gex:levels:v4:{$symbol}:{$timeframe}:{$version}";
        // The manifest warm path already performed its one cache retrieval.
        // Legacy v4 entries carry no anchor, ratio or calendar identity. An
        // enabled fallback must recompute rather than reuse an unproven entry.
        $publishedPayload = $manifest === null && ! EodSnapshotHealth::readsEnabled()
            ? Cache::get($cacheKey) : null;
        $cacheHit = ! $forceRefresh && is_array($publishedPayload);
        $payload = $cacheHit ? $publishedPayload : $this->buildGexPayload(
            $symbol, $timeframe, $dates, $timeframeExpirations,
            $expirationIds, $anchorDate, $manifest
        );

        if ($manifest !== null) {
            // A mutation may have started after the initial manifest read.
            // Do not publish rows read across that transition into an older
            // immutable response generation. Retry once through legacy reads.
            $after = $health->head($symbol);
            if ($after === null || $after['dirty']
                || $after['revision'] !== $manifest['revision']
                || $after['cache_version'] !== $manifest['cache_version']
                || $snapshotCache->key($symbol, $timeframe, $manifest) !== $cacheKey) {
                $this->skipSnapshotHealth = true;
                try {
                    return $this->getGexLevels($request);
                } finally {
                    $this->skipSnapshotHealth = false;
                }
            }
            if ($payload) {
                $snapshotCache->putIfMissing($cacheKey, $payload);
            }
        } elseif ($payload && $publishedPayload === null && ! EodSnapshotHealth::readsEnabled()) {
            // Rollback retains the legacy cache generation. An enabled but
            // uncertified fallback must not seed it from partial raw writes.
            // add() preserves an existing last-good value in a read race.
            Cache::add($cacheKey, $payload, now()->addHours(self::CACHE_HOURS));
        }

        if (! $payload) {
            $fallback = array_merge([
                'error' => "No data for {$symbol}/{$timeframe}",
                'status' => $activeRun ? 'fetching' : 'incomplete',
                'run' => $runPayload,
            ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []);
            $this->logPerf($symbol, $timeframe, $startedAt, [
                'status_code' => 404,
                'result' => 'empty_payload',
                'cache_hit' => $cacheHit,
                'force_refresh' => $forceRefresh,
                'expiration_count' => count($expirationIds),
                'cache_version' => $version,
            ]);

            return response()->json($fallback, 404);
        }

        $this->logPerf($symbol, $timeframe, $startedAt, [
            'status_code' => 200,
            'result' => 'ok',
            'cache_hit' => $cacheHit,
            'force_refresh' => $forceRefresh,
            'expiration_count' => count($expirationIds),
            'strike_count' => count($payload['strike_data'] ?? []),
            'data_date' => $payload['data_date'] ?? null,
            'cache_version' => $version,
        ]);

        if ($bootstrapPayload !== null) {
            $payload['run'] = $runPayload;
            $payload['bootstrap'] = $bootstrapPayload;
        }

        return response()->json($payload, 200);
    }

    protected function buildGexPayload(
        string $symbol,
        string $timeframe,
        array $dates,
        array $timeframeExpirations,
        array $expirationIds,
        ?string $anchorDate = null,
        ?array $manifest = null
    ): ?array {
        $selector = app(EodSnapshotSelector::class);
        $todayData = $manifest === null
            ? $selector->selectedRows($expirationIds, ['option_chain_data.*'], $anchorDate)
            : $selector->selectedRows($expirationIds, ['option_chain_data.*'], $anchorDate, null, $manifest);

        if ($todayData->isEmpty()) {
            return null;
        }

        // 2) Core metrics
        $callOI = $todayData->where('option_type', 'call')->sum('open_interest');
        $putOI = $todayData->where('option_type', 'put')->sum('open_interest');
        $callVol = $todayData->where('option_type', 'call')->sum('volume');
        $putVol = $todayData->where('option_type', 'put')->sum('volume');
        $totalOI = $callOI + $putOI;

        $pct = fn ($x) => $totalOI > 0 ? round($x / $totalOI * 100, 2) : 0;

        // 3) Net-GEX per strike
        // Formula: gamma × OI × 100 (contract multiplier) × spot²
        // Spot² converts raw gamma to dollar GEX (matches industry-standard scaling).
        $strikesRaw = [];
        $currentTotals = [];
        foreach ($todayData as $opt) {
            $s = $opt->strike;
            // Accumulate once per contract. Filtering the entire chain four
            // times for every strike makes large timeframes quadratic.
            $side = $opt->option_type;
            $currentTotals[$s][$side]['oi'] = ($currentTotals[$s][$side]['oi'] ?? 0) + $opt->open_interest;
            $currentTotals[$s][$side]['volume'] = ($currentTotals[$s][$side]['volume'] ?? 0) + $opt->volume;
            $spot = (float) ($opt->underlying_price ?? 0);
            $spotSq = $spot > 0 ? $spot * $spot : 1.0;
            $gex = ($opt->gamma ?? 0) * $opt->open_interest * 100 * $spotSq;
            if ($opt->option_type === 'call') {
                $strikesRaw[$s]['call_gamma'] = ($strikesRaw[$s]['call_gamma'] ?? 0) + $gex;
            } else {
                $strikesRaw[$s]['put_gamma'] = ($strikesRaw[$s]['put_gamma'] ?? 0) + $gex;
            }
        }

        $strikeList = [];
        foreach ($strikesRaw as $strike => $g) {
            $strikeList[] = [
                'strike' => $strike,
                'net_gex' => ($g['call_gamma'] ?? 0) - ($g['put_gamma'] ?? 0),
                'call_gex' => $g['call_gamma'] ?? 0,
                'put_gex' => $g['put_gamma'] ?? 0,
            ];
        }
        usort($strikeList, fn ($a, $b) => $a['strike'] <=> $b['strike']);

        // 4) HVL & walls
        $HVL = $this->findHVL($strikeList);
        [$c1, $c2, $c3] = $this->getTop3($strikeList, 'call');
        [$p1, $p2, $p3] = $this->getTop3($strikeList, 'put');

        // 5) Prepare prior snapshots
        $latestDate = $todayData->max('data_date');

        $latest = Carbon::parse($latestDate, 'America/New_York');
        $ageDays = $latest->diffInDays(
            Carbon::now('America/New_York')->startOfDay()
        );

        // find actual previous snapshot date (not just "latest - 1 day")
        $prevDate = OptionChainData::whereIn('expiration_id', $expirationIds)
            ->where('data_date', '<', $latestDate)
            ->max('data_date');

        // find "week ago" snapshot - last snapshot on or before latest - 7d
        $weekCutoff = Carbon::parse($latestDate)->subWeek()->toDateString();
        $prevWeekDate = OptionChainData::whereIn('expiration_id', $expirationIds)
            ->where('data_date', '<=', $weekCutoff)
            ->max('data_date');

        $fetchPrior = function (?string $date) use ($expirationIds) {
            if (! $date) {
                return collect();
            }

            return OptionChainData::whereIn('expiration_id', $expirationIds)
                ->where('data_date', $date)
                ->select(
                    'strike',
                    'option_type',
                    DB::raw('SUM(open_interest) as oi'),
                    DB::raw('SUM(volume)       as vol')
                )
                ->groupBy('strike', 'option_type')
                ->get()
                ->groupBy('strike');
        };

        $dayAgo = $fetchPrior($prevDate);
        $weekAgo = $fetchPrior($prevWeekDate);

        // for the response payload, keep your previous naming
        $yesterday = $prevDate;
        $lastWeek = $prevWeekDate;
        $prevGapTradingDays = $this->tradingWeekdayGap($latestDate, $prevDate);
        $prevWeekGapTradingDays = $this->tradingWeekdayGap($latestDate, $prevWeekDate);
        $prevIsStale = $prevGapTradingDays !== null && $prevGapTradingDays > 1;

        // 6) Assemble full strike data with call/put deltas
        $fullStrike = [];

        $totCallOiDelta = 0;
        $totPutOiDelta = 0;
        $totCallVolDelta = 0;
        $totPutVolDelta = 0;
        foreach ($strikeList as $row) {
            $s = $row['strike'];

            // current totals
            $curCallOi = $currentTotals[$s]['call']['oi'] ?? 0;
            $curPutOi = $currentTotals[$s]['put']['oi'] ?? 0;
            $curCallVol = $currentTotals[$s]['call']['volume'] ?? 0;
            $curPutVol = $currentTotals[$s]['put']['volume'] ?? 0;

            // prior day
            $pd = $dayAgo->get($s, collect());
            $pCall = $pd->firstWhere('option_type', 'call');
            $pPut = $pd->firstWhere('option_type', 'put');
            $pCallOi = $pCall?->oi ?? 0;
            $pPutOi = $pPut?->oi ?? 0;
            $pCallVol = $pCall?->vol ?? 0;
            $pPutVol = $pPut?->vol ?? 0;

            // prior week
            $pw = $weekAgo->get($s, collect());
            $wCall = $pw->firstWhere('option_type', 'call');
            $wPut = $pw->firstWhere('option_type', 'put');
            $wCallOi = $wCall?->oi ?? 0;
            $wPutOi = $wPut?->oi ?? 0;
            $wCallVol = $wCall?->vol ?? 0;
            $wPutVol = $wPut?->vol ?? 0;

            // deltas
            $dCallOi = $curCallOi - $pCallOi;
            $dPutOi = $curPutOi - $pPutOi;
            $dCallVol = $curCallVol - $pCallVol;
            $dPutVol = $curPutVol - $pPutVol;

            $totCallOiDelta += $dCallOi;
            $totPutOiDelta += $dPutOi;
            $totCallVolDelta += $dCallVol;
            $totPutVolDelta += $dPutVol;

            $pctOr0 = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 2) : 0;

            $fullStrike[] = [
                'strike' => $s,
                'net_gex' => $row['net_gex'],
                'call_gex' => $row['call_gex'],
                'put_gex' => $row['put_gex'],
                'call_oi_delta' => $dCallOi,
                'put_oi_delta' => $dPutOi,
                'call_oi_delta_pct' => $pctOr0($dCallOi, $pCallOi),
                'put_oi_delta_pct' => $pctOr0($dPutOi, $pPutOi),
                'call_vol_delta' => $dCallVol,
                'put_vol_delta' => $dPutVol,
                'call_vol_delta_pct' => $pctOr0($dCallVol, $pCallVol),
                'put_vol_delta_pct' => $pctOr0($dPutVol, $pPutVol),
                'call_oi_wow' => $curCallOi - $wCallOi,
                'put_oi_wow' => $curPutOi - $wPutOi,
                'call_vol_wow' => $curCallVol - $wCallVol,
                'put_vol_wow' => $curPutVol - $wPutVol,
            ];
        }

        $totalOiDelta = $totCallOiDelta + $totPutOiDelta;
        $totalVolDelta = $totCallVolDelta + $totPutVolDelta;

        $gs = Cache::get("gamma_strength:{$symbol}:{$latestDate}");

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'data_date' => $latestDate,
            'data_age_days' => $ageDays,
            'expiration_dates' => $dates,
            'available_timeframes' => array_keys($timeframeExpirations),
            'timeframe_expirations' => $timeframeExpirations,
            'hvl' => $HVL,
            'call_resistance' => $c1,
            'call_wall_2' => $c2,
            'call_wall_3' => $c3,
            'put_support' => $p1,
            'put_wall_2' => $p2,
            'put_wall_3' => $p3,
            'call_open_interest_total' => $callOI,
            'put_open_interest_total' => $putOI,
            'call_interest_percentage' => $pct($callOI),
            'put_interest_percentage' => $pct($putOI),
            'call_volume_total' => $callVol,
            'put_volume_total' => $putVol,
            'pcr_volume' => $callVol > 0 ? round($putVol / $callVol, 2) : null,
            'total_oi_delta' => $totalOiDelta,
            'total_volume_delta' => $totalVolDelta,
            'date_prev' => $yesterday,
            'date_prev_gap_trading_days' => $prevGapTradingDays,
            'date_prev_is_stale' => $prevIsStale,
            'date_prev_week' => $lastWeek,
            'date_prev_week_gap_trading_days' => $prevWeekGapTradingDays,
            'strike_data' => $fullStrike,
            'regime_strength' => $gs['strength'] ?? null,
            'gamma_sign' => $gs['sign'] ?? null,
            'regime_source_meta' => $gs['source_meta'] ?? null,
        ];
    }

    protected function logPerf(string $symbol, string $timeframe, float $startedAt, array $context = []): void
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $payload = array_merge([
            'endpoint' => 'gexLevels',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'duration_ms' => $durationMs,
        ], $context);

        if ($durationMs >= self::PERF_SLOW_MS) {
            Log::warning('eod.perf.slow', $payload);

            return;
        }

        if (mt_rand(1, 100) <= self::PERF_SAMPLE_PERCENT) {
            Log::info('eod.perf', $payload);
        }
    }

    /** @return array{timeframe_expirations:array,expiration_ids:?array} */
    protected function resolveGexExpirationSelection(string $symbol, string $requestedTimeframe): array
    {
        if (! config('gex_performance.expiration_universe_enabled', false)) {
            // Preserve the original queries and lazy ID lookup for rollback.
            return [
                'timeframe_expirations' => $this->getTimeframeExpirations($symbol, $requestedTimeframe),
                'expiration_ids' => null,
            ];
        }

        $clock = Carbon::now();
        $resolver = app(GexExpirationUniverse::class);
        $candidate = $resolver->resolve($symbol, $requestedTimeframe, $this->uiTimeframes, $clock);
        if (! config('gex_performance.expiration_shadow_enabled', false)) {
            return $candidate;
        }

        $previousClock = $this->expirationResolutionClock;
        $this->expirationResolutionClock = $clock;
        try {
            $legacyDates = $this->getTimeframeExpirations($symbol, $requestedTimeframe);
            $requestedDates = $legacyDates[$requestedTimeframe] ?? [];
            $legacy = [
                'timeframe_expirations' => $legacyDates,
                'expiration_ids' => $requestedDates === [] ? [] : OptionExpiration::where('symbol', $symbol)
                    ->whereIn('expiration_date', $requestedDates)->pluck('id')->toArray(),
            ];
        } finally {
            $this->expirationResolutionClock = $previousClock;
        }

        $comparison = $resolver->compareSelections($legacy, $candidate);
        $context = array_merge($comparison, [
            'symbol' => $symbol,
            'timeframe' => $requestedTimeframe,
            'legacy_expiration_count' => count($legacy['expiration_ids']),
            'candidate_expiration_count' => count($candidate['expiration_ids']),
        ]);
        if (! $comparison['matches']) {
            Log::warning('gex.expiration_shadow.mismatch', $context);

            return $legacy;
        }
        Log::info('gex.expiration_shadow.match', $context);

        return $candidate;
    }

    /** Build a map of timeframe => catalog expiration dates. */
    protected function getTimeframeExpirations(string $symbol, string $requestedTimeframe): array
    {
        $candidates = array_unique(array_merge($this->uiTimeframes, [$requestedTimeframe]));
        $availability = [];

        foreach ($candidates as $tf) {
            $dates = $this->resolveExpirationDates($symbol, $tf);
            if (! empty($dates)) {
                $availability[$tf] = $dates;
            }
        }

        return $availability;
    }

    /**
     * Turn symbol + timeframe into a list of expiration_ids.
     */
    protected function resolveExpirationDates(string $symbol, string $tf): array
    {
        // UI labels are DTE-ish (0DTE, 1DTE, 1W, 2W, 1M, 3M), so use
        // trading-day lookaheads instead of calendar-day lookaheads.
        $map = [
            '0d' => 0, '1d' => 1, '7d' => 5, '14d' => 10, '21d' => 15, '30d' => 21,
            '45d' => 32, '60d' => 43, '90d' => 64,
        ];
        if (isset($map[$tf])) {
            return $this->getExpirationsWithinDays($symbol, $map[$tf]);
        }
        if ($tf === 'monthly') {
            $d = $this->thirdFriday($this->expirationNow());
            // if the third Friday is in the past, take next month's third Friday
            if ($d->lt($this->expirationNow()->startOfDay())) {
                $d = $this->thirdFriday($this->expirationNow()->addMonth());
            }

            return \App\Models\OptionExpiration::where('symbol', $symbol)
                ->whereDate('expiration_date', $d->toDateString())
                ->orderBy('expiration_date')
                ->pluck('expiration_date')
                ->unique()->values()->toArray();
        }

        // default
        return $this->getExpirationsWithinDays($symbol, 14);
    }

    protected function thirdFriday(Carbon $dt): Carbon
    {
        // third Friday of the month of $dt
        $first = $dt->copy()->startOfMonth();
        // weekday() 0=Sun..6=Sat, we want Friday (5)
        $firstFriday = $first->copy()->next(Carbon::FRIDAY);
        if ($first->isFriday()) {
            $firstFriday = $first;
        }

        // third Friday = first Friday + 2 weeks
        return $firstFriday->copy()->addWeeks(2);
    }

    // Helper: find expirations within X days
    protected function getExpirationsWithinDays(string $symbol, int $days): array
    {
        $anchorNy = $this->expirationNow('America/New_York')->startOfDay();
        if ($anchorNy->isWeekend()) {
            $anchorNy = $anchorNy->previousWeekday()->startOfDay();
        }

        $startDate = $anchorNy->toDateString();
        $endDate = $days > 0
            ? $anchorNy->copy()->addWeekdays($days)->toDateString()
            : $startDate;

        return OptionExpiration::where('symbol', $symbol)
            ->whereBetween('expiration_date', [$startDate, $endDate])
            ->orderBy('expiration_date')
            ->pluck('expiration_date')
            ->unique()
            ->values()
            ->toArray();
    }

    private function expirationNow(?string $timezone = null): Carbon
    {
        return $this->expirationResolutionClock
            ? $this->expirationResolutionClock->copy()->setTimezone($timezone ?? date_default_timezone_get())
            : Carbon::now($timezone);
    }

    // Helper: find next monthly expiration
    protected function getNextMonthlyExpiration($symbol)
    {
        $nextMonthlyFriday = Carbon::now()
            ->startOfMonth()
            ->addWeeks(2)
            ->next(Carbon::FRIDAY);

        return OptionExpiration::where('symbol', $symbol)
            ->whereDate('expiration_date', $nextMonthlyFriday->toDateString())
            ->pluck('expiration_date')
            ->unique()
            ->values()
            ->toArray();
    }

    protected function findHVL(array $strikeData)
    {
        $HVL = null;
        for ($i = 0; $i < count($strikeData) - 1; $i++) {
            if ($strikeData[$i]['net_gex'] < 0 && $strikeData[$i + 1]['net_gex'] >= 0) {
                $HVL = $strikeData[$i + 1]['strike'];
                break;
            }
        }
        if (! $HVL && count($strikeData) > 0) {
            $HVL = $strikeData[0]['strike'];
        }

        return $HVL;
    }

    protected function getTop3(array $strikeData, string $type)
    {
        if ($type === 'call') {
            $filtered = array_filter($strikeData, fn ($d) => $d['net_gex'] > 0);
            usort($filtered, fn ($a, $b) => $b['net_gex'] <=> $a['net_gex']);
        } else {
            $filtered = array_filter($strikeData, fn ($d) => $d['net_gex'] < 0);
            usort($filtered, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']));
        }

        $level1 = $filtered[0]['strike'] ?? null;
        $level2 = $filtered[1]['strike'] ?? null;
        $level3 = $filtered[2]['strike'] ?? null;

        return [$level1, $level2, $level3];
    }

    protected function hasUsableGreeks(array $expirationIds, string $date): bool
    {
        return OptionChainData::whereIn('expiration_id', $expirationIds)
            ->whereDate('data_date', $date)
            ->whereNotNull('gamma')
            ->where('gamma', '!=', 0)
            ->exists();
    }

    protected function tradingWeekdayGap(?string $latestDate, ?string $priorDate): ?int
    {
        if (! $latestDate || ! $priorDate) {
            return null;
        }

        $latest = Carbon::parse($latestDate, 'America/New_York')->startOfDay();
        $prior = Carbon::parse($priorDate, 'America/New_York')->startOfDay();
        if ($prior->gte($latest)) {
            return 0;
        }

        $gap = 0;
        $cursor = $prior->copy();
        while ($cursor->lt($latest)) {
            $cursor->addDay();
            if (! $cursor->isWeekend()) {
                $gap++;
            }
        }

        return $gap;
    }
}
