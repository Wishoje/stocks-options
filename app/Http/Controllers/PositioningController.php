<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PositioningController extends Controller
{
    public function dex(Request $req)
    {
        $symbol    = \App\Support\Symbols::canon($req->query('symbol', 'SPY'));
        $lookahead = (int) $req->query('days_ahead', 90);
        $lookback  = (int) $req->query('days_back', 30);

        $anchor = $this->completedSessionDate();
        $date  = DB::table('dex_by_expiry')
            ->where('symbol', $symbol)
            ->whereDate('data_date', '<=', $anchor)
            ->max('data_date');
        if (!$date) {
            $date = DB::table('dex_by_expiry')->where('symbol', $symbol)->max('data_date');
        }

        $today = now('America/New_York')->toDateString();
        $start = now('America/New_York')->copy()->subDays($lookback)->toDateString();
        $end   = now('America/New_York')->copy()->addDays($lookahead)->toDateString();

        // expiries that already have DEX for the latest compute date
        $exA = DB::table('dex_by_expiry')
            ->select('exp_date as expiration_date')
            ->where('symbol', $symbol)
            ->where('data_date', $date);

        // forward expiries that actually have contracts
        $exB = DB::table('option_expirations as e')
            ->join('option_chain_data as o', 'o.expiration_id', '=', 'e.id')
            ->where('e.symbol', $symbol)
            ->whereBetween('e.expiration_date', [$today, $end])
            ->distinct()
            ->select('e.expiration_date');

        // build the window we'll render (past/today + future-with-contracts)
        $exps = DB::query()
            ->fromSub($exA->union($exB), 'x')
            ->whereBetween('expiration_date', [$start, $end]);

        // left-join DEX values (zero if not computed yet)
        $by = DB::table('dex_by_expiry as d')
            ->rightJoinSub($exps, 'e', 'e.expiration_date', '=', 'd.exp_date')
            ->where(function ($q) use ($symbol, $date) {
                $q->where('d.symbol', $symbol)->where('d.data_date', $date)->orWhereNull('d.data_date');
            })
            ->orderBy('e.expiration_date')
            ->get([
                DB::raw('e.expiration_date as exp_date'),
                DB::raw('COALESCE(d.dex_total, 0.0) as dex_total'),
            ]);

        $total = DB::table('dex_by_expiry')
            ->where('symbol', $symbol)->where('data_date', $date)->sum('dex_total');

        return response()->json([
            'symbol'    => $symbol,
            'data_date' => $date,          // last compute date for DEX
            'today'     => $today,         // calendar today (for marker)
            'by_expiry' => $by,
            'total'     => (float) $total,
            'window'    => ['start' => $start, 'end' => $end],
        ]);
    }

    protected function completedSessionDate(): string
    {
        $ny = now('America/New_York');
        if ($ny->isWeekend()) {
            return $ny->previousWeekday()->toDateString();
        }

        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            return $ny->previousWeekday()->toDateString();
        }

        return $ny->toDateString();
    }
}
