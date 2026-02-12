<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Support\Market;
use App\Support\Symbols;

// Example default command
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
});

// 2) Build the daily snapshot a bit later
Schedule::command('chain:snapshot')
    ->weekdays()
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->at('17:30');

// 3) Compute vol metrics nightly
Schedule::job(new ComputeVolMetricsJob(['SPY','QQQ','IWM']))
    ->timezone('America/New_York')
    ->dailyAt('17:45');

// 4) Seed prices then compute VRP/term after close (order matters)
Schedule::command('prices:seed SPY QQQ IWM')
    ->weekdays()
    ->timezone('America/New_York')
    ->onOneServer()
    ->withoutOverlapping(15)
    ->at('16:05');

Schedule::command('walls:compute --timeframe=all --limit=400 --source=hot')
    ->weekdays()
    ->timezone('America/New_York')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->at('17:40'); // after your chain snapshot finishes

Schedule::command('vol:compute SPY QQQ IWM')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('17:05');

// 5) Seasonality data
Schedule::command('seasonality:5d SPY QQQ IWM MSFT AAPL')
    ->weekdays()
    ->timezone('America/New_York')
    ->withoutOverlapping(90)
    ->onOneServer()
    ->at('17:35');

Schedule::command('watchlist:preload')
    ->weekdays()
    ->timezone('America/New_York')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->at('16:15');

Schedule::command('preload:hot-options --limit=200 --days=10')
    ->weekdays()
    ->timezone('America/New_York')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->at('17:20');

Schedule::command('intraday:warmup --limit=200')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:15');

Schedule::command('intraday:prune-counters --days=7')
    ->timezone('America/New_York')
    ->dailyAt('03:00');

Schedule::command('intraday:prune-option-volumes --hours=24 --batch=100000 --sleep-ms=25')
    ->timezone('America/New_York')
    ->dailyAt('03:00')
    ->withoutOverlapping(20)
    ->onOneServer();

Schedule::command('calculator:prune-snapshots --hours=168')
    ->timezone('America/New_York')
    ->dailyAt('03:10')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('walls:prune-snapshots --days=120')
    ->timezone('America/New_York')
    ->dailyAt('03:20')
    ->withoutOverlapping(20)
    ->onOneServer();

Schedule::command('hot-options:prune --days=120')
    ->timezone('America/New_York')
    ->dailyAt('03:25')
    ->withoutOverlapping(20)
    ->onOneServer();

Schedule::command('walls:compute --timeframe=all --limit=400 --source=hot')
    ->weekdays()
    ->timezone('America/New_York')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->between('09:35', '15:55');

// Intraday polygon pull
Schedule::call(function () {
    $nowEt = now('America/New_York');
    if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        return;
    }

    $symbols = DB::table('watchlists')
        ->pluck('symbol')
        ->map(fn ($s) => Symbols::canon($s))
        ->filter()
        ->unique()
        ->values()
        ->all();

    if (!$symbols) {
        $symbols = ['SPY','QQQ','IWM','AAPL','MSFT','NVDA','TSLA','AMZN'];
    }

    foreach (array_chunk($symbols, 15) as $chunk) {
        // Split heavy symbols (SPY, QQQ) to their own queue so they don't block others
        $heavy = array_filter($chunk, fn($s) => in_array($s, ['SPY','QQQ'], true));
        $rest  = array_diff($chunk, $heavy);

        if (!empty($heavy)) {
            FetchPolygonIntradayOptionsJob::dispatch(array_values($heavy))->onQueue('intraday-heavy');
        }
        if (!empty($rest)) {
            FetchPolygonIntradayOptionsJob::dispatch(array_values($rest))->onQueue('intraday');
        }
    }
})
->everyFiveMinutes()
->weekdays()
->between('09:35', '15:55')
->name('intraday:polygon:pull')
->withoutOverlapping(2)
->onOneServer()
->timezone('America/New_York');

Schedule::command('prices:refresh --source=both --limit=400')
    ->everyFiveMinutes()
    ->timezone('America/New_York')
    ->between('09:35', '15:55')
    ->withoutOverlapping(2)
    ->onOneServer()
    ->name('prices:refresh:intraday');

Schedule::command('hot-options:fetch --limit=200 --type=STOCKS')
    ->weekdays()
    ->timezone('America/New_York')
    ->onOneServer()
    ->dailyAt('17:00');

Schedule::call(function () {
    $nowEt = now('America/New_York');

    // guard trading hours + weekdays
    if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        return;
    }

    $maxSymbols   = 75;                // cap per run
    $freshCutoff  = $nowEt->copy()->subMinutes(10); // skip if primed in last 10m

    $symbols = DB::table('watchlists')
        ->pluck('symbol')
        ->map(fn ($s) => Symbols::canon($s))
        ->filter()
        ->unique()
        ->filter(function ($sym) use ($freshCutoff) {
            $cached = Cache::get("calculator:primed:{$sym}");
            return !$cached || Carbon::parse($cached)->lt($freshCutoff);
        })
        ->take($maxSymbols)
        ->values()
        ->all();

    if (!$symbols) {
        $symbols = ['SPY','QQQ','IWM']; // minimal fallback
    }

    foreach (array_chunk($symbols, 15) as $chunk) {
        foreach ($chunk as $sym) {
            Cache::put("calculator:primed:{$sym}", $nowEt->toIso8601String(), $nowEt->copy()->addMinutes(15));
            FetchCalculatorChainJob::dispatch($sym)->onQueue('calculator');
        }
    }
})
->name('calculator:prime:watchlist')
->timezone('America/New_York')
->weekdays()
->everyFiveMinutes()
->between('09:35', '15:55')
->withoutOverlapping(10)
->onOneServer();

Schedule::command('emails:lifecycle-run')
    ->timezone('America/New_York')
    ->everyThirtyMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();
