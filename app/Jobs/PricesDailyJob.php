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

class PricesDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols = []) {}

    public function handle(): void
    {
        $date = $this->tradingDate(now());

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
                }
            } catch (\Throwable $e) {
                \Log::error("PricesDailyJob error {$symbol} {$date}: ".$e->getMessage());
            }
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }
}
