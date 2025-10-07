<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class PricesDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols = []) {}

    public function handle(): void
    {
        $apiKey = env('FINNHUB_API_KEY');
        $date   = $this->tradingDate(now());

        $from = strtotime($date . ' 00:00:00 America/New_York');
        $to   = strtotime($date . ' 23:59:59 America/New_York');

        foreach ($this->symbols as $symbol) {
            $symbol = \App\Support\Symbols::canon($symbol);
            try {
                $resp = Http::retry(3, 300, throw: false)->get(
                    'https://finnhub.io/api/v1/stock/candle',
                    ['symbol'=>$symbol,'resolution'=>'D','from'=>$from,'to'=>$to,'token'=>$apiKey]
                );

                $needYahoo = false;

                if ($resp->failed()) {
                    \Log::warning("PricesDailyJob HTTP fail {$symbol} {$date}: ".$resp->status());
                    if (in_array($resp->status(), [401,403,429])) $needYahoo = true;
                } else {
                    $j = $resp->json();
                    if (($j['s'] ?? '') === 'ok' && !empty($j['t'])) {
                        $i = 0;
                        $o = (float)($j['o'][$i] ?? null);
                        $h = (float)($j['h'][$i] ?? null);
                        $l = (float)($j['l'][$i] ?? null);
                        $c = (float)($j['c'][$i] ?? null);

                        if ($c > 0) {
                            DB::table('prices_daily')->updateOrInsert(
                                ['symbol'=>$symbol, 'trade_date'=>$date],
                                ['open'=>$o ?: null,'high'=>$h ?: null,'low'=>$l ?: null,'close'=>$c,
                                'updated_at'=>now(),'created_at'=>now()]
                            );
                            continue; // done with this symbol
                        }
                        // candle present but bad -> try Yahoo
                        $needYahoo = true;
                    } else {
                        \Log::info("No EOD candle {$symbol} {$date} from Finnhub; trying Yahoo.");
                        $needYahoo = true;
                    }
                }

                if ($needYahoo) {
                    $ok = $this->fetchYahooDay($symbol, $date);
                    if (!$ok) \Log::warning("Yahoo fallback also failed for {$symbol} {$date}");
                }

            } catch (\Throwable $e) {
                \Log::error("PricesDailyJob error {$symbol} {$date}: ".$e->getMessage());
                // try Yahoo as last resort
                $this->fetchYahooDay($symbol, $date);
            }
        }
    }

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function upsertYahooDaily(string $symbol, array $timestamps, array $quote, string $expectDate): bool
    {
        $o = Arr::get($quote, 'open', []);
        $h = Arr::get($quote, 'high', []);
        $l = Arr::get($quote, 'low',  []);
        $c = Arr::get($quote, 'close',[]);
        $wrote = false;

        foreach ($timestamps as $i => $ts) {
            $date  = Carbon::createFromTimestamp($ts, 'America/New_York')->toDateString();
            if ($date !== $expectDate) continue; // only today’s EOD
            $open  = isset($o[$i]) ? (float)$o[$i] : null;
            $high  = isset($h[$i]) ? (float)$h[$i] : null;
            $low   = isset($l[$i]) ? (float)$l[$i] : null;
            $close = isset($c[$i]) ? (float)$c[$i] : null;
            if ($close > 0) {
                DB::table('prices_daily')->updateOrInsert(
                    ['symbol'=>$symbol, 'trade_date'=>$date],
                    ['open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,
                    'updated_at'=>now(),'created_at'=>now()]
                );
                $wrote = true;
            }
        }
        return $wrote;
    }

    protected function fetchYahooDay(string $symbol, string $date): bool
    {
        $period1 = strtotime($date.' 00:00:00 America/New_York');
        $period2 = strtotime($date.' 23:59:59 America/New_York');

        $y = Http::retry(2, 250)->get(
            "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}",
            ['period1'=>$period1, 'period2'=>$period2, 'interval'=>'1d']
        );

        if (!$y->ok()) {
            \Log::warning("Yahoo EOD fetch failed {$symbol} {$date}: {$y->status()} body=".$y->body());
            return false;
        }
        $root = $y->json();
        $res  = $root['chart']['result'][0] ?? null;
        $t    = $res['timestamp'] ?? [];
        $q    = $res['indicators']['quote'][0] ?? [];

        if (!is_array($t) || empty($t) || empty($q)) return false;
        return $this->upsertYahooDaily($symbol, $t, $q, $date);
    }

}
