<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SymbolStatusController extends Controller
{
    public function show(Request $req)
    {
        $symbol = strtoupper($req->query('symbol', 'SPY'));
        $timeframe = $req->query('timeframe', '14d'); // keep in sync with UI default

        // --- trading date (NY, roll back on weekends) ---
        $ny = Carbon::now('America/New_York');
        if ($ny->isWeekend()) {
            $ny = $ny->previousWeekday();
        }
        $tradeDate = $ny->toDateString();

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

        // nothing seeded yet? enqueue full prime + intraday and report queued
        if (empty($expDates)) {
            $this->queueBootstrap($symbol);

            return response()->json([
                'status' => 'queued',
                'symbol' => $symbol,
            ], 202);
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
            return response()->json([
                'status' => 'ready',
                'symbol' => $symbol,
                'expirations_targeted' => $targetExpCount,
                'expirations_covered' => $coveredExpCount,
                'rows_today' => $totalRows,
            ]);
        }

        // we have expirations but not enough rows yet -> fetching
        // (also useful to expose progress to the UI)
        $this->queueBootstrap($symbol);

        return response()->json([
            'status' => 'fetching',
            'symbol' => $symbol,
            'expirations_targeted' => $targetExpCount,
            'expirations_covered' => $coveredExpCount,
            'rows_today' => $totalRows,
        ], 202);
    }

    private function queueBootstrap(string $symbol): void
    {
        $sym = strtoupper(trim($symbol));
        if ($sym === '') {
            return;
        }

        if (Cache::add("symbol-status:prime:{$sym}", 1, now()->addSeconds(45))) {
            dispatch(new \App\Jobs\PrimeSymbolJob($sym))->onQueue('default');
        }

        if (Cache::add("symbol-status:intraday:{$sym}", 1, now()->addSeconds(45))) {
            dispatch(new \App\Jobs\FetchPolygonIntradayOptionsJob([$sym]))
                ->onQueue($this->intradayQueueForSymbol($sym));
        }
    }

    private function intradayQueueForSymbol(string $symbol): string
    {
        return in_array($symbol, ['SPY', 'QQQ'], true) ? 'intraday-heavy' : 'intraday';
    }
}
