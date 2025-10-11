<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PositioningController extends Controller
{

    public function dex(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol', 'SPY'));


        $date = DB::table('dex_by_expiry')
            ->where('symbol',$symbol)->max('data_date');

        if (!$date) {
            // do NOT cache empties
            return response()->json([
                'symbol'=>$symbol, 'data_date'=>null,
                'total'=>null, 'by_expiry'=>[]
            ], 200);
        }

        $cacheKey = "dex:{$symbol}:{$date}";
        return Cache::remember($cacheKey, 86400, function() use ($symbol, $date) {

            $by = DB::table('dex_by_expiry')
                ->where('symbol',$symbol)->where('data_date',$date)
                    ->orderBy('exp_date')
                    ->get(['exp_date','dex_total']);

                $total = (float) $by->sum('dex_total');

                return response()->json([
                    'symbol'    => $symbol,
                    'data_date' => $date,
                    'total'     => $total,
                    'by_expiry' => $by,
                ], 200);
        });
    }

}
