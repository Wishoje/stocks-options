<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Watchlist;

class WatchlistController extends Controller
{
    public function index()
    {
        $items = Watchlist::where('user_id', Auth::id())->get();
        return response()->json($items);
    }

    public function store(Request $request)
    {
        $request->validate([
            'symbol' => 'required|string|max:10',
            'timeframe' => 'nullable|string'
        ]);

        $item = Watchlist::create([
            'user_id'   => Auth::id(),
            'symbol'    => strtoupper($request->symbol),
            'timeframe' => $request->timeframe ?? '14d'
        ]);

        return response()->json($item, 201);
    }

    public function destroy($id)
    {
        $item = Watchlist::where('id', $id)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $item->delete();
        return response()->json(['message' => 'Deleted'], 200);
    }

    public function fetchAllData()
    {
        $userId = Auth::id();
        $items = Watchlist::where('user_id', $userId)->get();

        if ($items->isEmpty()) {
            return response()->json(['message' => 'No watchlist items to fetch'], 200);
        }

        // collect all symbols
        $symbols = $items->pluck('symbol')->unique()->values()->toArray();

        // optional: if each watchlist row has a timeframe, do something with it
        // e.g. dispatch multiple times or read days from timeframe
        // For simplicity, let's just dispatch a job for all symbols (14-day default).
        \Log::info("Fetching data for watchlist: " . implode(',', $symbols));
        
        // Dispatch the job (synchronously or asynchronously)
        // If you want to pass days based on watchlist timeframe, parse it here.
        // For example, if timeframe= '7d' => 7, '14d' => 14, 'monthly' => ??? 
        // We'll do a simpler approach: dispatch one job with no days => 14 by default
        \App\Jobs\FetchOptionChainDataJob::dispatchSync($symbols);

        return response()->json(['message' => 'Data fetch started for your watchlist'], 200);
    }
}
