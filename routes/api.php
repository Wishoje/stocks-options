<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GexController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Route::get('/health/ingest', function () {
    $rows = DB::table('option_chain_data as o')
        ->join('option_expirations as e','e.id','=','o.expiration_id')
        ->selectRaw('e.symbol, MAX(o.data_timestamp) as last_ts, MAX(o.data_date) as last_date')
        ->groupBy('e.symbol')
        ->get();
    return response()->json($rows);
});

Route::get('/me', function () {
    return response()->json([
        'id' => Auth::id(),
        'user' => Auth::user(),
    ]);
})->middleware('auth:sanctum');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/gex-levels', [GexController::class, 'getGexLevels']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/watchlist/fetch', [WatchlistController::class, 'fetchAllData']);
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::post('/watchlist', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/{id}', [WatchlistController::class, 'destroy']);
});