<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;

// 1) Ingest options (nearest expiries) after the close on weekdays
Schedule::call(function () {
        FetchOptionChainDataJob::dispatch(['SPY','QQQ'], 90)->onQueue('ingest');
    })
    ->name('options-ingest-postclose')     // <-- required by withoutOverlapping
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:05')
    ->withoutOverlapping();

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

Schedule::command('vol:compute SPY QQQ IWM')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:20');

// 5) Seasonality data (pre-market)
Schedule::command('seasonality:5d SPY QQQ IWM MSFT AAPL')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('06:10');

// 6) Watchlist preload
Schedule::command('watchlist:preload')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('06:30');
