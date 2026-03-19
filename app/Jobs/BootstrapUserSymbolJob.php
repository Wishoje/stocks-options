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

class BootstrapUserSymbolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'bootstrap';

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
        if (!Cache::add($lockKey, $source ?? 'unknown', now()->addSeconds($ttlSeconds))) {
            return false;
        }

        self::dispatch($sym, $source)->onQueue(self::QUEUE);

        return true;
    }

    public function handle(): void
    {
        $symbol = Symbols::canon($this->symbol);
        if (!$symbol) {
            return;
        }

        $chainKey = "symbol-bootstrap:chain:{$symbol}";
        if (!Cache::add($chainKey, $this->source ?? 'unknown', now()->addMinutes(3))) {
            return;
        }

        $tradeDate = $this->tradeDate(now('America/New_York'));

        Bus::chain([
            (new PricesDailyJob([$symbol]))->onQueue(self::QUEUE),
            (new FetchOptionChainDataJob([$symbol], 90))->onQueue(self::QUEUE),
            (new ComputeExpiryPressureJob([$symbol], 3, $tradeDate))->onQueue(self::QUEUE),
            (new ComputePositioningJob([$symbol], $tradeDate))->onQueue(self::QUEUE),
            (new FetchPolygonIntradayOptionsJob([$symbol]))->onQueue(self::QUEUE),
            (new QueueSymbolEnrichmentJob($symbol, $this->source))->onQueue(self::QUEUE),
        ])->dispatch();
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
