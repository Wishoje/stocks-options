<?php

namespace App\Console\Commands;

use App\Support\CalculatorPrimeScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PrimeCalculatorWatchlist extends Command
{
    protected $signature = 'calculator:prime-watchlist';

    protected $description = 'Queue due full-catalog calculator refreshes for watchlisted symbols.';

    public function handle(CalculatorPrimeScheduler $scheduler): int
    {
        $result = $scheduler->dispatchDue();

        Log::channel('scheduler')->info('calculator.scheduler.run', $result);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['status'] === 'dispatch_failed' ? self::FAILURE : self::SUCCESS;
    }
}
