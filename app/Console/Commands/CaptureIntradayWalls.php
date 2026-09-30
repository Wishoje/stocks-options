<?php

namespace App\Console\Commands;

use App\Services\IntradayWallTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CaptureIntradayWalls extends Command
{
    protected $signature = 'walls:capture-intraday';

    protected $description = 'Record bounded wall observations from existing stored market inputs';

    public function handle(IntradayWallTracker $tracker): int
    {
        if (! config('wall_tracking.enabled')) {
            $this->info('Intraday wall capture is disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach (config('wall_tracking.symbols') as $symbol) {
            try {
                $result = $tracker->capture($symbol, CarbonImmutable::now('UTC'));
                $this->line($symbol.': '.$result['status']);
            } catch (\Throwable $error) {
                report($error);
                $this->error($symbol.': capture failed; see the application log.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
