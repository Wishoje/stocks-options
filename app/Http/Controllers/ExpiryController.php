<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ExpiryController extends Controller
{
    // GET /expiry-pressure?symbol=SPY&days=3
    public function pressure(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol','SPY'));
        $days   = (int) $req->query('days', 3);

        $cacheKey = "expiry_pressure:{$symbol}:{$days}";
        return Cache::remember($cacheKey, now()->addHours(1), function() use ($symbol,$days) {

            $latestDate = DB::table('expiry_pressure')
                ->where('symbol',$symbol)
                ->max('data_date');

            if (!$latestDate) {
                return response()->json([
                    'symbol'=>$symbol, 'data_date'=>null, 'headline_pin'=>null, 'entries'=>[]
                ], 200);
            }

            // limit to next N trading days
            $endDate = (new \Carbon\Carbon($latestDate))->toDateString();
            $endFn   = function(string $start, int $n) {
                $d = \Carbon\Carbon::parse($start, 'America/New_York');
                $left = $n;
                while ($left > 0) { $d->addDay(); if (!$d->isWeekend()) $left--; }
                return $d->toDateString();
            };

            $limit = $endFn($latestDate, $days);

            $rows = DB::table('expiry_pressure')
                ->where('symbol',$symbol)
                ->where('data_date',$latestDate)
                ->whereBetween('exp_date', [$latestDate, $limit])
                ->orderBy('exp_date')
                ->get(['exp_date','pin_score','clusters_json','max_pain']);

            $entries = [];
            $headline = null;
            foreach ($rows as $r) {
                $clusters = json_decode($r->clusters_json, true) ?: [];
                $entries[] = [
                    'exp_date'  => $r->exp_date,
                    'pin_score' => (int)$r->pin_score,
                    'clusters'  => array_map(function($c){
                        return [
                            'strike'   => (float)$c['strike'],
                            'density'  => (int)$c['density'],
                            'distance' => (float)$c['distance'],
                            'score'    => round((float)$c['score']*100, 1), // 0..100 for UI
                        ];
                    }, $clusters),
                    'max_pain'  => $r->max_pain !== null ? (float)$r->max_pain : null,
                ];
                $headline = max($headline ?? 0, (int)$r->pin_score);
            }

            return response()->json([
                'symbol'       => $symbol,
                'data_date'    => $latestDate,
                'headline_pin' => $headline,
                'entries'      => $entries,
            ], 200);
        });
    }

    public function pressureBatch(Request $req)
    {
        $symbols = collect($req->query('symbols', []))
            ->map(fn($s)=>\App\Support\Symbols::canon($s))
            ->unique()->values();
        $days = (int) $req->query('days', 3);

        if ($symbols->isEmpty()) {
            return response()->json(['items'=>[]], 200);
        }

        $cacheKey = 'expiry_pressure_batch:'.md5($symbols->join(',').":{$days}");
        return \Cache::remember($cacheKey, now()->addHour(), function() use ($symbols,$days) {

            // latest data_date per symbol
            $latest = \DB::table('expiry_pressure')
                ->select('symbol', \DB::raw('MAX(data_date) as d'))
                ->whereIn('symbol', $symbols)
                ->groupBy('symbol')
                ->pluck('d','symbol'); // ['SPY'=>'2025-10-10', ...]

            $items = [];
            foreach ($symbols as $sym) {
                $d = $latest[$sym] ?? null;
                if (!$d) { $items[$sym] = ['data_date'=>null,'headline_pin'=>null]; continue; }

                // compute end date (next N trading days)
                $end = (function(string $start, int $n){
                    $dt = \Carbon\Carbon::parse($start, 'America/New_York');
                    $left=$n;
                    while($left>0){ $dt->addDay(); if(!$dt->isWeekend()) $left--; }
                    return $dt->toDateString();
                })($d, $days);

                $rows = \DB::table('expiry_pressure')
                    ->where('symbol',$sym)
                    ->where('data_date',$d)
                    ->whereBetween('exp_date', [$d,$end])
                    ->pluck('pin_score');

                $items[$sym] = [
                    'data_date'    => $d,
                    'headline_pin' => $rows->count() ? (int)$rows->max() : null,
                ];
            }

            return response()->json(['items'=>$items], 200);
        });
    }

}
