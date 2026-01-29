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
use App\Http\Controllers\IntradayController;
use App\Http\Controllers\WallScannerController;
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
Route::get('/intraday/strikes', [IntradayController::class, 'strikesComposite']);
Route::get('/intraday/repriced-gex-by-strike', [IntradayController::class, 'repricedGexByStrike']);
Route::get('/symbol/status', [\App\Http\Controllers\SymbolStatusController::class, 'show']);


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

Route::post('/intraday/pull', [IntradayController::class, 'pull']);
Route::get('/hot-options', [\App\Http\Controllers\HotOptionsController::class, 'index']);
Route::post('/scanner/walls', [WallScannerController::class, 'scan']);

Route::get('/option-chain', function () {
    $symbol = strtoupper(request('symbol', 'SPY'));
    $expiry = request('expiry');

    // Find the latest snapshot for this symbol
    $latest = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->max('fetched_at');

    if (!$latest) {
        return response()->json([
            'underlying'  => ['symbol' => $symbol, 'price' => null],
            'chain'       => [],
            'expirations' => [],
        ]);
    }

    $base = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->where('fetched_at', $latest);

    $spot = app(\App\Services\WallService::class)->currentPrice($symbol, null);
    $price = $spot ?? $base->value('underlying_price') ?? 100;

    if (!$expiry) {
        $expiry = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->where('fetched_at', $latest)
            ->where('expiry', '>=', today())
            ->orderBy('expiry')
            ->value('expiry');
    }

    $chainQuery = $base->clone()
        ->select(
            'strike',
            'type',
            'bid',
            'ask',
            'mid',
            DB::raw("DATE_FORMAT(expiry, '%Y-%m-%d') as expiry_full"),
            DB::raw("DATE_FORMAT(expiry, '%m-%d') as expiry")
        );

    if ($expiry) {
        $chainQuery->whereDate('expiry', '=', $expiry);
    }

    $chain = $chainQuery
        ->orderBy('strike')
        ->get()
        ->map(function ($row) {
            // normalize field names for the front-end
            return [
                'strike' => (float) $row->strike,
                'type'   => $row->type,
                'bid'    => (float) $row->bid,
                'ask'    => (float) $row->ask,
                'mid'    => (float) $row->mid,
                'expiry' => $row->expiry_full, // full YYYY-MM-DD for JS Date
                'label'  => $row->expiry,      // MM-DD for display if needed
            ];
        });

    $expirations = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->where('expiry', '>=', today())
        ->select('expiry')
        ->distinct()
        ->orderBy('expiry')
        ->get()
        ->map(fn ($r) => [
            'value' => $r->expiry, // full YYYY-MM-DD
            'label' => \Carbon\Carbon::parse($r->expiry)->format('m-d'),
        ])
        ->toArray();

    return [
        'underlying'  => ['symbol' => $symbol, 'price' => round($price, 2)],
        'chain'       => $chain,
        'expirations' => $expirations,
        'snapshot_at' => $latest,
    ];
});

Route::get('/debug/market', function () {
    $nowNy = \Carbon\Carbon::now('America/New_York');
    return response()->json([
        'now_et' => $nowNy->toDateTimeString(),
        'is_rth_open' => \App\Support\Market::isRthOpen($nowNy),
    ]);
});

Route::post('/prime-calculator', function (Request $req) {
    $sym = \App\Support\Symbols::canon($req->input('symbol', 'SPY'));
    if (!$sym) {
        return response()->json(['error' => 'Invalid symbol'], 400);
    }

    // Queue the calculator job so the request doesn't block
    \App\Jobs\FetchCalculatorChainJob::dispatch($sym)->onQueue('calculator');

    return response()->json([
        'ok'     => true,
        'symbol' => $sym,
        'queued' => true,
        'queue'  => 'calculator',
    ]);
});
