<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeasonalityController extends Controller
{
    public function fiveDay(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol','SPY'));

        // Only select columns that exist
        $rows = DB::table('seasonality_5d')
            ->where('symbol', $symbol)
            ->orderByDesc('data_date')
            ->get([
                'data_date as date','d1','d2','d3','d4','d5','cum5','z','meta'
            ]);

        if ($rows->isEmpty()) {
            return response()->json([
                'symbol'=>$symbol,
                'variant'=>null,
                'note'=>'No seasonality available yet.',
            ], 200);
        }

        // Score rows by “depth” using values inside meta
        $pick = $rows->sortByDesc(function($r){
            $meta = is_string($r->meta) ? json_decode($r->meta, true) : ($r->meta ?? []);
            $years = (int)($meta['lookback_years'] ?? 0);
            $days  = (int)($meta['lookback_days']  ?? 0);
            return ($years * 100000) + $days; // prefer years over days
        })->first();

        // Build human note from meta
        $meta = is_string($pick->meta) ? json_decode($pick->meta, true) : ($pick->meta ?? []);
        $samples = $meta['samples']['n_valid'] ?? null;

        if (isset($meta['lookback_years'])) {
            $note = sprintf(
                'Computed from ±%sd calendar window across %sy (samples: %s).',
                $meta['window_days'] ?? 0,
                $meta['lookback_years'],
                $samples ?? '—'
            );
        } else {
            $note = sprintf(
                'Computed from rolling last %sd (samples: %s).',
                $meta['lookback_days'] ?? 0,
                $samples ?? '—'
            );
        }

        // Return the picked record as the “variant”
        return response()->json([
            'symbol'  => $symbol,
            'variant' => [
                'date' => $pick->date,
                'd1'   => $pick->d1, 'd2'=>$pick->d2, 'd3'=>$pick->d3, 'd4'=>$pick->d4, 'd5'=>$pick->d5,
                'cum5' => $pick->cum5,
                'z'    => $pick->z,
            ],
            'note'    => $note,
        ], 200);
    }
}
