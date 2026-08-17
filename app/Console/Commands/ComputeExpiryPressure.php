<?php

namespace App\Console\Commands;

use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Support\EodCacheVersion;
use Illuminate\Console\Command;

class ComputeExpiryPressure extends Command
{
    protected $signature = 'expiry:compute {--symbols=*} {--days=3}';

    protected $description = 'Compute pin risk / max pain for near expiries';

    public function handle(): int
    {
        $symbols = $this->option('symbols') ?: ['SPY', 'QQQ', 'IWM'];
        $days = (int) $this->option('days');
        $publication = new PublishEodCacheVersionJob(
            $symbols,
            [EodCacheVersion::DOMAIN_EXPIRY_PRESSURE]
        );
        ComputeExpiryPressureJob::dispatchSync($symbols, $days);
        $publication->handle(app(EodCacheVersion::class));
        $this->info('Computed expiry pressure for: '.implode(', ', $symbols)." (days={$days})");

        return self::SUCCESS;
    }
}
