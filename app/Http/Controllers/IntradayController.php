<?php

namespace App\Http\Controllers;

use App\Jobs\FetchPolygonIntradayOptionsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;

class IntradayController extends Controller
{
    // POST /api/intraday/pull { symbols: ["SPY","QQQ"] }
    public function pull(Request $request)
    {
        $symbols = $request->input('symbols', []);
        if (!is_array($symbols) || empty($symbols)) {
            return response()->json(['error' => 'symbols[] required'], 422);
        }

        // dispatch immediately (batch is fine or straight dispatch)
        Bus::dispatch(new FetchPolygonIntradayOptionsJob($symbols));

        return response()->json(['ok' => true]);
    }

    // GET /api/intraday/summary?symbol=SPY
    public function summary(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));

        // pick NY trading date same logic as job
        $tradeDate = $this->tradingDate(now());

        // grab that one "totals row": exp_date NULL, strike NULL, option_type NULL
        $row = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereNull('exp_date')
            ->whereNull('strike')
            ->whereNull('option_type')
            ->orderByDesc('updated_at')
            ->first();

        if (!$row) {
            // nothing yet today
            return response()->json([
                'asof'   => null,
                'totals' => [
                    'call_vol' => 0,
                    'put_vol'  => 0,
                    'total'    => 0,
                    'pcr_vol'  => null,
                    'premium'  => 0,
                ],
            ]);
        }

        // We only stored combined volume (calls+puts) and combined premium_usd on that row.
        // If you want split call_vol / put_vol in summary, we have 2 options:
        //   (1) re-sum below from per-strike rows by option_type
        //   (2) extend FetchPolygonIntradayOptionsJob to store separate counters.
        //
        // We'll do (1) here so you don't have to change the job yet.

        [$callVol, $putVol] = $this->sumTypeVolumes($symbol, $tradeDate);

        $pcr = null;
        if ($callVol > 0) {
            // pcr = puts / calls
            $pcr = round($putVol / $callVol, 3);
        }

        return response()->json([
            'asof' => $row->asof,
            'totals' => [
                'call_vol' => $callVol,
                'put_vol'  => $putVol,
                'total'    => (int) $row->volume,         // job sets volume = call+put
                'pcr_vol'  => $pcr,
                'premium'  => (float) $row->premium_usd,  // est notional
            ],
        ]);
    }

    // GET /api/intraday/volume-by-strike?symbol=SPY
    public function volumeByStrike(Request $request)
    {
        $symbol = strtoupper($request->query('symbol', 'SPY'));
        $tradeDate = $this->tradingDate(now());

        // Pull latest rows for this symbol+day WHERE strike is not null (so skip the totals row)
        // We'll aggregate per strike across expirations.
        $rows = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereNotNull('strike')
            ->orderBy('strike')
            ->get([
                'strike',
                'option_type',
                'volume',
            ]);

        // Roll up: strike -> { call_vol, put_vol }
        $byStrike = [];
        foreach ($rows as $r) {
            $K = (string)$r->strike;
            if (!isset($byStrike[$K])) {
                $byStrike[$K] = [
                    'strike'   => (float)$r->strike,
                    'call_vol' => 0,
                    'put_vol'  => 0,
                ];
            }
            if ($r->option_type === 'call') {
                $byStrike[$K]['call_vol'] += (int)$r->volume;
            } elseif ($r->option_type === 'put') {
                $byStrike[$K]['put_vol'] += (int)$r->volume;
            }
        }

        // turn dict -> array sorted by strike asc
        $items = array_values($byStrike);
        usort($items, fn($a,$b) => $a['strike'] <=> $b['strike']);

        return response()->json([
            'items' => $items,
        ]);
    }

    private function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }
        return $ny->toDateString();
    }

    /**
     * helper for summary(): sum today's call/put vol across all buckets
     */
    private function sumTypeVolumes(string $symbol, string $tradeDate): array
    {
        $callVol = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->where('option_type', 'call')
            ->sum('volume');

        $putVol = \App\Models\OptionLiveCounter::query()
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->where('option_type', 'put')
            ->sum('volume');

        return [(int)$callVol, (int)$putVol];
    }

     public function ua(Request $request)
    {
        // TODO: replace with true intraday UA logic.
        // For now just call the same service you use for /api/ua so the UI has data.
        return app(\App\Http\Controllers\ActivityController::class)->index($request);
    }
}
