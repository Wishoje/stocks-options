<?php

namespace App\Console\Commands;

use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeUAJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Support\DailyChainSnapshotPublisher;
use App\Support\EodCacheVersion;
use Illuminate\Console\Command;
use Throwable;

class BuildDailyChainSnapshot extends Command
{
    public const CACHE_PUBLICATION_DOMAINS = [
        EodCacheVersion::DOMAIN_GEX,
        EodCacheVersion::DOMAIN_EXPIRY_PRESSURE,
        EodCacheVersion::DOMAIN_ACTIVITY,
    ];

    protected $signature = 'chain:snapshot {date?}';

    protected $description = 'Aggregate option_chain_data into daily_chain_snapshot';

    public function handle(DailyChainSnapshotPublisher $publisher): int
    {
        $date = (string) ($this->argument('date') ?? now()->toDateString());

        try {
            $publication = $publisher->publish($date);
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $symbols = $publication['symbols'];
        $cachePublication = new PublishEodCacheVersionJob(
            $symbols,
            self::CACHE_PUBLICATION_DOMAINS
        );

        (new ComputePositioningJob($symbols, $publication['date']))->handle();
        (new ComputeExpiryPressureJob($symbols, 3, $publication['date']))->handle();
        (new ComputeUAJob($symbols, anchorDate: $publication['date']))->handle();
        $cachePublication->handle(app(EodCacheVersion::class));

        $this->info(sprintf(
            'Snapshot published for %s (rows: %d, checksum: %s)',
            $publication['date'],
            $publication['row_count'],
            $publication['checksum']
        ));

        return self::SUCCESS;
    }
}
