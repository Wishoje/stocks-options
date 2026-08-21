<?php

namespace App\Http\Controllers;

use App\Support\EodSnapshotSelector;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SymbolStatusController extends Controller
{
    public function show(
        Request $req,
        WorkRunCoordinator $runs,
        SymbolBootstrapPolicy $bootstrapPolicy,
        SymbolBootstrapCoordinator $bootstrap
    )
    {
        $symbol = Symbols::canon((string) $req->query('symbol', 'SPY'));
        if (! Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }
        $timeframe = $req->query('timeframe', '14d'); // keep in sync with UI default
        $parameters = $bootstrapPolicy->claimParameters();
        $activeRun = $runs->active(
            'symbol_bootstrap',
            $symbol,
            $parameters
        );
        $statusRun = $activeRun;
        $bootstrapPayload = null;
        if ($bootstrapPolicy->enabled()) {
            $statusRun ??= $bootstrap->authoritativeWorkRun(
                $symbol,
                (string) $parameters['session_date']
            );
            if (! $statusRun) {
                $latest = $bootstrap->latestForSymbol(
                    $symbol,
                    (string) $parameters['session_date']
                );
                $statusRun = $latest?->workRun;
            }
            $bootstrapPayload = $statusRun ? $bootstrap->payload($statusRun) : null;
        }
        $runPayload = $statusRun ? $runs->payload($statusRun) : null;

        if ($bootstrapPayload && ! $bootstrapPayload['fast_ready']) {
            $terminal = (bool) $bootstrapPayload['terminal'];

            return response()->json([
                'status' => $terminal ? 'failed' : ($activeRun ? 'fetching' : 'incomplete'),
                'symbol' => $symbol,
                'run' => $runPayload,
                'bootstrap' => $bootstrapPayload,
            ], $activeRun ? 202 : 200);
        }
        if ($bootstrapPayload && $bootstrapPayload['no_options']) {
            return response()->json([
                'status' => 'no_options',
                'symbol' => $symbol,
                'run' => $runPayload,
                'bootstrap' => $bootstrapPayload,
            ]);
        }

        // --- trading date (NY, roll back on weekends) ---
        $ny = Carbon::now('America/New_York');
        if ($ny->isWeekend()) {
            $ny = $ny->previousWeekday();
        }
        $tradeDate = app(EodSnapshotSelector::class)->completedSessionDate($ny->copy());

        // --- resolve expiration dates for the timeframe ---
        $daysMap = ['0d' => 0, '1d' => 1, '7d' => 7, '14d' => 14, '21d' => 21, '30d' => 30, '45d' => 45, '60d' => 60, '90d' => 90];
        if (isset($daysMap[$timeframe])) {
            $start = $ny->copy()->startOfDay()->toDateString();
            $end = $ny->copy()->addDays($daysMap[$timeframe])->toDateString();
            $expDates = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereBetween('expiration_date', [$start, $end])
                ->orderBy('expiration_date')
                ->pluck('expiration_date')
                ->unique()
                ->values()
                ->all();
        } elseif ($timeframe === 'monthly') {
            // third Friday logic
            $first = $ny->copy()->startOfMonth();
            $firstFri = $first->isFriday() ? $first : $first->next(Carbon::FRIDAY);
            $thirdFri = $firstFri->copy()->addWeeks(2)->toDateString();
            $expDates = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereDate('expiration_date', $thirdFri)
                ->pluck('expiration_date')
                ->unique()->values()->all();
        } else {
            // sane default
            $start = $ny->copy()->startOfDay()->toDateString();
            $end = $ny->copy()->addDays(14)->toDateString();
            $expDates = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereBetween('expiration_date', [$start, $end])
                ->orderBy('expiration_date')
                ->pluck('expiration_date')
                ->unique()
                ->values()
                ->all();
        }

        // A read reports current readiness only. Expensive work starts through POST /api/prime.
        if (empty($expDates)) {
            return response()->json(array_merge([
                'status' => $activeRun ? 'queued' : 'missing',
                'symbol' => $symbol,
                'run' => $runPayload,
            ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []), $activeRun ? 202 : 404);
        }

        // target expirations -> ids
        $expIds = DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->whereIn('expiration_date', $expDates)
            ->pluck('id');

        // any chain rows for *today* for those expirations?
        $rowsToday = DB::table('option_chain_data')
            ->whereIn('expiration_id', $expIds)
            ->whereDate('data_date', $tradeDate);

        $targetExpCount = count($expIds);
        $coveredExpCount = (clone $rowsToday)->distinct('expiration_id')->count();
        $totalRows = (clone $rowsToday)->count();

        // dynamic thresholds by window: fewer expiries => lower bar
        $minExpToCover = in_array($timeframe, ['0d', '1d'], true) ? 1 : min($targetExpCount, 3);

        // rows bar: high for index ETFs, low for others
        $symbolHighLiquidity = in_array($symbol, ['SPY', 'QQQ', 'IWM'], true);
        $minRows = $symbolHighLiquidity ? 400 : 40;

        // flip to ready if we have *any* rows for today (fast-path)
        $hasAnyRowsToday = $totalRows > 0;

        // stricter "healthy" readiness (kept for safety)
        $healthy = ($totalRows >= $minRows) && ($coveredExpCount >= $minExpToCover);

        if ($hasAnyRowsToday || $healthy) {
            return response()->json(array_merge([
                'status' => 'ready',
                'symbol' => $symbol,
                'expirations_targeted' => $targetExpCount,
                'expirations_covered' => $coveredExpCount,
                'rows_today' => $totalRows,
                'run' => $runPayload,
            ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []));
        }

        // we have expirations but not enough rows yet -> fetching
        // (also useful to expose progress to the UI)
        return response()->json(array_merge([
            'status' => $activeRun ? 'fetching' : 'incomplete',
            'symbol' => $symbol,
            'expirations_targeted' => $targetExpCount,
            'expirations_covered' => $coveredExpCount,
            'rows_today' => $totalRows,
            'run' => $runPayload,
        ], $bootstrapPayload !== null ? ['bootstrap' => $bootstrapPayload] : []), $activeRun ? 202 : 200);
    }
}
