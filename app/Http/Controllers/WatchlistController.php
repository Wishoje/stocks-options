<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Watchlist;
use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Support\Market;
use App\Support\QueueLanes;

class WatchlistController extends Controller
{
    public function index()
    {
        return Watchlist::query()
            ->where('user_id', Auth::id())
            ->orderBy('symbol')
            ->get(['id','symbol']);
    }

    public function universe()
    {
        return Watchlist::query()
            ->select('symbol')
            ->distinct()
            ->orderBy('symbol')
            ->get()
            ->map(fn (Watchlist $row) => ['symbol' => $row->symbol])
            ->values();
    }

    public function store(Request $req)
    {
        $symbol = \App\Support\Symbols::canon($req->input('symbol', ''));
        if (!$symbol) return response()->json(['message'=>'Symbol required'], 422);

        $row = Watchlist::firstOrCreate(
            ['user_id' => Auth::id(), 'symbol' => $symbol],
            []
        );

        BootstrapUserSymbolJob::dispatchIfNeeded($symbol, 'watchlist_store');

        // Intraday bootstrap:
        // - during market hours: always allow
        // - outside market hours: allow only when symbol has no intraday rows yet
        $marketOpen = Market::isRthOpen(now('America/New_York'));
        $hasIntraday = DB::table('option_live_counters')
            ->where('symbol', $symbol)
            ->exists();
        $hasExpiries = DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->exists();

        if (($marketOpen || !$hasIntraday) && $hasExpiries) {
            $dispatchLock = Cache::lock("watchlist:intraday:{$symbol}", 45);
            if ($dispatchLock->get()) {
                try {
                    Bus::dispatch(
                        (new FetchPolygonIntradayOptionsJob([$symbol]))
                            ->onQueue($this->intradayQueueForSymbol($symbol))
                    );
                } catch (\Throwable $exception) {
                    $dispatchLock->release();
                    throw $exception;
                }
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
        return QueueLanes::intraday($symbol, interactive: true);
    }
}
