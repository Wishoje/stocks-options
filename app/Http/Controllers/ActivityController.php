<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ActivityController extends Controller
{
    // GET /ua?symbol=SPY[&exp=YYYY-MM-DD]
    public function index(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol','SPY'));
        $exp    = $req->query('exp'); // optional

        // cache 15m intraday, else 24h if you only compute EOD
        $ttl = now()->addMinutes(15);

        $key = 'ua:'.md5($symbol.':'.($exp ?? 'ALL'));
        return Cache::remember($key, $ttl, function () use ($symbol, $exp) {
            $latest = \DB::table('unusual_activity')
                ->where('symbol', $symbol)
                ->max('data_date');

            if (!$latest) {
                return response()->json(['symbol'=>$symbol,'data_date'=>null,'items'=>[]], 200);
            }

            $q = DB::table('unusual_activity')
                ->where('symbol', $symbol)
                ->where('data_date', $latest);

            if ($exp) $q->where('exp_date', $exp);

            $rows = $q->orderByDesc('z_score')
                      ->orderByDesc('vol_oi')
                      ->get(['exp_date','strike','z_score','vol_oi','meta']);

            return response()->json([
                'symbol'=>$symbol,
                'data_date'=>$latest,
                'items'=>$rows->map(function($r){
                    return [
                        'exp_date' => $r->exp_date,
                        'strike'   => (float)$r->strike,
                        'z_score'  => (float)$r->z_score,
                        'vol_oi'   => (float)$r->vol_oi,
                        'meta'     => json_decode($r->meta, true) ?: null,
                    ];
                }),
            ], 200);
        });
    }
}
