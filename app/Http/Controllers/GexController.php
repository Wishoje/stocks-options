<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\OptionExpiration;
use App\Models\OptionChainData;
use Illuminate\Support\Facades\Cache;

class GexController extends Controller
{
    public function getGexLevels(Request $request)
    {
        $symbol    = strtoupper($request->query('symbol', 'SPY'));
        $timeframe = $request->query('timeframe', '90d');

        // ← now resolves dates + IDs for you
        $dates = $this->resolveExpirationDates($symbol, $timeframe);

        if (empty($dates)) {
            return response()->json([
                'error' => "No expirations found for {$symbol}/{$timeframe}"
            ], 404);
        }

        $expirationIds = OptionExpiration::where('symbol', $symbol)
            ->whereIn('expiration_date', $dates)
            ->pluck('id')
            ->toArray();

        $latestDates = OptionChainData::select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        $todayData = OptionChainData::joinSub($latestDates, 'ld', function($join) {
                $join->on('option_chain_data.expiration_id', '=', 'ld.expiration_id')
                     ->on('option_chain_data.data_date',      '=', 'ld.max_date');
            })
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->get();

        if ($todayData->isEmpty()) {
            return response()->json(['error'=>"No data for {$symbol}/{$timeframe}"], 404);
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
                + ($opt->option_type==='call' ? $opt->gamma * $opt->open_interest * 100 : 0);
            $strikesRaw[$s]['put_gamma']  = ($strikesRaw[$s]['put_gamma']  ?? 0)
                + ($opt->option_type==='put'  ? $opt->gamma * $opt->open_interest * 100 : 0);
        }

        $strikeList = [];
        foreach ($strikesRaw as $strike => $g) {
            $strikeList[] = [
                'strike'  => $strike,
                'net_gex' => $g['call_gamma'] - $g['put_gamma'],
            ];
        }
        usort($strikeList, fn($a,$b)=> $a['strike'] <=> $b['strike']);

        // 4) HVL & walls
        $HVL          = $this->findHVL($strikeList);
        [$c1,$c2,$c3] = $this->getTop3($strikeList, 'call');
        [$p1,$p2,$p3] = $this->getTop3($strikeList, 'put');

        // 5) Prepare prior snapshots
        $latestDate = $todayData->first()->data_date;
        $yesterday  = Carbon::parse($latestDate)->subDay()->toDateString();
        $lastWeek   = Carbon::parse($latestDate)->subWeek()->toDateString();

        $fetchPrior = fn($date) => OptionChainData::whereIn('expiration_id', $expirationIds)
            ->where('data_date', $date)
            ->select('strike', 'option_type',
                     DB::raw('SUM(open_interest) as oi'),
                     DB::raw('SUM(volume)       as vol'))
            ->groupBy('strike','option_type')
            ->get()
            ->groupBy('strike');

        $dayAgo  = $fetchPrior($yesterday);
        $weekAgo = $fetchPrior($lastWeek);

        // 6) Assemble full strike data with call/put deltas
        $fullStrike = [];
        foreach ($strikeList as $row) {
            $s = $row['strike'];

            // current totals
            $curCallOi  = $todayData->where('strike',$s)->where('option_type','call')->sum('open_interest');
            $curPutOi   = $todayData->where('strike',$s)->where('option_type','put' )->sum('open_interest');
            $curCallVol = $todayData->where('strike',$s)->where('option_type','call')->sum('volume');
            $curPutVol  = $todayData->where('strike',$s)->where('option_type','put' )->sum('volume');

            // prior day
            $pd = $dayAgo->get($s, collect());
            $pCallOi  = $pd->first(fn($r)=>$r->option_type==='call')->oi ?? 0;
            $pPutOi   = $pd->first(fn($r)=>$r->option_type==='put')->oi  ?? 0;
            $pCallVol = $pd->first(fn($r)=>$r->option_type==='call')->vol?? 0;
            $pPutVol  = $pd->first(fn($r)=>$r->option_type==='put')->vol ?? 0;

            // prior week
            $pw = $weekAgo->get($s, collect());
            $wCallOi  = $pw->first(fn($r)=>$r->option_type==='call')->oi ?? 0;
            $wPutOi   = $pw->first(fn($r)=>$r->option_type==='put')->oi  ?? 0;
            $wCallVol = $pw->first(fn($r)=>$r->option_type==='call')->vol?? 0;
            $wPutVol  = $pw->first(fn($r)=>$r->option_type==='put')->vol ?? 0;

            // deltas
            $dCallOi  = $curCallOi  - $pCallOi;
            $dPutOi   = $curPutOi   - $pPutOi;
            $dCallVol = $curCallVol - $pCallVol;
            $dPutVol  = $curPutVol  - $pPutVol;

            $pctOr0 = fn($n,$d) => $d>0 ? round($n/$d*100,2) : 0;

            $fullStrike[] = [
                'strike'              => $s,
                'net_gex'             => $row['net_gex'],
                'call_oi_delta'       => $dCallOi,
                'put_oi_delta'        => $dPutOi,
                'call_oi_delta_pct'   => $pctOr0($dCallOi, $pCallOi),
                'put_oi_delta_pct'    => $pctOr0($dPutOi,  $pPutOi),
                'call_vol_delta'      => $dCallVol,
                'put_vol_delta'       => $dPutVol,
                'call_vol_delta_pct'  => $pctOr0($dCallVol,$pCallVol),
                'put_vol_delta_pct'   => $pctOr0($dPutVol, $pPutVol),
                'call_oi_wow'         => $curCallOi  - $wCallOi,
                'put_oi_wow'          => $curPutOi   - $wPutOi,
                'call_vol_wow'        => $curCallVol - $wCallVol,
                'put_vol_wow'         => $curPutVol  - $wPutVol,
            ];
        }

        $date = Carbon::now('America/New_York')->isWeekend()
            ? Carbon::now('America/New_York')->previousWeekday()->toDateString()
            : Carbon::now('America/New_York')->toDateString();

        $gs = Cache::get("gamma_strength:{$symbol}:{$date}");
        $payload['regime_strength'] = $gs['strength'] ?? null;
        $payload['gamma_sign']      = $gs['sign']     ?? null; // +1 pos gamma, -1 neg gamma

        // 7) Send it all back
        return response()->json([
            'symbol'                   => $symbol,
            'timeframe'                => $timeframe,
            'expiration_dates'         => $dates,
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
            'pcr_volume'               => $callVol>0 ? round($putVol/$callVol,2) : null,
            'date_prev'                => $yesterday,
            'date_prev_week'           => $lastWeek,
            'strike_data'              => $fullStrike,
        ], 200);
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
            // if the third Friday is in the past, take next month’s third Friday
            if ($d->lt(\Carbon\Carbon::now()->startOfDay())) {
                $d = $this->thirdFriday(\Carbon\Carbon::now()->addMonth());
            }
            return \App\Models\OptionExpiration::where('symbol',$symbol)
                ->whereDate('expiration_date', $d->toDateString())
                ->orderBy('expiration_date')
                ->pluck('expiration_date')
                ->unique()->values()->toArray();
        }
        // default
        return $this->getExpirationsWithinDays($symbol, 90);
    }

    
    protected function thirdFriday(Carbon $dt): Carbon
    {
        // third Friday of the month of $dt
        $first = $dt->copy()->startOfMonth();
        // weekday() 0=Sun..6=Sat, we want Friday (5)
        $firstFriday = $first->copy()->next(Carbon::FRIDAY);
        if ($first->isFriday()) $firstFriday = $first; // if the 1st IS Friday
        // third Friday = first Friday + 2 weeks
        return $firstFriday->copy()->addWeeks(2);
    }

    // Helper: find expirations within X days
    protected function getExpirationsWithinDays(string $symbol, int $days): array
    {
        $now       = now();
        $startDate = $now->toDateString();
        $endDate   = $now->copy()->addDays($days)->toDateString();  // ← clone before mutating!

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
            if ($strikeData[$i]['net_gex'] < 0 && $strikeData[$i+1]['net_gex'] >= 0) {
                $HVL = $strikeData[$i+1]['strike'];
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
}
