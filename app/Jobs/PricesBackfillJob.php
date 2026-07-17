<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PricesBackfillJob extends QueueJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $symbols,
        public int $days = 90,
        public ?string $endDate = null
    ) {
        $this->endDate = $endDate
            ? substr($endDate, 0, 10)
            : Carbon::now('America/New_York')->toDateString();
    }

    public function handle(): void
    {
        $apiKey = env('FINNHUB_API_KEY');
        $end = Carbon::parse((string) $this->endDate, 'America/New_York');
        $to = $end->copy()->endOfDay()->timestamp;
        $from = $end->copy()->subDays($this->days)->startOfDay()->timestamp;
        $failed = 0;

        foreach ($this->symbols as $rawSymbol) {
            $symbol = \App\Support\Symbols::canon($rawSymbol);
            if (! $symbol) {
                continue;
            }

            try {
                // Try Finnhub first to preserve the existing preferred source.
                $response = Http::retry(3, 300, throw: false)
                    ->connectTimeout(3)
                    ->timeout(10)
                    ->get(
                        'https://finnhub.io/api/v1/stock/candle',
                        [
                            'symbol' => $symbol,
                            'resolution' => 'D',
                            'from' => $from,
                            'to' => $to,
                            'token' => $apiKey,
                        ]
                    );

                $useYahoo = false;
                if ($response->failed()) {
                    Log::warning('PricesBackfillJob.finnhubFailed', [
                        'symbol' => $symbol,
                        'status' => $response->status(),
                    ]);
                    // Transient 5xx responses are eligible for the same safe
                    // provider fallback as auth and rate-limit responses.
                    $useYahoo = true;
                } else {
                    $json = $response->json(); // { s, t[], o[], h[], l[], c[] }
                    if (($json['s'] ?? '') === 'ok' && ! empty($json['t'])) {
                        $rows = [];
                        $now = now();

                        foreach ($json['t'] as $index => $timestamp) {
                            $close = (float) ($json['c'][$index] ?? 0);
                            if ($close <= 0) {
                                continue;
                            }

                            $rows[] = [
                                'symbol' => $symbol,
                                'trade_date' => Carbon::createFromTimestamp($timestamp, 'America/New_York')->toDateString(),
                                'open' => ((float) ($json['o'][$index] ?? 0)) ?: null,
                                'high' => ((float) ($json['h'][$index] ?? 0)) ?: null,
                                'low' => ((float) ($json['l'][$index] ?? 0)) ?: null,
                                'close' => $close,
                                'updated_at' => $now,
                                'created_at' => $now,
                            ];
                        }

                        if ($rows !== []) {
                            $this->upsertDailyRows($rows);
                        } else {
                            $useYahoo = true;
                        }
                    } else {
                        $useYahoo = true;
                    }
                }

                // Yahoo is required when Finnhub fails or for a range where
                // Finnhub may not provide all requested history.
                if (($useYahoo || $this->days > 185) && ! $this->fetchYahooRange($symbol, $from, $to)) {
                    $failed++;
                    Log::warning('PricesBackfillJob.yahooFallbackFailed', [
                        'symbol' => $symbol,
                        'days' => $this->days,
                    ]);
                }
            } catch (\Throwable $exception) {
                $failed++;
                Log::warning('PricesBackfillJob.providerException', [
                    'symbol' => $symbol,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($failed > 0) {
            throw new RuntimeException("Price backfill incomplete for {$failed} symbol(s).");
        }
    }

    protected function upsertYahooDaily(string $symbol, array $timestamps, array $quote): int
    {
        $opens = Arr::get($quote, 'open', []);
        $highs = Arr::get($quote, 'high', []);
        $lows = Arr::get($quote, 'low', []);
        $closes = Arr::get($quote, 'close', []);
        $rows = [];
        $now = now();

        foreach ($timestamps as $index => $timestamp) {
            $close = isset($closes[$index]) ? (float) $closes[$index] : null;
            if ($close === null || $close <= 0) {
                continue;
            }

            $rows[] = [
                'symbol' => $symbol,
                'trade_date' => Carbon::createFromTimestamp($timestamp, 'America/New_York')->toDateString(),
                'open' => isset($opens[$index]) ? (float) $opens[$index] : null,
                'high' => isset($highs[$index]) ? (float) $highs[$index] : null,
                'low' => isset($lows[$index]) ? (float) $lows[$index] : null,
                'close' => $close,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            $this->upsertDailyRows($rows);
        }

        return count($rows);
    }

    protected function fetchYahooRange(
        string $symbol,
        int $period1,
        int $period2,
        string $interval = '1d'
    ): bool {
        $response = Http::retry(2, 250, throw: false)
            ->connectTimeout(3)
            ->timeout(10)
            ->get(
                "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}",
                ['period1' => $period1, 'period2' => $period2, 'interval' => $interval]
            );

        if (! $response->ok()) {
            Log::warning('PricesBackfillJob.yahooFailed', [
                'symbol' => $symbol,
                'status' => $response->status(),
            ]);

            return false;
        }

        $result = $response->json('chart.result.0');
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        if (! is_array($timestamps) || $timestamps === [] || ! is_array($quote) || $quote === []) {
            return false;
        }

        return $this->upsertYahooDaily($symbol, $timestamps, $quote) > 0;
    }

    /** @param array<int,array<string,mixed>> $rows */
    protected function upsertDailyRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('prices_daily')->upsert(
                $chunk,
                ['symbol', 'trade_date'],
                ['open', 'high', 'low', 'close', 'updated_at', 'created_at']
            );
        }
    }
}
