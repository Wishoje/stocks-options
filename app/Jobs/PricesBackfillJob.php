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

class PricesBackfillJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels,Batchable;

    public function __construct(public array $symbols, public int $days = 90) {}

   public function handle(): void
    {
        $apiKey = env('FINNHUB_API_KEY');
        $to   = Carbon::now('America/New_York')->endOfDay()->timestamp;
        $from = Carbon::now('America/New_York')->subDays($this->days)->startOfDay()->timestamp;

        foreach ($this->symbols as $sym) {
            $symbol = \App\Support\Symbols::canon($sym);

            // Try Finnhub first (keeps consistency if your plan allows it)
            $resp = Http::retry(3, 300, throw: false)->get(
                'https://finnhub.io/api/v1/stock/candle',
                ['symbol'=>$symbol, 'resolution'=>'D', 'from'=>$from, 'to'=>$to, 'token'=>$apiKey]
            );

            $useYahoo = false;
            if ($resp->failed()) {
                \Log::warning("Finnhub candle fail {$symbol}: {$resp->status()} body=".$resp->body());
                if (in_array($resp->status(), [401,403,429])) $useYahoo = true;
            } else {
                $j = $resp->json(); // { s, t[], o[], h[], l[], c[] }
                if (($j['s'] ?? '') === 'ok' && !empty($j['t'])) {
                    foreach ($j['t'] as $i => $ts) {
                        $date = Carbon::createFromTimestamp($ts, 'America/New_York')->toDateString();
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
                        }
                    }
                } else {
                    $useYahoo = true; // Finnhub responded but with no data—fallback
                }
            }

            // Also fallback if user asked for > ~6 months; Yahoo handles long ranges better
            if ($useYahoo || $this->days > 185) {
                $ok = $this->fetchYahooRange($symbol, $from, $to, '1d');
                if (!$ok) {
                    \Log::warning("Yahoo fallback failed {$symbol} [{$this->days}d]");
                }
            }
        }
    }

    protected function upsertYahooDaily(string $symbol, array $timestamps, array $quote): void
    {
        $o = Arr::get($quote, 'open', []);
        $h = Arr::get($quote, 'high', []);
        $l = Arr::get($quote, 'low',  []);
        $c = Arr::get($quote, 'close',[]);
        foreach ($timestamps as $i => $ts) {
            $date  = Carbon::createFromTimestamp($ts, 'America/New_York')->toDateString();
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
            }
        }
    }

    protected function fetchYahooRange(string $symbol, int $period1, int $period2, string $interval = '1d'): bool
    {
        $y = Http::retry(2, 250)->get(
            "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}",
            ['period1'=>$period1, 'period2'=>$period2, 'interval'=>$interval]
        );
        if (!$y->ok()) {
            \Log::warning("Yahoo range fetch failed {$symbol}: {$y->status()} body=".$y->body());
            return false;
        }
        $root = $y->json();
        $res  = $root['chart']['result'][0] ?? null;
        $t    = $res['timestamp'] ?? [];
        $q    = $res['indicators']['quote'][0] ?? [];
        if (!is_array($t) || empty($t) || empty($q)) return false;

        $this->upsertYahooDaily($symbol, $t, $q);
        return true;
    }
}
