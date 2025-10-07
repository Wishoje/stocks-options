<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class SeasonalityController extends Controller
{
    public function fiveDay(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol','SPY'));
        $key = "seasonality5d:{$symbol}";
        return Cache::remember($key, 3600, function() use ($symbol) {
            $row = DB::table('seasonality_5d')
                ->where('symbol',$symbol)
                ->orderByDesc('data_date')
                ->first(['data_date as date','d1','d2','d3','d4','d5','cum5','z','meta']);

            return response()->json($row ?? (object)[
                'date'=>null,'d1'=>null,'d2'=>null,'d3'=>null,'d4'=>null,'d5'=>null,'cum5'=>null,'z'=>null
            ], 200);
        });
    }
}
