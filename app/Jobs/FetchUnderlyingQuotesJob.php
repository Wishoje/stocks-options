<?php

namespace App\Jobs;

use App\Models\UnderlyingQuote;
use App\Support\Symbols;
use App\Support\Market;
use App\Support\PolygonClient;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;

class FetchUnderlyingQuotesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols)
    {
        // Separate, lightweight queue for prices
        $this->onQueue('quotes');
    }

    public function handle(): void
    {
        // $nowEt = now('America/New_York');
        // if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        //     Log::info('FetchUnderlyingQuotesJob.marketClosed', ['ts' => $nowEt->toDateTimeString()]);
        //     return;
        // }

        /** @var PolygonClient $client */
        $client = app(PolygonClient::class);

       foreach ($this->symbols as $raw) {
            $symbol = Symbols::canon($raw);
            if (!$symbol) {
                continue;
            }

            try {
                Log::debug('FetchUnderlyingQuotesJob.symbol', ['symbol' => $symbol]);

                $quote = $client->underlyingQuote($symbol);

                if (!$quote || empty($quote['last_price']) || $quote['last_price'] <= 0) {
                    Log::warning('FetchUnderlyingQuotesJob.noQuote', ['symbol' => $symbol]);
                    continue;
                }

                $asofRaw = $quote['asof'] ?? null;
                $asofUtc = $this->normalizeAsof($asofRaw);

                UnderlyingQuote::updateOrCreate(
                    ['symbol' => $symbol],
                    [
                        'source'     => $quote['source']     ?? 'massive',
                        'last_price' => $quote['last_price'],
                        'prev_close' => $quote['prev_close'] ?? null,
                        'asof'       => $asofUtc,
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('FetchUnderlyingQuotesJob.error', [
                    'symbol' => $symbol,
                    'err'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Normalize various asof formats to a UTC Carbon instance.
     *
     * Massive v2 uses epoch nanoseconds (e.g. 1765328400000000000).
     */
    private function normalizeAsof(mixed $asofRaw): CarbonImmutable
    {
        // Default if nothing provided
        if ($asofRaw === null || $asofRaw === '') {
            return CarbonImmutable::now('UTC');
        }

        // Numeric epoch (seconds/ms/us/ns)
        if (is_int($asofRaw) || (is_string($asofRaw) && ctype_digit($asofRaw))) {
            $num = (int) $asofRaw;
            $len = strlen((string) $num);

            // 19 digits: ns, 16: µs, 13: ms, <=10: seconds
            if ($len >= 19) {
                $seconds = intdiv($num, 1_000_000_000); // ns -> s
            } elseif ($len >= 16) {
                $seconds = intdiv($num, 1_000_000);     // µs -> s
            } elseif ($len >= 13) {
                $seconds = intdiv($num, 1_000);         // ms -> s
            } else {
                $seconds = $num;                        // seconds
            }

            return CarbonImmutable::createFromTimestampUTC($seconds);
        }

        // Fallback: treat as string datetime
        return CarbonImmutable::parse((string) $asofRaw, 'UTC');
    }
}
