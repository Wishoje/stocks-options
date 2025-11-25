<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class HotOptionsController extends Controller
{
    public function index()
    {
        $limit = (int) request('limit', 200);
        $limit = max(1, min($limit, 500)); // sanity

        $requestedDate = request('date'); // optional ?date=YYYY-MM-DD

        $query = DB::table('hot_option_symbols');

        if ($requestedDate) {
            $tradeDate = $requestedDate;
            $query->whereDate('trade_date', $tradeDate);
        } else {
            $tradeDate = $query->max('trade_date');
            if ($tradeDate) {
                $query->whereDate('trade_date', $tradeDate);
            }
        }

        $rows = $tradeDate
            ? $query->orderBy('rank')->limit($limit)->get()
            : collect();

        $totalVol = (int) $rows->sum('total_volume');
        $avgPcr   = $rows->whereNotNull('put_call_ratio')->avg('put_call_ratio');

        // Fallback: if table is empty, optionally fall back to your old DB-based ranking:
        if ($rows->isEmpty()) {
            // === old behavior (optional) ===
            $cutoff = now('America/New_York')->subDays(10)->toDateString();

            $fallbackSymbols = DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->whereDate('o.data_date', '>=', $cutoff)
                ->selectRaw('e.symbol, SUM(o.open_interest) as oi_sum, SUM(o.volume) as vol_sum')
                ->groupBy('e.symbol')
                ->orderByRaw('oi_sum DESC, vol_sum DESC')
                ->limit($limit)
                ->pluck('symbol')
                ->map(fn ($s) => \App\Support\Symbols::canon($s))
                ->unique()
                ->values()
                ->all();

            return response()->json([
                'trade_date' => null,
                'limit'      => $limit,
                'source'     => 'fallback_db',
                'symbols'    => $fallbackSymbols,
            ]);
        }

        return response()->json([
            'trade_date' => $tradeDate,
            'limit'      => $limit,
            'source'     => 'hot_option_symbols',
            'symbols'    => $rows->pluck('symbol')->values()->all(),
            'items'      => $rows->map(fn($r) => [
                'symbol'       => $r->symbol,
                'rank'         => $r->rank,
                'total_volume' => $r->total_volume,
                'put_call'     => $r->put_call_ratio,
                'last_price'   => $r->last_price,
            ])->values()->all(),
            'meta'       => [
                'count'     => $rows->count(),
                'source'    => $rows->first()->source ?? null,
                'total_vol' => $totalVol,
                'avg_pcr'   => $avgPcr,
            ],
        ]);

    }
}
