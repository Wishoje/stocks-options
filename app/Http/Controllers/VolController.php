<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VolController extends Controller
{
    public function term(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $cacheKey = "iv_term:{$symbol}";
        return Cache::remember($cacheKey, 86400, function() use ($symbol){
            $date = DB::table('iv_term')
                ->where('symbol',$symbol)
                ->max('data_date');

            if (!$date) return response()->json(['items'=>[],'date'=>null], 200);

            $items = DB::table('iv_term')
                ->where('symbol',$symbol)->where('data_date',$date)
                ->orderBy('exp_date')
                ->get(['exp_date as exp','iv']);

            return response()->json(['symbol'=>$symbol,'date'=>$date,'items'=>$items], 200);
        });
    }

    public function vrp(Request $req)
    {
        $symbol = strtoupper($req->query('symbol','SPY'));
        $cacheKey = "vrp:{$symbol}";
        return Cache::remember($cacheKey, 86400, function() use ($symbol){
            $row = DB::table('vrp_daily')
                ->where('symbol',$symbol)
                ->orderByDesc('data_date')
                ->first(['data_date as date','iv1m','rv20','vrp','z']);

            return response()->json($row ?? (object)[
                'date'=>null,'iv1m'=>null,'rv20'=>null,'vrp'=>null,'z'=>null
            ], 200);
        });
    }
}
