<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Support\Market;
use App\Support\Symbols;

// Example default command
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
});

// 1) Kick off the ingest after market close (weekdays, ET)
Schedule::job(new FetchOptionChainDataJob(['SPY','QQQ','IWM','DIA']))
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:10');

// 2) Build the daily snapshot a bit later
Schedule::command('chain:snapshot')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:30');

// 3) Compute vol metrics nightly
Schedule::job(new ComputeVolMetricsJob(['SPY','QQQ','IWM']))
    ->timezone('America/New_York')
    ->dailyAt('20:40');

// 4) Seed prices then compute VRP/term after close (order matters)
Schedule::command('prices:seed SPY QQQ IWM')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:15');

Schedule::command('walls:compute --timeframe=all --limit=400 --source=hot')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:35'); // after your chain snapshot finishes

Schedule::command('vol:compute SPY QQQ IWM')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:20');

// 5) Seasonality data
Schedule::command('seasonality:5d SPY QQQ IWM MSFT AAPL')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:15');

Schedule::command('watchlist:preload')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:15');

Schedule::command('preload:hot-options --limit=200 --days=10')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('17:00');

Schedule::command('intraday:warmup --limit=200 --days=5')
    ->weekdays()
    ->timezone('America/New_York')
    ->everyFifteenMinutes()
    ->between('09:35', '15:55');

Schedule::command('intraday:prune-counters --days=7')
    ->dailyAt('03:00');

// Intraday polygon pull
Schedule::call(function () {
    $nowEt = now('America/New_York');

    if ($nowEt->isWeekend()) {
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

    dispatch(new FetchPolygonIntradayOptionsJob($symbols))->onQueue('intraday');
})
->everyFiveMinutes()
->name('intraday:polygon:pull')
->withoutOverlapping(4)
->timezone('America/New_York');

Schedule::command('hot-options:fetch --limit=200 --type=STOCKS')
    ->weekdays()
    ->timezone('America/New_York')
    ->dailyAt('17:30');
