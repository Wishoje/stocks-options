<?php

namespace App\Console\Kernel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 1) Kick off the ingest after market close (weekdays, ET)
        $schedule->job(new \App\Jobs\FetchOptionChainDataJob(['SPY','QQQ','IWM','DIA']))
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:10'); // 4:10pm ET

        // 2) Build the daily snapshot a bit later
        $schedule->command('chain:snapshot')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:30'); // 4:30pm ET

        // 3) Compute vol metrics nightly
        $schedule->job(new \App\Jobs\ComputeVolMetricsJob(['SPY','QQQ','IWM']))
            ->timezone('America/New_York')
            ->dailyAt('20:40');

        // 4) Seed prices then compute VRP/term after close (order matters)
        $schedule->command('prices:seed SPY QQQ IWM')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:15');

        $schedule->command('vol:compute SPY QQQ IWM')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:20');

        // 5) Seasonality data
        $schedule->command('seasonality:5d SPY QQQ IWM MSFT AAPL')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('06:10');

        // Run on weekdays at 06:30 ET (adjust to your pipeline timing)
        $schedule->command('watchlist:preload')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:15');

            
        $schedule->call(function () {
            $symbols = DB::table('watchlists')->distinct()->pluck('symbol')->take(10);
            foreach ($symbols as $sym) {
                dispatch(new \App\Jobs\FetchCalculatorChainJob($sym));
            }
        })->everyFifteenMinutes()->name('Refresh Calculator Chains');

        
        // ---------------------------------------------------------
        // INTRADAY LIVE OPTION FLOW INGEST (NEW)
        // ---------------------------------------------------------
        //
        // Goal:
        // - Populate option_live_counters every 5 minutes during RTH (changed for freshness)
        // - Feeds /api/intraday/summary, /api/intraday/volume-by-strike, /api/intraday/ua
        //
        // Notes:
        // - We only dispatch if it's a weekday AND between 09:30 and ~16:10 ET
        // - We pull symbols from watchlist first; if empty fall back to a core basket
        //

        $schedule->call(function () {
            $nowEt = \Carbon\Carbon::now('America/New_York');

            if ($nowEt->isWeekend()) return;
            $hhmm = $nowEt->format('H:i');
            if ($hhmm < '09:30' || $hhmm > '16:10') return;

            // pull distinct symbols from watchlists
            $symbols = \Illuminate\Support\Facades\DB::table('watchlists')
                ->pluck('symbol')
                ->map(fn($s) => \App\Support\Symbols::canon($s))
                ->filter()
                ->unique()
                ->values()
                ->all();

            // fallback if empty
            if (!$symbols) {
                $symbols = ['SPY','QQQ','IWM','AAPL','MSFT','NVDA','TSLA','AMZN'];
            }

            if (!$symbols) {
                return;
            }

            dispatch(new \App\Jobs\FetchPolygonIntradayOptionsJob($symbols))
                ->onQueue('default');
        })
        ->everyFiveMinutes()
        ->name('intraday:polygon:pull')
        ->withoutOverlapping(4)
        ->timezone('America/New_York');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}