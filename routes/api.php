<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GexController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\VolController;
use App\Http\Controllers\PositioningController;
use App\Http\Controllers\SeasonalityController;
use App\Http\Controllers\QScoreController;
use App\Http\Controllers\ExpiryController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\SymbolSearchController;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Http\Controllers\IntradayController;

use App\Jobs\PrimeSymbolJob;
use App\Models\User;
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

Route::get('/user', function (Request $request): User {
    return $request->user();
})->middleware('auth:sanctum');


Route::get('/gex-levels', [GexController::class, 'getGexLevels']);
Route::post('/intraday/pull', [IntradayController::class, 'pull']);
Route::get('/intraday/summary', [IntradayController::class, 'summary']);
Route::get('/intraday/volume-by-strike', [IntradayController::class, 'volumeByStrike']);
Route::get('/intraday/ua', [IntradayController::class, 'ua']);

Route::middleware(['auth:sanctum'])->group(function () {
    // Watchlist
    Route::get('/watchlist', [WatchlistController::class, 'index']);
    Route::post('/watchlist', [WatchlistController::class, 'store']);
    Route::delete('/watchlist/{id}', [WatchlistController::class, 'destroy']);

    // Prime a symbol on-demand
    Route::post('/prime', function (Request $req) {
        $sym = \App\Support\Symbols::canon($req->input('symbol',''));
        if (!$sym) return response()->noContent(204);
        dispatch(new PrimeSymbolJob($sym));
        return response()->noContent(204);
    });
});
Route::get('/symbols', [SymbolSearchController::class, 'lookup']);
Route::get('/iv/term', [VolController::class,'term']);
Route::get('/vrp',     [VolController::class,'vrp']);
Route::get('/qscore', [QScoreController::class, 'show']);
Route::get('/seasonality/5d', [SeasonalityController::class, 'fiveDay']);
Route::get('/iv/skew', [VolController::class, 'skew']);
Route::get('/iv/skew/debug', [VolController::class, 'skewDebug']);
Route::get('/iv/skew/by-bucket', [VolController::class, 'skewByBucket']);
Route::get('/iv/skew/history', [VolController::class, 'skewHistory']);
Route::get('/iv/skew/history/bucket', [VolController::class, 'skewHistoryBucket']);
Route::get('/dex', [PositioningController::class, 'dex']);
Route::get('/expiry-pressure', [ExpiryController::class, 'pressure']);
Route::get('/expiry-pressure/batch',  [ExpiryController::class, 'pressureBatch']);
Route::get('/ua', [ActivityController::class, 'index']);

Route::get('/ua/debug', function (Request $req) {
  $symbol = \App\Support\Symbols::canon($req->query('symbol','spy'));
  $latest = DB::table('option_chain_data as o')
    ->join('option_expirations as e','e.id','=','o.expiration_id')
    ->where('e.symbol',$symbol)
    ->max('o.data_date');

  $expiries = DB::table('option_chain_data as o')
    ->join('option_expirations as e','e.id','=','o.expiration_id')
    ->where('e.symbol',$symbol)
    ->whereDate('o.data_date',$latest)
    ->distinct()->pluck('e.expiration_date');

  return response()->json(compact('symbol','latest','expiries'));
});

Route::post('/intraday/pull', function (Request $req) {
    $symbols = (array) $req->input('symbols', ['SPY']);
    $symbols = array_map(fn($s)=>\App\Support\Symbols::canon($s), $symbols);
    dispatch(new FetchPolygonIntradayOptionsJob($symbols));
    return response()->json(['ok'=>true,'symbols'=>$symbols]);
});
