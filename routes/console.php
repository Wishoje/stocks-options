<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
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
        FetchPolygonIntradayOptionsJob::dispatch($chunk)->onQueue('intraday');
    }
})
->everyFiveMinutes()
->name('intraday:polygon:pull')
->withoutOverlapping(2)
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

    // extra guard (in case between() ever changes)
    if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        return;
    }

    foreach (['SPY', 'QQQ'] as $sym) {
        FetchCalculatorChainJob::dispatch($sym)->onQueue('calculator');
    }
})
->name('calculator:prime:spy-qqq')
->timezone('America/New_York')
->weekdays()
->everyFiveMinutes()
->between('09:35', '15:55')
->withoutOverlapping(10)
->onOneServer();

