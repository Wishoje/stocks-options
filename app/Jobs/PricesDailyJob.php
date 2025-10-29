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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    protected static function polygonDaily(string $symbol, string $date): ?array
    {
        $apiKey = env('POLYGON_API_KEY');
        if (!$apiKey) return null;

        $url = "https://api.polygon.io/v2/aggs/ticker/{$symbol}/range/1/day/{$date}/{$date}";
        $resp = Http::retry(2, 200, throw:false)
            ->timeout(15)->connectTimeout(10)
            ->get($url, ['adjusted' => true, 'sort' => 'asc', 'apiKey' => $apiKey]);

        if ($resp->failed()) {
            Log::warning("Polygon daily fail {$symbol} {$date}: {$resp->status()} ".$resp->body());
            return null;
        }

        $j = $resp->json();
        if (($j['resultsCount'] ?? 0) < 1 || empty($j['results'][0])) {
            Log::info("Polygon daily empty {$symbol} {$date}: status=".( $j['status'] ?? 'n/a' ));
            return null;
        }

        $r = $j['results'][0];
        return [
            'date'   => $date,
            'open'   => $r['o'] ?? null,
            'high'   => $r['h'] ?? null,
            'low'    => $r['l'] ?? null,
            'close'  => $r['c'] ?? null,
            'volume' => $r['v'] ?? null,
        ];
    }
}