<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use RuntimeException;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\QueueLanes;

class PricesDailyJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols = [], public ?string $targetDate = null)
    {
        $this->targetDate = $targetDate
            ? substr($targetDate, 0, 10)
            : $this->tradingDate(now());
    }

    public function handle(): void
    {
        $limiter = app(ProviderConcurrencyLimiter::class);
        $limiter->withPriority(
            QueueLanes::providerPriority($this->queue),
            fn () => $this->fetchAndPersist(),
            2
        );
    }

    private function fetchAndPersist(): void
    {
        $date = (string) $this->targetDate;
        $failed = 0;

        foreach ($this->symbols as $symbol) {
            $symbol = \App\Support\Symbols::canon($symbol);
            try {
                $bar = \App\Support\Prices::daily($symbol, $date); // Polygon-first, Finnhub denied cached, Yahoo fallback
                if ($bar && ($bar['close'] ?? 0) > 0) {
                    DB::table('prices_daily')->updateOrInsert(
                        ['symbol'=>$symbol, 'trade_date'=>$date],
                        [
                            'open'  => $bar['open']  ?? null,
                            'high'  => $bar['high']  ?? null,
                            'low'   => $bar['low']   ?? null,
                            'close' => $bar['close'],
                            'updated_at'=>now(),
                            'created_at'=>now(),
                        ]
                    );
                } else {
                    \Log::warning("PricesDailyJob: no EOD for {$symbol} {$date}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                \Log::error('PricesDailyJob.error', [
                    'symbol' => $symbol,
                    'date' => $date,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($failed > 0) {
            throw new RuntimeException("Daily price refresh incomplete for {$failed} symbol(s).");
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');

        // If weekend -> previous weekday
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
            return $ny->toDateString();
        }

        // Use last completed session if before ~4:15pm ET
        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }

}
