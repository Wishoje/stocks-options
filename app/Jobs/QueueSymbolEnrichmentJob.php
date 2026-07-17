<?php

namespace App\Jobs;

use App\Support\Symbols;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class QueueSymbolEnrichmentJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public string $symbol, public ?string $source = null)
    {
    }

    public function handle(): void
    {
        $symbol = Symbols::canon($this->symbol);
        if (!$symbol) {
            return;
        }

        $lockKey = "symbol-enrichment:{$symbol}";
        $dispatchLock = Cache::lock($lockKey, 600);
        if (! $dispatchLock->get()) {
            return;
        }

        try {
            Bus::dispatch((new PrimeSymbolJob($symbol))->onQueue(PrimeSymbolJob::QUEUE));
        } catch (\Throwable $exception) {
            $dispatchLock->release();
            throw $exception;
        }
    }
}
