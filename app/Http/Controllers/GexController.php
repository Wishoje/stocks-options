<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\OptionExpiration;
use App\Models\OptionChainData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GexController extends Controller
{
    // Keep in sync with the frontend timeframe options
    protected array $uiTimeframes = ['0d','1d','7d','14d','30d','90d'];
    protected const CACHE_HOURS = 8;
    protected const PERF_SLOW_MS = 1200;
    protected const PERF_SAMPLE_PERCENT = 10;

    public function getGexLevels(Request $request)
    {
        $startedAt = microtime(true);
        $symbol    = strtoupper($request->query('symbol', 'SPY'));
        $timeframe = $request->query('timeframe', '90d');
        $forceRefresh = (bool) $request->boolean('refresh', false);

        // Resolve dates + IDs for you
        $timeframeExpirations = $this->getTimeframeExpirations($symbol, $timeframe);
        $dates = $timeframeExpirations[$timeframe] ?? [];

        if (empty($dates)) {
            $this->kickoffSymbolPrime($symbol);
            $payload = [
                'error' => "No expirations found for {$symbol}/{$timeframe}",
                'status' => 'queued',
                'available_timeframes'  => array_keys($timeframeExpirations),
                'timeframe_expirations' => $timeframeExpirations,
            ];
            $this->logPerf($symbol, $timeframe, $startedAt, [
                'status_code' => 404,
                'result' => 'no_expirations',
                'cache_hit' => false,
                'force_refresh' => $forceRefresh,
                'expiration_count' => 0,
            ]);

            return response()->json($payload, 404);
        }

        $expirationIds = OptionExpiration::where('symbol', $symbol)
            ->whereIn('expiration_date', $dates)
            ->pluck('id')
            ->toArray();

        $latestDate = OptionChainData::whereIn('expiration_id', $expirationIds)->max('data_date');
        if (!$latestDate) {
            $this->kickoffSymbolPrime($symbol);
            $payload = [
                'error' => "No data for {$symbol}/{$timeframe}",
                'status' => 'fetching',
            ];
            $this->logPerf($symbol, $timeframe, $startedAt, [
                'status_code' => 404,
                'result' => 'no_data',
                'cache_hit' => false,
                'force_refresh' => $forceRefresh,
                'expiration_count' => count($expirationIds),
            ]);

            return response()->json($payload, 404);
        }

        $latestStamp = OptionChainData::whereIn('expiration_id', $expirationIds)
            ->where('data_date', $latestDate)
            ->max('data_timestamp');

        $dateSig = substr(sha1(implode(',', $dates)), 0, 12);
        $version = substr(sha1(implode('|', [
            $symbol,
            $timeframe,
            $latestDate,
            (string) $latestStamp,
            count($expirationIds),
            $dateSig,
        ])), 0, 20);

        $cacheKey = "gex:levels:v2:{$symbol}:{$timeframe}:{$version}";
        $cacheHit = !$forceRefresh && Cache::has($cacheKey);

        if ($forceRefresh) {
            $payload = $this->buildGexPayload(
                $symbol,
                $timeframe,
                $dates,
                $timeframeExpirations,
                $expirationIds
            );
            if ($payload) {
                Cache::put($cacheKey, $payload, now()->addHours(self::CACHE_HOURS));
            }
        } else {
            $payload = Cache::remember($cacheKey, now()->addHours(self::CACHE_HOURS), function () use (
                $symbol,
                $timeframe,
                $dates,
                $timeframeExpirations,
                $expirationIds
            ) {
                return $this->buildGexPayload(
                    $symbol,
                    $timeframe,
                    $dates,
                    $timeframeExpirations,
                    $expirationIds
                );
            });
        }

        if (!$payload) {
            $this->kickoffSymbolPrime($symbol);
            $fallback = [
                'error' => "No data for {$symbol}/{$timeframe}",
                'status' => 'fetching',
            ];
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

        return response()->json($payload, 200);
    }

    protected function buildGexPayload(
        string $symbol,
        string $timeframe,
        array $dates,
        array $timeframeExpirations,
        array $expirationIds
    ): ?array {
        $latestDates = OptionChainData::select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        $todayData = OptionChainData::joinSub($latestDates, 'ld', function ($join) {
                $join->on('option_chain_data.expiration_id', '=', 'ld.expiration_id')
                    ->on('option_chain_data.data_date',      '=', 'ld.max_date');
            })
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->get();

        if ($todayData->isEmpty()) {
            return null;
        }

        // 2) Core metrics
        $callOI  = $todayData->where('option_type','call')->sum('open_interest');
        $putOI   = $todayData->where('option_type','put' )->sum('open_interest');
        $callVol = $todayData->where('option_type','call')->sum('volume');
        $putVol  = $todayData->where('option_type','put' )->sum('volume');
        $totalOI = $callOI + $putOI;

        $pct = fn($x) => $totalOI > 0 ? round($x / $totalOI * 100, 2) : 0;

        // 3) Net-GEX per strike
        $strikesRaw = [];
        foreach ($todayData as $opt) {
            $s = $opt->strike;
            $strikesRaw[$s]['call_gamma'] = ($strikesRaw[$s]['call_gamma'] ?? 0)
                + ($opt->option_type === 'call' ? $opt->gamma * $opt->open_interest * 100 : 0);
            $strikesRaw[$s]['put_gamma']  = ($strikesRaw[$s]['put_gamma']  ?? 0)
                + ($opt->option_type === 'put'  ? $opt->gamma * $opt->open_interest * 100 : 0);
        }

        $strikeList = [];
        foreach ($strikesRaw as $strike => $g) {
            $strikeList[] = [
                'strike'  => $strike,
                'net_gex' => $g['call_gamma'] - $g['put_gamma'],
            ];
        }
        usort($strikeList, fn($a, $b) => $a['strike'] <=> $b['strike']);

        // 4) HVL & walls
        $HVL          = $this->findHVL($strikeList);
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
            if (!$date) {
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

        $dayAgo  = $fetchPrior($prevDate);
        $weekAgo = $fetchPrior($prevWeekDate);

        // for the response payload, keep your previous naming
        $yesterday = $prevDate;
        $lastWeek  = $prevWeekDate;

        // 6) Assemble full strike data with call/put deltas
        $fullStrike = [];

        $totCallOiDelta   = 0;
        $totPutOiDelta    = 0;
        $totCallVolDelta  = 0;
        $totPutVolDelta   = 0;
        foreach ($strikeList as $row) {
            $s = $row['strike'];

            // current totals
            $curCallOi  = $todayData->where('strike', $s)->where('option_type', 'call')->sum('open_interest');
            $curPutOi   = $todayData->where('strike', $s)->where('option_type', 'put' )->sum('open_interest');
            $curCallVol = $todayData->where('strike', $s)->where('option_type', 'call')->sum('volume');
            $curPutVol  = $todayData->where('strike', $s)->where('option_type', 'put' )->sum('volume');

            // prior day
            $pd = $dayAgo->get($s, collect());
            $pCall  = $pd->firstWhere('option_type', 'call');
            $pPut   = $pd->firstWhere('option_type', 'put');
            $pCallOi  = $pCall?->oi ?? 0;
            $pPutOi   = $pPut?->oi ?? 0;
            $pCallVol = $pCall?->vol ?? 0;
            $pPutVol  = $pPut?->vol ?? 0;

            // prior week
            $pw = $weekAgo->get($s, collect());
            $wCall  = $pw->firstWhere('option_type', 'call');
            $wPut   = $pw->firstWhere('option_type', 'put');
            $wCallOi  = $wCall?->oi ?? 0;
            $wPutOi   = $wPut?->oi ?? 0;
            $wCallVol = $wCall?->vol ?? 0;
            $wPutVol  = $wPut?->vol ?? 0;

            // deltas
            $dCallOi  = $curCallOi  - $pCallOi;
            $dPutOi   = $curPutOi   - $pPutOi;
            $dCallVol = $curCallVol - $pCallVol;
            $dPutVol  = $curPutVol  - $pPutVol;

            $totCallOiDelta  += $dCallOi;
            $totPutOiDelta   += $dPutOi;
            $totCallVolDelta += $dCallVol;
            $totPutVolDelta  += $dPutVol;

            $pctOr0 = fn($n, $d) => $d > 0 ? round($n / $d * 100, 2) : 0;

            $fullStrike[] = [
                'strike'              => $s,
                'net_gex'             => $row['net_gex'],
                'call_oi_delta'       => $dCallOi,
                'put_oi_delta'        => $dPutOi,
                'call_oi_delta_pct'   => $pctOr0($dCallOi, $pCallOi),
                'put_oi_delta_pct'    => $pctOr0($dPutOi,  $pPutOi),
                'call_vol_delta'      => $dCallVol,
                'put_vol_delta'       => $dPutVol,
                'call_vol_delta_pct'  => $pctOr0($dCallVol, $pCallVol),
                'put_vol_delta_pct'   => $pctOr0($dPutVol,  $pPutVol),
                'call_oi_wow'         => $curCallOi  - $wCallOi,
                'put_oi_wow'          => $curPutOi   - $wPutOi,
                'call_vol_wow'        => $curCallVol - $wCallVol,
                'put_vol_wow'         => $curPutVol  - $wPutVol,
            ];
        }

        $totalOiDelta  = $totCallOiDelta + $totPutOiDelta;
        $totalVolDelta = $totCallVolDelta + $totPutVolDelta;

        $date = Carbon::now('America/New_York')->isWeekend()
            ? Carbon::now('America/New_York')->previousWeekday()->toDateString()
            : Carbon::now('America/New_York')->toDateString();

        $gs = Cache::get("gamma_strength:{$symbol}:{$date}");

        return [
            'symbol'                   => $symbol,
            'timeframe'                => $timeframe,
            'data_date'                => $latestDate,
            'data_age_days'            => $ageDays,
            'expiration_dates'         => $dates,
            'available_timeframes'     => array_keys($timeframeExpirations),
            'timeframe_expirations'    => $timeframeExpirations,
            'hvl'                      => $HVL,
            'call_resistance'          => $c1,
            'call_wall_2'              => $c2,
            'call_wall_3'              => $c3,
            'put_support'              => $p1,
            'put_wall_2'               => $p2,
            'put_wall_3'               => $p3,
            'call_open_interest_total' => $callOI,
            'put_open_interest_total'  => $putOI,
            'call_interest_percentage' => $pct($callOI),
            'put_interest_percentage'  => $pct($putOI),
            'call_volume_total'        => $callVol,
            'put_volume_total'         => $putVol,
            'pcr_volume'               => $callVol > 0 ? round($putVol / $callVol, 2) : null,
            'total_oi_delta'           => $totalOiDelta,
            'total_volume_delta'       => $totalVolDelta,
            'date_prev'                => $yesterday,
            'date_prev_week'           => $lastWeek,
            'strike_data'              => $fullStrike,
            'regime_strength'          => $gs['strength'] ?? null,
            'gamma_sign'               => $gs['sign']     ?? null,
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

    /**
     * Build a map of timeframe => expiration dates (only those with data).
     */
    protected function getTimeframeExpirations(string $symbol, string $requestedTimeframe): array
    {
        $candidates = array_unique(array_merge($this->uiTimeframes, [$requestedTimeframe]));
        $availability = [];

        foreach ($candidates as $tf) {
            $dates = $this->resolveExpirationDates($symbol, $tf);
            if (!empty($dates)) {
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
        $map = [
            '0d'=>0,'1d'=>1,'7d'=>7,'14d'=>14,'21d'=>21,'30d'=>30,
            '45d'=>45,'60d'=>60,'90d'=>90,
        ];
        if (isset($map[$tf])) {
            return $this->getExpirationsWithinDays($symbol, $map[$tf]);
        }
        if ($tf === 'monthly') {
            $d = $this->thirdFriday(\Carbon\Carbon::now());
            // if the third Friday is in the past, take next month's third Friday
            if ($d->lt(\Carbon\Carbon::now()->startOfDay())) {
                $d = $this->thirdFriday(\Carbon\Carbon::now()->addMonth());
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
        $now       = now();
        $startDate = $now->toDateString();
        $endDate   = $now->copy()->addDays($days)->toDateString();

        return OptionExpiration::where('symbol', $symbol)
            ->whereBetween('expiration_date', [$startDate, $endDate])
            ->orderBy('expiration_date')
            ->pluck('expiration_date')
            ->unique()
            ->values()
            ->toArray();
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
        if (!$HVL && count($strikeData) > 0) {
            $HVL = $strikeData[0]['strike'];
        }
        return $HVL;
    }

    protected function getTop3(array $strikeData, string $type)
    {
        if ($type === 'call') {
            $filtered = array_filter($strikeData, fn($d) => $d['net_gex'] > 0);
            usort($filtered, fn($a, $b) => $b['net_gex'] <=> $a['net_gex']);
        } else {
            $filtered = array_filter($strikeData, fn($d) => $d['net_gex'] < 0);
            usort($filtered, fn($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']));
        }

        $level1 = $filtered[0]['strike'] ?? null;
        $level2 = $filtered[1]['strike'] ?? null;
        $level3 = $filtered[2]['strike'] ?? null;

        return [$level1, $level2, $level3];
    }

    /**
     * Queue symbol priming once per short window to avoid dispatch storms.
     */
    protected function kickoffSymbolPrime(string $symbol): void
    {
        $sym = strtoupper(trim($symbol));
        if ($sym === '') {
            return;
        }

        $lockKey = "prime:symbol:{$sym}";
        if (!Cache::add($lockKey, 1, now()->addSeconds(90))) {
            return;
        }

        dispatch(new \App\Jobs\PrimeSymbolJob($sym))->onQueue('default');
        dispatch(new \App\Jobs\FetchPolygonIntradayOptionsJob([$sym]))
            ->onQueue($this->intradayQueueForSymbol($sym));
    }

    protected function intradayQueueForSymbol(string $symbol): string
    {
        return in_array($symbol, ['SPY', 'QQQ'], true) ? 'intraday-heavy' : 'intraday';
    }
}
