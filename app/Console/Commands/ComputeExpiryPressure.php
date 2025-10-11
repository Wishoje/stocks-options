<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ComputeExpiryPressureJob;

class ComputeExpiryPressure extends Command
{
    protected $signature = 'expiry:compute {--symbols=*} {--days=3}';
    protected $description = 'Compute pin risk / max pain for near expiries';

    public function handle(): int {
        $symbols = $this->option('symbols') ?: ['SPY','QQQ','IWM'];
        $days = (int)$this->option('days');
        ComputeExpiryPressureJob::dispatchSync($symbols, $days);
        $this->info("Computed expiry pressure for: ".implode(', ', $symbols)." (days={$days})");
        return self::SUCCESS;
    }
}