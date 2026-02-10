<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SymbolWallSnapshot;
use App\Support\Symbols;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class WallScannerController extends Controller
{
    public function scan(Request $request)
    {
        try {
            $validated = $request->validate([
                'symbols'      => ['required', 'array', 'min:1', 'max:500'],
                'symbols.*'    => ['required', 'string', 'max:32'],
                'timeframe'    => ['nullable', 'string', 'max:16'],
                'timeframes'   => ['nullable', 'array', 'max:12'],
                'timeframes.*' => ['nullable', 'string', 'max:16'],
                'near_pct'     => ['nullable', 'numeric', 'min:0', 'max:100'],
                'near_pts'     => ['nullable', 'numeric', 'min:0'],
            ]);

            $symbols = collect((array) ($validated['symbols'] ?? []))
                ->map(fn ($s) => Symbols::canon((string) $s))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $defaultTf = (string) ($validated['timeframe'] ?? '30d');
            $requested = collect((array) ($validated['timeframes'] ?? [$defaultTf]))
                ->map(fn ($tf) => strtolower(trim((string) $tf)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            $allowedTimeframes = ['0d','1d','7d','14d','21d','30d','45d','60d','90d','monthly'];
            $timeframes = array_values(array_intersect($requested, $allowedTimeframes));

            $maxPct = array_key_exists('near_pct', $validated) ? (float) $validated['near_pct'] : 1.0;
            $maxPts = array_key_exists('near_pts', $validated) ? (float) $validated['near_pts'] : null;

            if (empty($symbols) || empty($timeframes)) {
                return response()->json(['items' => []]);
            }

            $now = now('America/New_York');

            // Pull only the most recent trade_date per symbol/timeframe to avoid loading full history.
            $latest = SymbolWallSnapshot::query()
                ->select('symbol', 'timeframe', DB::raw('MAX(trade_date) as max_trade_date'))
                ->whereIn('symbol', $symbols)
                ->whereIn('timeframe', $timeframes)
                ->groupBy('symbol', 'timeframe');

            $rows = SymbolWallSnapshot::query()
                ->joinSub($latest, 'latest', function ($join) {
                    $join->on('symbol_wall_snapshots.symbol', '=', 'latest.symbol')
                        ->on('symbol_wall_snapshots.timeframe', '=', 'latest.timeframe')
                        ->on('symbol_wall_snapshots.trade_date', '=', 'latest.max_trade_date');
                })
                ->select('symbol_wall_snapshots.*')
                ->orderBy('symbol_wall_snapshots.symbol')
                ->orderBy('symbol_wall_snapshots.timeframe')
                ->get();

            if ($rows->isEmpty()) {
                return response()->json(['items' => []]);
            }

            $items = [];

            foreach ($rows as $row) {
                // Freshness guard:
                // - weekdays: 24h
                // - Monday/weekends: allow weekend bridge from Friday close
                $maxFreshHours = ($now->isWeekend() || $now->isMonday()) ? 72 : 24;
                if ($row->trade_date) {
                    try {
                        $tradeAt = Carbon::parse($row->trade_date . ' 16:00:00', 'America/New_York');
                    } catch (\Throwable) {
                        continue;
                    }
                    $ageHours = $tradeAt->diffInHours($now);

                    if ($ageHours > $maxFreshHours) {
                        continue;
                    }
                }

                $spot = (float) $row->spot;
                if ($spot <= 0) {
                    continue;
                }

                $hitTypes = [];
                $walls    = [];

                // Keep scanner focused on one primary put wall + one primary call wall
                // (EOD first; intraday as fallback) to avoid weaker multi-wall noise.
                $putWall = $this->pickSingleWall([
                    $this->wallInfo('eod_put', $row->eod_put_wall, $row->eod_put_dist_pct, $spot),
                    $this->wallInfo('intraday_put', $row->intraday_put_wall, $row->intraday_put_dist_pct, $spot),
                ]);

                if ($putWall) {
                    $walls[$putWall['key']] = [
                        'strike'      => $putWall['strike'],
                        'distance_pt' => $putWall['distance_pt'],
                        'distance_pc' => $putWall['distance_pc'],
                    ];

                    if ($this->isHit($putWall['distance_pc'], $putWall['distance_pt'], $maxPct, $maxPts)) {
                        $hitTypes[] = $putWall['key'];
                    }
                }

                $callWall = $this->pickSingleWall([
                    $this->wallInfo('eod_call', $row->eod_call_wall, $row->eod_call_dist_pct, $spot),
                    $this->wallInfo('intraday_call', $row->intraday_call_wall, $row->intraday_call_dist_pct, $spot),
                ]);

                if ($callWall) {
                    $walls[$callWall['key']] = [
                        'strike'      => $callWall['strike'],
                        'distance_pt' => $callWall['distance_pt'],
                        'distance_pc' => $callWall['distance_pc'],
                    ];

                    if ($this->isHit($callWall['distance_pc'], $callWall['distance_pt'], $maxPct, $maxPts)) {
                        $hitTypes[] = $callWall['key'];
                    }
                }

                if (!$hitTypes) {
                    continue;
                }

                $items[] = [
                    'symbol'      => $row->symbol,
                    'spot'        => $spot,
                    'timeframe'   => $row->timeframe,
                    'trade_date'  => $row->trade_date,
                    'hits'        => $hitTypes,
                    'walls'       => $walls,
                ];
            }

            usort($items, function ($a, $b) {
                $aMin = $this->minDistance($a);
                $bMin = $this->minDistance($b);
                return $aMin <=> $bMin;
            });

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
        } catch (\Throwable $e) {
            // Keep Laravel's normal 422 response shape for bad payloads.
            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::error('WallScannerController.scan.failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Scanner temporarily unavailable. Please try again.',
            ], 500);
        }
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

    private function pickSingleWall(array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function wallInfo(string $key, mixed $strikeRaw, mixed $distPctRaw, float $spot): ?array
    {
        if ($strikeRaw === null || !is_numeric($strikeRaw)) {
            return null;
        }

        $strike = (float) $strikeRaw;
        $distPts = abs($spot - $strike);
        $distPct = is_numeric($distPctRaw)
            ? abs((float) $distPctRaw)
            : ($spot > 0 ? ($distPts / $spot) * 100.0 : INF);

        return [
            'key'         => $key,
            'strike'      => $strike,
            'distance_pt' => $distPts,
            'distance_pc' => $distPct,
        ];
    }
}
