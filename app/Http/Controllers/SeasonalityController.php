<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeasonalityController extends Controller
{
    public function fiveDay(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->query('symbol', 'SPY'));

        $latestDate = DB::table('seasonality_5d')
            ->where('symbol', $symbol)
            ->max('data_date');

        if (!$latestDate) {
            return response()->json([
                'symbol' => $symbol,
                'variant' => null,
                'note' => 'No seasonality available yet.',
            ], 200);
        }

        // Pick from latest date only so stale deep rows never override fresh rows.
        $rows = DB::table('seasonality_5d')
            ->where('symbol', $symbol)
            ->whereDate('data_date', $latestDate)
            ->get([
                'data_date as date', 'd1', 'd2', 'd3', 'd4', 'd5', 'cum5', 'z',
                'lookback_years', 'lookback_days', 'window_days', 'meta',
            ]);

        if ($rows->isEmpty()) {
            return response()->json([
                'symbol' => $symbol,
                'variant' => null,
                'note' => 'No seasonality available yet.',
            ], 200);
        }

        // Prefer deeper variant within the same date if multiple rows exist.
        $pick = $rows->sortByDesc(function ($r) {
            $meta = is_string($r->meta) ? (json_decode($r->meta, true) ?: []) : ($r->meta ?? []);
            $years = (int) ($r->lookback_years ?? ($meta['lookback_years'] ?? 0));
            $days = (int) ($r->lookback_days ?? ($meta['lookback_days'] ?? 0));
            return ($years * 100000) + $days;
        })->first();

        $meta = is_string($pick->meta) ? (json_decode($pick->meta, true) ?: []) : ($pick->meta ?? []);
        $samples = $meta['samples']['n_valid'] ?? null;
        $lookbackYears = $pick->lookback_years ?? ($meta['lookback_years'] ?? null);
        $lookbackDays = $pick->lookback_days ?? ($meta['lookback_days'] ?? null);
        $windowDays = $pick->window_days ?? ($meta['window_days'] ?? null);

        if (!is_null($lookbackYears)) {
            $note = sprintf(
                'Computed from +/- %sd calendar window across %sy (samples: %s).',
                $windowDays ?? 0,
                $lookbackYears,
                $samples ?? '-'
            );
        } else {
            $note = sprintf(
                'Computed from rolling last %sd (samples: %s).',
                $lookbackDays ?? 0,
                $samples ?? '-'
            );
        }

        return response()->json([
            'symbol' => $symbol,
            'variant' => [
                'date' => $pick->date,
                'd1' => $pick->d1,
                'd2' => $pick->d2,
                'd3' => $pick->d3,
                'd4' => $pick->d4,
                'd5' => $pick->d5,
                'cum5' => $pick->cum5,
                'z' => $pick->z,
            ],
            'note' => $note,
        ], 200);
    }
}
