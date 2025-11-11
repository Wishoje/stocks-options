<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

// Downstream jobs
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\Seasonality5DJob;
use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputeUAJob;

class PreloadSingleSymbolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public string $symbol;

    // Tunables (can be overridden via the command/options)
    public int $backfillDays;
    public int $chainStrikesDays;
    public int $seasonalityWindow;
    public int $seasonalityStep;
    public int $expiryLookaheadWeeks;

    /**
     * @param string $symbol Raw or canonical ticker (we'll canon it)
     */
    public function __construct(
        string $symbol,
        int $backfillDays = 400,
        int $chainStrikesDays = 90,
        int $seasonalityWindow = 15,
        int $seasonalityStep = 2,
        int $expiryLookaheadWeeks = 3
    ) {
        $this->symbol = \App\Support\Symbols::canon($symbol);
        $this->backfillDays = $backfillDays;
        $this->chainStrikesDays = $chainStrikesDays;
        $this->seasonalityWindow = $seasonalityWindow;
        $this->seasonalityStep = $seasonalityStep;
        $this->expiryLookaheadWeeks = $expiryLookaheadWeeks;

        // Put the wrapper job on a reasonable queue; chained jobs can override their own queues.
    }

    public function handle(): void
    {
        if (!$this->symbol) {
            Log::warning('PreloadSingleSymbolJob: empty/invalid symbol, skipping.');
            return;
        }

        $s = [$this->symbol]; // All downstream jobs expect arrays

        Log::info('PreloadSingleSymbolJob starting chain', [
            'symbol' => $this->symbol,
            'backfillDays' => $this->backfillDays,
            'chainStrikesDays' => $this->chainStrikesDays,
            'seasonalityWindow' => $this->seasonalityWindow,
            'seasonalityStep' => $this->seasonalityStep,
            'expiryLookaheadWeeks' => $this->expiryLookaheadWeeks,
        ]);

        // Strict ordering so compute never runs before fetch completes
        Bus::chain([
            new PricesBackfillJob($s, $this->backfillDays),
            new PricesDailyJob($s),
            new FetchOptionChainDataJob($s, $this->chainStrikesDays),
            new ComputeVolMetricsJob($s),
            new Seasonality5DJob($s, $this->seasonalityWindow, $this->seasonalityStep),
            new ComputeExpiryPressureJob($s, $this->expiryLookaheadWeeks),
            new ComputeUAJob($s),
        ])
        ->dispatch();

        Log::info('PreloadSingleSymbolJob chain dispatched', ['symbol' => $this->symbol]);
    }

    /**
     * Appears in Horizon UI lists.
     */
    public function displayName(): string
    {
        return 'PreloadSingleSymbolJob [' . ($this->symbol ?: 'N/A') . ']';
    }
}
