<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WallScannerController extends Controller
{
    public function scan(Request $request)
    {
        $symbols = (array) $request->input('symbols', []);
        $symbols = array_values(array_unique(array_filter(array_map('strtoupper', $symbols))));

        // single timeframe param kept for backwards compatibility
        $defaultTf = $request->input('timeframe', '30d');

        $timeframes = $request->input('timeframes');
        if ($timeframes === null) {
            $timeframes = [$defaultTf]; // old behavior
        } else {
            $timeframes = (array) $timeframes;
        }

        $timeframes = array_values(array_unique(array_map('strval', $timeframes)));

        $maxPct = $request->has('near_pct') ? $request->float('near_pct') : 1.0;
        $maxPts = $request->has('near_pts') ? $request->float('near_pts') : null;

        if (empty($symbols) || empty($timeframes)) {
            return response()->json(['items' => []]);
        }

        $tradeDate = now('America/New_York')->toDateString();

        $rows = \App\Models\SymbolWallSnapshot::query()
            ->whereIn('symbol', $symbols)
            ->whereIn('timeframe', $timeframes)
            ->where('trade_date', $tradeDate)
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $spot = (float) $row->spot;
            if ($spot <= 0) {
                continue;
            }

            $hitTypes = [];
            $walls    = [];

            if ($row->eod_put_wall) {
                $distPct = (float) $row->eod_put_dist_pct;
                $distPts = abs($spot - (float) $row->eod_put_wall);

                $isNear = $this->isHit($distPct, $distPts, $maxPct, $maxPts);

                if ($isNear) {
                    $hitTypes[] = 'eod_put';
                }

                $walls['eod_put'] = [
                    'strike'      => (float) $row->eod_put_wall,
                    'distance_pt' => $distPts,
                    'distance_pc' => $distPct,
                ];
            }

            if ($row->intraday_call_wall) {
                $distPct = (float) $row->intraday_call_dist_pct;
                $distPts = abs($spot - (float) $row->intraday_call_wall);

                $isNear = $this->isHit($distPct, $distPts, $maxPct, $maxPts);

                if ($isNear) {
                    $hitTypes[] = 'intraday_call';
                }

                $walls['intraday_call'] = [
                    'strike'      => (float) $row->intraday_call_wall,
                    'distance_pt' => $distPts,
                    'distance_pc' => $distPct,
                ];
            }

            if (!$hitTypes) {
                continue;
            }

            $items[] = [
                'symbol'    => $row->symbol,
                'spot'      => $spot,
                'timeframe' => $row->timeframe,
                'hits'      => $hitTypes,
                'walls'     => $walls,
            ];
        }

        usort($items, function ($a, $b) {
            $aMin = $this->minDistance($a);
            $bMin = $this->minDistance($b);
            return $aMin <=> $bMin;
        });

        // Also provide a grouped-by-timeframe view if you want it
        $byTimeframe = [];
        foreach ($items as $hit) {
            $tf = $hit['timeframe'];
            $byTimeframe[$tf][] = $hit;
        }

        return response()->json([
            'near_pct'     => $maxPct,
            'near_pts'     => $maxPts,
            'items'        => $items,
            'by_timeframe' => $byTimeframe,
        ]);
    }

    private function isHit(float $distPct, float $distPts, ?float $maxPct, ?float $maxPts): bool
    {
        if ($maxPts !== null && $distPts <= $maxPts) return true;
        if ($maxPct !== null && $distPct <= $maxPct) return true;
        return false;
    }

    private function minDistance(array $hit): float
    {
        $min = INF;
        foreach ($hit['walls'] as $info) {
            if (!$info || !isset($info['distance_pt'])) continue;
            $min = min($min, (float) $info['distance_pt']);
        }
        return $min === INF ? PHP_FLOAT_MAX : $min;
    }
}
