<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Watchlist;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\PrimeSymbolJob;
use App\Support\Market;

class WatchlistController extends Controller
{
    public function index()
    {
        return Watchlist::query()
            ->where('user_id', Auth::id())
            ->orderBy('symbol')
            ->get(['id','symbol']);
    }

    public function store(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->input('symbol', ''));
        if (!$symbol) return response()->json(['message'=>'Symbol required'], 422);

        $row = Watchlist::firstOrCreate(
            ['user_id' => Auth::id(), 'symbol' => $symbol],
            []
        );

        // prime immediately (non-blocking), debounced to avoid storms
        if (Cache::add("watchlist:prime:{$symbol}", 1, now()->addSeconds(45))) {
            dispatch(new PrimeSymbolJob($symbol))->onQueue('default');
        }

        // Intraday bootstrap:
        // - during market hours: always allow
        // - outside market hours: allow only when symbol has no intraday rows yet
        $marketOpen = Market::isRthOpen(now('America/New_York'));
        $hasIntraday = DB::table('option_live_counters')
            ->where('symbol', $symbol)
            ->exists();

        if ($marketOpen || !$hasIntraday) {
            if (Cache::add("watchlist:intraday:{$symbol}", 1, now()->addSeconds(45))) {
                dispatch(new FetchPolygonIntradayOptionsJob([$symbol]))
                    ->onQueue($this->intradayQueueForSymbol($symbol));
            }
        }

        // Option A
        $row->refresh(); // reload from DB
        return response()->json($row->only(['id','symbol']), 201);
    }

    public function destroy(int $id)
    {
        Watchlist::where('id', $id)->where('user_id', Auth::id())->delete();
        return response()->noContent();
    }

    private function intradayQueueForSymbol(string $symbol): string
    {
        return in_array($symbol, ['SPY', 'QQQ'], true) ? 'intraday-heavy' : 'intraday';
    }
}
