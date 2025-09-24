<?php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\BuildDailyChainSnapshot;
use App\Jobs\FetchOptionChainDataJob;

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
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
