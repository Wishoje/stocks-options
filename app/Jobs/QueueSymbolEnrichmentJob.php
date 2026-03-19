<?php

namespace App\Jobs;

use App\Support\Symbols;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class QueueSymbolEnrichmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        if (!Cache::add($lockKey, $this->source ?? 'bootstrap', now()->addMinutes(10))) {
            return;
        }

        PrimeSymbolJob::dispatch($symbol)->onQueue(PrimeSymbolJob::QUEUE);
    }
}
