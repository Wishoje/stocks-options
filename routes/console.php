<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\BuildDailyChainSnapshot;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;

class Kernel extends ConsoleKernel
{
    // Not strictly required on recent Laravel (auto-discovers), but fine to keep:
    protected $commands = [
        BuildDailyChainSnapshot::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // 1) Kick off the ingest after market close
        $schedule->job(new FetchOptionChainDataJob(['SPY','QQQ','IWM','DIA']))
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:10'); // 4:10pm ET

        // 2) Build the daily snapshot a bit later
        $schedule->command('chain:snapshot')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:30'); // 4:30pm ET

        $schedule->job(new ComputeVolMetricsJob(['SPY','QQQ','IWM']))->dailyAt('20:40');

        Schedule::command('prices:seed SPY QQQ IWM')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:15');  // 1) seed prices first

        Schedule::command('vol:compute SPY QQQ IWM')
            ->weekdays()
            ->timezone('America/New_York')
            ->at('16:20');  // 2) then compute term + VRP (needs prices)

        $schedule->command('seasonality:5d SPY QQQ IWM MSFT AAPL')->weekdays()->timezone('America/New_York')->at('06:10');

    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
