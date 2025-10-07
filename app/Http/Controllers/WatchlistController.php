<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Bus\Batch;
use Throwable;

use App\Models\Watchlist;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\Seasonality5DJob;

class WatchlistController extends Controller
{
    protected function tfToDays(string $tf): int
    {
        return match ($tf) {
            '0d' => 0,  '1d' => 1,  '7d' => 7,  '14d' => 14,
            '21d' => 21,'30d' => 30,'45d' => 45,'60d' => 60,
            '90d' => 90,
            default => 14,
        };
    }

    public function index()
    {
        return Watchlist::query()
            ->where('user_id', Auth::id())
            ->orderBy('symbol')
            ->get();
    }

    public function store(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->input('symbol'));
        $timeframe = $req->input('timeframe', '14d');

        // prevent dupes per user
        $row = Watchlist::firstOrCreate(
            ['user_id'=>Auth::id(), 'symbol'=>$symbol, 'timeframe'=>$timeframe],
            []
        );

        return response()->json($row->fresh(), 201);
    }

    public function destroy(int $id)
    {
        Watchlist::where('id', $id)->where('user_id', Auth::id())->delete();
        return response()->noContent();
    }

    public function fetchAllData(Request $req)
    {
        $rows = Watchlist::where('user_id', Auth::id())->get(['symbol','timeframe']);
        if ($rows->isEmpty()) {
            return response()->json(['message'=>'Nothing to fetch (watchlist empty).'], 200);
        }

        // Max required window per symbol
        $bySymbol = [];
        foreach ($rows as $r) {
            $sym  = \App\Support\Symbols::canon($r->symbol);
            $days = $this->tfToDays($r->timeframe);
            $bySymbol[$sym] = max($bySymbol[$sym] ?? 0, $days);
        }
        $symbols = array_keys($bySymbol);

        $batch = Bus::batch([])
            ->name('FetchAll pipeline')
            ->allowFailures()
            ->then(function (Batch $batch) {})
            ->catch(function (Batch $batch, Throwable $e) {})
            ->dispatch();

        // Prices (broad backfill + today)
        $batch->add(new PricesBackfillJob($symbols, 400));
        $batch->add(new PricesDailyJob($symbols));

        // Option chains per symbol/window
        foreach ($bySymbol as $sym => $days) {
            $batch->add(new FetchOptionChainDataJob([$sym], $days));
        }

        // Derived metrics
        $batch->add(new ComputeVolMetricsJob($symbols));
        $batch->add(new Seasonality5DJob($symbols, 15, 2));

        return response()->json(['message'=>'Fetch pipeline queued', 'batch_id'=>$batch->id], 202);
    }
}
