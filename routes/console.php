<?php

use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\BuildDailyChainSnapshot;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;

// Route-based scheduling for Laravel 11

// 1) Kick off the ingest after market close (weekdays, ET)
Schedule::job(new FetchOptionChainDataJob(['SPY','QQQ','IWM','DIA']))
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:10'); // 4:10pm ET

// 2) Build the daily snapshot a bit later
Schedule::command('chain:snapshot')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('16:30'); // 4:30pm ET

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

// 5) Seasonality data
Schedule::command('seasonality:5d SPY QQQ IWM MSFT AAPL')
    ->weekdays()
    ->timezone('America/New_York')
    ->at('06:10');
