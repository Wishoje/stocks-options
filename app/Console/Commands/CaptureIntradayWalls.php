<?php

namespace App\Console\Commands;

use App\Services\IntradayWallTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CaptureIntradayWalls extends Command
{
    protected $signature = 'walls:capture-intraday {--symbol=* : Capture only these symbols} {--timeframe= : Capture only this expiry scope}';

    protected $description = 'Record bounded wall observations from existing stored market inputs';

    public function handle(IntradayWallTracker $tracker): int
    {
        if (! config('wall_tracking.enabled')) {
            $this->info('Intraday wall capture is disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        $requestedScope = $this->option('timeframe');
        if ($requestedScope && ! in_array($requestedScope, IntradayWallTracker::TIMEFRAMES, true)) {
            $this->error('Invalid expiry scope.');

            return self::FAILURE;
        }
        $explicit = $this->option('symbol');
        $symbols = $explicit ?: $tracker->symbols(CarbonImmutable::now('UTC'));
        $cursor = $explicit ? null : Cache::get('walls:capture-cursor');
        if ($cursor) {
            $symbols = array_merge(array_values(array_filter($symbols, fn ($s) => strcmp($s, $cursor) > 0)),
                array_values(array_filter($symbols, fn ($s) => strcmp($s, $cursor) <= 0)));
        }
        $started = microtime(true);
        $last = null;
        foreach ($symbols as $symbol) {
            if ($last !== null && microtime(true) - $started >= config('wall_tracking.capture_budget_seconds', 90)) {
                if (! $explicit) {
                    Cache::put('walls:capture-cursor', $last, now()->addDay());
                }
                $this->info('Capture budget reached; remaining symbols continue on the next scheduled run.');

                return $failed ? self::FAILURE : self::SUCCESS;
            }
            try {
                foreach ($requestedScope ? [$requestedScope] : $tracker->requestedTimeframes($symbol) as $scope) {
                    $result = $tracker->capture($symbol, CarbonImmutable::now('UTC'), $scope);
                    $this->line($symbol.' '.$scope.': '.$result['status']);
                }
            } catch (\Throwable $error) {
                report($error);
                $this->error($symbol.': capture failed; see the application log.');
                $failed = true;
            }
            $last = $symbol;
        }
        if (! $explicit) {
            Cache::forget('walls:capture-cursor');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
