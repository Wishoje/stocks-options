<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Watchlist;
use App\Jobs\PrimeSymbolJob;

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
        // prime immediately (non-blocking)
        dispatch(new PrimeSymbolJob($symbol));

        // Option A
        $row->refresh(); // reload from DB
        return response()->json($row->only(['id','symbol']), 201);
    }

    public function destroy(int $id)
    {
        Watchlist::where('id', $id)->where('user_id', Auth::id())->delete();
        return response()->noContent();
    }
}
