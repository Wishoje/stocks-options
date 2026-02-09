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
use Illuminate\Support\Facades\Cache;
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
    $expiry = $expiry ? substr((string) $expiry, 0, 10) : null;
    $refreshQueued = false;

    $queuePrime = function (string $sym, ?string $exp = null) use (&$refreshQueued): bool {
        $lockKey = 'calculator:prime:' . md5($sym.'|'.($exp ?? '*'));
        if (!Cache::add($lockKey, 1, now()->addSeconds(90))) {
            return false;
        }

        \App\Jobs\FetchCalculatorChainJob::dispatch($sym, $exp)->onQueue('calculator');
        $refreshQueued = true;
        return true;
    };

    $recentCutoff = now('UTC')->subHours(24);
    $today = today()->toDateString();

    // Expiration menu: use recent snapshots (not a single fetched_at), then fallback.
    $expRows = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->where('expiry', '>=', $today)
        ->where('fetched_at', '>=', $recentCutoff)
        ->select('expiry')
        ->distinct()
        ->orderBy('expiry')
        ->get();

    if ($expRows->isEmpty()) {
        $expRows = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->where('expiry', '>=', $today)
            ->select('expiry')
            ->distinct()
            ->orderBy('expiry')
            ->get();
    }

    if (!$expiry) {
        $expiry = data_get($expRows->first(), 'expiry');
    }

    // If symbol/expiry absent in DB, one-shot targeted prime.
    if (!$expiry) {
        $queuePrime($symbol, null);

        $expRows = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->where('expiry', '>=', $today)
            ->select('expiry')
            ->distinct()
            ->orderBy('expiry')
            ->get();

        $expiry = data_get($expRows->first(), 'expiry');
        if (!$expiry) {
            return response()->json([
                'underlying'  => ['symbol' => $symbol, 'price' => null],
                'chain'       => [],
                'expirations' => [],
                'status'      => 'no_snapshot',
                'refresh_queued' => $refreshQueued,
            ], 202);
        }
    }

    $spotForHealth = app(\App\Services\WallService::class)->currentPrice($symbol, null);
    $latest = null;
    $stats = null;

    // For this expiry, prefer a healthy/fuller snapshot over blindly taking newest.
    if ($spotForHealth && $spotForHealth > 0) {
        $stats = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('expiry', $expiry)
            ->selectRaw('fetched_at, COUNT(*) as row_count, MIN(strike) as min_strike, MAX(strike) as max_strike')
            ->groupBy('fetched_at')
            ->havingRaw('COUNT(*) >= 40')
            ->havingRaw('MAX(strike) >= ?', [(float) $spotForHealth * 1.05])
            ->havingRaw('MIN(strike) <= ?', [(float) $spotForHealth * 0.95])
            ->orderByDesc('fetched_at')
            ->first();
    }

    if (!$stats) {
        $stats = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('expiry', $expiry)
            ->selectRaw('fetched_at, COUNT(*) as row_count, MIN(strike) as min_strike, MAX(strike) as max_strike')
            ->groupBy('fetched_at')
            ->orderByDesc('row_count')
            ->orderByDesc('fetched_at')
            ->first();
    }

    if ($stats && isset($stats->fetched_at)) {
        $latest = $stats->fetched_at;
    }

    $looksTruncated = $spotForHealth
        && $stats
        && isset($stats->max_strike)
        && (float) $stats->max_strike > 0
        && (float) $stats->max_strike < ((float) $spotForHealth * 0.85);

    // Self-heal thin/partial chains for this specific expiry.
    if (
        !$latest ||
        !$stats ||
        (int) ($stats->row_count ?? 0) < 40 ||
        $looksTruncated
    ) {
        $queuePrime($symbol, $expiry);
    }

    if (!$latest) {
        return response()->json([
            'underlying'  => ['symbol' => $symbol, 'price' => null],
            'chain'       => [],
            'expirations' => $expRows->map(fn ($r) => [
                'value' => $r->expiry,
                'label' => \Carbon\Carbon::parse($r->expiry)->format('m-d'),
            ])->toArray(),
            'status'      => 'no_expiry_snapshot',
            'requested_expiry' => $expiry,
            'refresh_queued' => $refreshQueued,
        ], 202);
    }

    $spot = $spotForHealth;
    $basePrice = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->whereDate('expiry', $expiry)
        ->where('fetched_at', $latest)
        ->value('underlying_price');
    $price = $spot ?? $basePrice ?? 100;

    $chain = DB::table('option_snapshots')
        ->where('symbol', $symbol)
        ->whereDate('expiry', $expiry)
        ->where('fetched_at', $latest)
        ->select(
            'strike',
            'type',
            'bid',
            'ask',
            'mid',
            DB::raw("DATE_FORMAT(expiry, '%Y-%m-%d') as expiry_full"),
            DB::raw("DATE_FORMAT(expiry, '%m-%d') as expiry")
        )
        ->orderBy('strike')
        ->get()
        ->map(function ($row) {
            return [
                'strike' => (float) $row->strike,
                'type'   => $row->type,
                'bid'    => (float) $row->bid,
                'ask'    => (float) $row->ask,
                'mid'    => (float) $row->mid,
                'expiry' => $row->expiry_full,
                'label'  => $row->expiry,
            ];
        });

    $expirations = $expRows
        ->map(fn ($r) => [
            'value' => $r->expiry,
            'label' => \Carbon\Carbon::parse($r->expiry)->format('m-d'),
        ])
        ->toArray();

    return [
        'underlying'  => ['symbol' => $symbol, 'price' => round($price, 2)],
        'chain'       => $chain,
        'expirations' => $expirations,
        'snapshot_at' => $latest,
        'snapshot_stats' => [
            'row_count' => (int) ($stats->row_count ?? 0),
            'min_strike' => isset($stats->min_strike) ? (float) $stats->min_strike : null,
            'max_strike' => isset($stats->max_strike) ? (float) $stats->max_strike : null,
        ],
        'requested_expiry' => $expiry,
        'refresh_queued' => $refreshQueued,
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
    if (!$sym) return response()->json(['error' => 'Invalid symbol'], 400);
    $expiry = $req->input('expiry');
    $expiry = $expiry ? substr((string) $expiry, 0, 10) : null;
    $sync = $req->boolean('sync', false);

    if ($sync) {
        dispatch_sync(new \App\Jobs\FetchCalculatorChainJob($sym, $expiry));
        return response()->json([
            'ok' => true,
            'symbol' => $sym,
            'expiry' => $expiry,
            'mode' => 'sync',
        ]);
    }

    $lockKey = 'calculator:prime:' . md5($sym.'|'.($expiry ?? '*'));
    $queued = Cache::add($lockKey, 1, now()->addSeconds(90));
    if ($queued) {
        \App\Jobs\FetchCalculatorChainJob::dispatch($sym, $expiry)->onQueue('calculator');
    }

    return response()->json([
        'ok' => true,
        'symbol' => $sym,
        'expiry' => $expiry,
        'mode' => 'queue',
        'queued' => $queued,
    ], $queued ? 202 : 200);
});
