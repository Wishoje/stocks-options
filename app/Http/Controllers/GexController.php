<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\OptionExpiration;
use App\Models\OptionChainData;

class GexController extends Controller
{
    public function getGexLevels(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));
        $timeframeParam = $request->query('timeframe', '14d');

        // 1) Determine relevant expiration dates based on timeframe
        $expirationDates = []; 
        switch ($timeframeParam) {
            case '0d': // Today only
                $expirationDates = $this->getExpirationsWithinDays($symbol, 0); 
                break;
            case '1d': // Next day
                $expirationDates = $this->getExpirationsWithinDays($symbol, 1);
                break;
            case '7d':
                $expirationDates = $this->getExpirationsWithinDays($symbol, 7);
                break;
            case '14d':
                $expirationDates = $this->getExpirationsWithinDays($symbol, 14);
                break;
            case 'monthly':
                $expirationDates = $this->getNextMonthlyExpiration($symbol);
                break;
            default:
                $expirationDates = $this->getExpirationsWithinDays($symbol, 14);
                break;
        }

        if (empty($expirationDates)) {
            return response()->json([
                'error' => "No data found for $symbol in timeframe: $timeframeParam"
            ], 404);
        }

        // 2) Fetch the OptionExpiration rows that match these dates
        $expirations = OptionExpiration::where('symbol', $symbol)
            ->whereIn('expiration_date', $expirationDates)
            ->get();

        if ($expirations->isEmpty()) {
            return response()->json([
                'error' => "No data found for $symbol in timeframe: $timeframeParam"
            ], 404);
        }

        // 3) Get the IDs of these expiration rows
        $expirationIds = $expirations->pluck('id');

        /*
         * 4) Subquery: find the latest (MAX) data_date for each expiration_id
         *    We’ll call it "ld" (latest dates).
         */
        $latestDatesQuery = OptionChainData::select(
                'expiration_id',
                DB::raw('MAX(data_date) as max_date')
            )
            ->whereIn('expiration_id', $expirationIds)
            ->groupBy('expiration_id');

        /*
         * 5) Join that subquery to get only rows
         *    where option_chain_data.data_date = subquery.max_date.
         *    This fetches the MOST RECENT data for each expiration.
         */
        $chainData = OptionChainData::joinSub($latestDatesQuery, 'ld', function ($join) {
                $join->on('option_chain_data.expiration_id', '=', 'ld.expiration_id')
                     ->on('option_chain_data.data_date', '=', 'ld.max_date');
            })
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->get();

        if ($chainData->isEmpty()) {
            return response()->json([
                'error' => "No option data found for $symbol in timeframe: $timeframeParam"
            ], 404);
        }

        // ---- Now compute your GEX logic as before ----

        // 6) Basic sums
        $callOpenInterestTotal = $chainData
            ->where('option_type', 'call')
            ->sum('open_interest');

        $putOpenInterestTotal  = $chainData
            ->where('option_type', 'put')
            ->sum('open_interest');

        $totalOpenInterest = $callOpenInterestTotal + $putOpenInterestTotal;

        $callInterestPercentage = $totalOpenInterest > 0
            ? round(($callOpenInterestTotal / $totalOpenInterest) * 100, 2)
            : 0;

        $putInterestPercentage = $totalOpenInterest > 0
            ? round(($putOpenInterestTotal / $totalOpenInterest) * 100, 2)
            : 0;

        // 7) Volume-based metrics
        $callVolumeTotal = $chainData->where('option_type', 'call')->sum('volume');
        $putVolumeTotal  = $chainData->where('option_type', 'put')->sum('volume');
        $pcrVolume = ($callVolumeTotal > 0)
            ? round($putVolumeTotal / $callVolumeTotal, 2)
            : null;

        // 8) Aggregate GEX at each strike
        $strikes = [];
        foreach ($chainData as $opt) {
            $strike   = $opt->strike;
            $type     = $opt->option_type;
            $oi       = $opt->open_interest ?? 0;
            $gammaVal = $opt->gamma ?? 0;

            if (!isset($strikes[$strike])) {
                $strikes[$strike] = [
                    'call_gamma_sum' => 0,
                    'put_gamma_sum'  => 0,
                ];
            }

            $contribution = $gammaVal * $oi * 100; 
            if ($type === 'call') {
                $strikes[$strike]['call_gamma_sum'] += $contribution;
            } else {
                $strikes[$strike]['put_gamma_sum']  += $contribution;
            }
        }

        // 9) Build strikeData array to find net_gex
        $strikeData = [];
        foreach ($strikes as $strikeVal => $vals) {
            $netGEX = $vals['call_gamma_sum'] - $vals['put_gamma_sum'];
            $strikeData[] = [
                'strike'  => $strikeVal,
                'net_gex' => $netGEX
            ];
        }

        // Sort by strike ascending
        usort($strikeData, fn($a, $b) => $a['strike'] <=> $b['strike']);

        // 10) Find HVL
        $HVL = $this->findHVL($strikeData);

        // 11) Call/Put walls
        [$callResistance, $callWall2, $callWall3] = $this->getTop3($strikeData, 'call');
        [$putSupport, $putWall2, $putWall3]       = $this->getTop3($strikeData, 'put');

        // Return JSON
        return response()->json([
            'symbol'                  => $symbol,
            'timeframe'               => $timeframeParam,
            'expiration_dates'        => $expirationDates,
            'HVL'                     => $HVL,
            'call_resistance'         => $callResistance,
            'call_wall_2'            => $callWall2,
            'call_wall_3'            => $callWall3,
            'put_support'             => $putSupport,
            'put_wall_2'              => $putWall2,
            'put_wall_3'              => $putWall3,
            'call_open_interest_total'=> $callOpenInterestTotal,
            'put_open_interest_total' => $putOpenInterestTotal,
            'call_interest_percentage'=> $callInterestPercentage,
            'put_interest_percentage' => $putInterestPercentage,
            'call_volume_total'       => $callVolumeTotal,
            'put_volume_total'        => $putVolumeTotal,
            'pcr_volume'              => $pcrVolume
        ]);
    }

    // Helper: find expirations within X days
    protected function getExpirationsWithinDays($symbol, $days)
    {
        $now = now();
        $endDate = now()->addDays($days);

        return OptionExpiration::where('symbol', $symbol)
            ->whereBetween('expiration_date', [$now->toDateString(), $endDate->toDateString()])
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
