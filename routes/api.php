<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AiExportController;
use App\Http\Controllers\CalculatorChainController;
use App\Http\Controllers\CalculatorRefreshController;
use App\Http\Controllers\EodHealthController;
use App\Http\Controllers\ExpiryController;
use App\Http\Controllers\GexController;
use App\Http\Controllers\IntradayController;
use App\Http\Controllers\PositioningController;
use App\Http\Controllers\QScoreController;
use App\Http\Controllers\SeasonalityController;
use App\Http\Controllers\SymbolPrimeController;
use App\Http\Controllers\SymbolSearchController;
use App\Http\Controllers\VolController;
use App\Http\Controllers\WallScannerController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\WorkRunController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health/ingest', function () {
    $rows = Cache::remember('health:ingest:v1', now()->addMinute(), fn () => DB::table('option_chain_data as o')
        ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
        ->selectRaw('e.symbol, MAX(o.data_timestamp) as last_ts, MAX(o.data_date) as last_date')
        ->groupBy('e.symbol')
        ->get());

    return response()->json($rows);
})->middleware(['auth:sanctum', 'eodhealth', 'throttle:market-data-read']);

Route::get('/me', function () {
    return response()->json([
        'id' => Auth::id(),
        'user' => Auth::user(),
    ]);
})->middleware('auth:sanctum');

Route::get('/user', function (Request $request): User {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/iv/skew/debug', [VolController::class, 'skewDebug'])
    ->middleware(['auth:sanctum', 'eodhealth', 'throttle:market-data-read']);

Route::get('/ua/debug', function (Request $req) {
    $symbol = \App\Support\Symbols::canon($req->query('symbol', 'spy'));
    $latest = DB::table('option_chain_data as o')
        ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
        ->where('e.symbol', $symbol)
        ->max('o.data_date');

    $expiries = DB::table('option_chain_data as o')
        ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
        ->where('e.symbol', $symbol)
        ->whereDate('o.data_date', $latest)
        ->distinct()->pluck('e.expiration_date');

    return response()->json(compact('symbol', 'latest', 'expiries'));
})->middleware(['auth:sanctum', 'eodhealth', 'throttle:market-data-read']);

Route::get('/debug/market', function () {
    $nowNy = \Carbon\Carbon::now('America/New_York');

    return response()->json([
        'now_et' => $nowNy->toDateTimeString(),
        'is_rth_open' => \App\Support\Market::isRthOpen($nowNy),
    ]);
})->middleware(['auth:sanctum', 'eodhealth', 'throttle:market-data-read']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/eod/health', [EodHealthController::class, 'index'])
        ->middleware(['eodhealth', 'throttle:market-data-read']);

    Route::middleware('feature:app.access,strict')->group(function () {
        Route::middleware('throttle:market-data-read')->group(function () {
            Route::get('/gex-levels', [GexController::class, 'getGexLevels']);
            Route::get('/symbols', [SymbolSearchController::class, 'lookup']);
            Route::get('/symbol/status', [\App\Http\Controllers\SymbolStatusController::class, 'show']);

            Route::get('/watchlist', [WatchlistController::class, 'index']);
            Route::get('/watchlist/universe', [WatchlistController::class, 'universe']);
            Route::get('/watchlist/eod-exports', [AiExportController::class, 'index'])
                ->name('api.ai-export.index');
            Route::get('/watchlist/eod-export/{export}', [AiExportController::class, 'show'])
                ->name('api.ai-export.show');
            Route::get('/watchlist/eod-export/{export}/download', [AiExportController::class, 'download'])
                ->name('api.ai-export.download');

            Route::get('/iv/term', [VolController::class, 'term']);
            Route::get('/vrp', [VolController::class, 'vrp']);
            Route::get('/qscore', [QScoreController::class, 'show']);
            Route::get('/seasonality/5d', [SeasonalityController::class, 'fiveDay']);
            Route::get('/iv/skew', [VolController::class, 'skew']);
            Route::get('/iv/skew/by-bucket', [VolController::class, 'skewByBucket']);
            Route::get('/iv/skew/history', [VolController::class, 'skewHistory']);
            Route::get('/iv/skew/history/bucket', [VolController::class, 'skewHistoryBucket']);
            Route::get('/dex', [PositioningController::class, 'dex']);
            Route::get('/expiry-pressure', [ExpiryController::class, 'pressure']);
            Route::get('/expiry-pressure/batch', [ExpiryController::class, 'pressureBatch']);
            Route::get('/ua', [ActivityController::class, 'index']);
        });

        Route::middleware('throttle:work-start')->group(function () {
            Route::post('/watchlist', [WatchlistController::class, 'store']);
            Route::delete('/watchlist/{id}', [WatchlistController::class, 'destroy']);
            Route::post('/watchlist/eod-export', [AiExportController::class, 'queue'])
                ->name('api.ai-export.queue');
            Route::post('/prime', [SymbolPrimeController::class, 'store']);
        });
    });

    Route::middleware('feature:intraday.access,strict')->group(function () {
        Route::middleware('throttle:market-data-read')->group(function () {
            Route::get('/intraday/summary', [IntradayController::class, 'summary']);
            Route::get('/intraday/volume-by-strike', [IntradayController::class, 'volumeByStrike']);
            Route::get('/intraday/ua', [IntradayController::class, 'ua']);
            Route::get('/intraday/strikes', [IntradayController::class, 'strikesComposite']);
            Route::get('/intraday/repriced-gex-by-strike', [IntradayController::class, 'repricedGexByStrike']);
        });

        Route::post('/intraday/pull', [IntradayController::class, 'pull'])
            ->middleware('throttle:work-start');
    });

    Route::middleware(['feature:scanner.access,strict', 'throttle:market-data-read'])->group(function () {
        Route::get('/hot-options', [\App\Http\Controllers\HotOptionsController::class, 'index']);
        Route::post('/scanner/walls', [WallScannerController::class, 'scan']);
    });

    Route::middleware('feature:calculator.access,strict')->group(function () {
        Route::get('/option-chain', [CalculatorChainController::class, 'show'])
            ->middleware('throttle:market-data-read');
        Route::post('/prime-calculator', [CalculatorRefreshController::class, 'store'])
            ->middleware('throttle:work-start');
    });

    // Check account access before resolving a user-supplied work-run ID.
    Route::get('/work-runs/{runId}', [WorkRunController::class, 'show'])
        ->middleware(['subscribed', 'work-run-feature', 'throttle:work-status'])
        ->name('api.work-runs.show');
});

Route::get('/wall-foundation/audit', [\App\Http\Controllers\WallFoundationController::class, 'audit'])
    ->middleware([\App\Http\Middleware\EnsureLocalWallReview::class, 'auth:sanctum', 'feature:app.access,strict', 'throttle:10,1']);
