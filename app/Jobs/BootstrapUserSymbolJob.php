<?php

namespace App\Jobs;

use App\Support\Symbols;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class BootstrapUserSymbolJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'bootstrap';

    public int $timeout = 60;

    public function __construct(public string $symbol, public ?string $source = null)
    {
        $this->onQueue(self::QUEUE);
    }

    public static function dispatchIfNeeded(string $symbol, ?string $source = null, int $ttlSeconds = 120): bool
    {
        $sym = Symbols::canon($symbol);
        if (!$sym) {
            return false;
        }

        $lockKey = "symbol-bootstrap:dispatch:{$sym}";
        $dispatchLock = Cache::lock($lockKey, $ttlSeconds);
        if (! $dispatchLock->get()) {
            return false;
        }

        try {
            Bus::dispatch((new self($sym, $source))->onQueue(self::QUEUE));
        } catch (\Throwable $exception) {
            $dispatchLock->release();
            throw $exception;
        }

        return true;
    }

    public function handle(): void
    {
        $symbol = Symbols::canon($this->symbol);
        if (!$symbol) {
            return;
        }

        $chainKey = "symbol-bootstrap:chain:{$symbol}";
        $chainLock = Cache::lock($chainKey, 180);
        if (! $chainLock->get()) {
            return;
        }

        $tradeDate = $this->tradeDate(now('America/New_York'));

        try {
            Bus::chain([
                (new PricesDailyJob([$symbol]))->withJobTimeout(270)->onQueue(self::QUEUE),
                (new FetchOptionChainDataJob([$symbol], 90, null, 270))->onQueue(self::QUEUE),
                (new ComputeExpiryPressureJob([$symbol], 3, $tradeDate))->withJobTimeout(270)->onQueue(self::QUEUE),
                (new ComputePositioningJob([$symbol], $tradeDate))->withJobTimeout(270)->onQueue(self::QUEUE),
                (new FetchPolygonIntradayOptionsJob([$symbol], 270))->onQueue(self::QUEUE),
                (new QueueSymbolEnrichmentJob($symbol, $this->source))->onQueue(self::QUEUE),
            ])->dispatch();
        } catch (\Throwable $exception) {
            $chainLock->release();
            throw $exception;
        }
    }

    private function tradeDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
            return $ny->toDateString();
        }

        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }
}
