<?php

namespace App\Jobs;

use App\Exceptions\ProviderConcurrencyUnavailable;
use App\Models\UnderlyingQuote;
use App\Support\Symbols;
use App\Support\Market;
use App\Support\PolygonClient;
use App\Support\QueueLanes;
use App\Support\ProviderConcurrencyLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\CarbonImmutable;
use RuntimeException;

class FetchUnderlyingQuotesJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $timeout = 90;

    public function __construct(public array $symbols)
    {
        // Separate, lightweight queue for prices
        $this->onQueue(QueueLanes::quotes());
    }

    public function handle(): void
    {
        $limiter = app(ProviderConcurrencyLimiter::class);
        $limiter->withPriority(
            QueueLanes::providerPriority($this->queue),
            fn () => $this->fetchAndPersist(),
            1
        );
    }

    private function fetchAndPersist(): void
    {
        // $nowEt = now('America/New_York');
        // if ($nowEt->isWeekend() || !Market::isRthOpen($nowEt)) {
        //     Log::info('FetchUnderlyingQuotesJob.marketClosed', ['ts' => $nowEt->toDateTimeString()]);
        //     return;
        // }

        /** @var PolygonClient $client */
        $client = app(PolygonClient::class);

        $failed = 0;

       foreach ($this->symbols as $raw) {
            $symbol = Symbols::canon($raw);
            if (!$symbol) {
                continue;
            }

            try {
                // Log::debug('FetchUnderlyingQuotesJob.symbol', ['symbol' => $symbol]);

                $quote = $client->underlyingQuote($symbol);

                if (!$quote || empty($quote['last_price']) || $quote['last_price'] <= 0) {
                    Log::warning('FetchUnderlyingQuotesJob.noQuote', ['symbol' => $symbol]);
                    $failed++;
                    continue;
                }

                $asofRaw = $quote['asof'] ?? null;
                $asofUtc = $this->normalizeAsof($asofRaw);

                DB::transaction(function () use ($symbol, $quote, $asofUtc): void {
                    $current = UnderlyingQuote::query()
                        ->where('symbol', $symbol)
                        ->lockForUpdate()
                        ->first();
                    $currentUsesIngestionTime = str_ends_with(
                        (string) ($current?->source ?? ''),
                        ':ingested-at'
                    );

                    // A provider response without source time cannot supersede
                    // an already timestamped quote. Keep the last verifiable row.
                    if ($asofUtc === null && $current?->asof && ! $currentUsesIngestionTime) {
                        return;
                    }

                    if (
                        $asofUtc !== null
                        && $current?->asof
                        && ! $currentUsesIngestionTime
                        && $current->asof->gt($asofUtc)
                    ) {
                        return;
                    }

                    $source = (string) ($quote['source'] ?? 'massive');
                    $effectiveAsof = $asofUtc;
                    if ($effectiveAsof === null) {
                        $effectiveAsof = CarbonImmutable::now('UTC');
                        $source .= ':ingested-at';
                    }

                    ($current ?? new UnderlyingQuote(['symbol' => $symbol]))
                        ->fill([
                            'source' => $source,
                            'last_price' => $quote['last_price'],
                            'prev_close' => $quote['prev_close'] ?? null,
                            'asof' => $effectiveAsof,
                        ])
                        ->save();
                }, 3);
            } catch (ProviderConcurrencyUnavailable $exception) {
                // Capacity pressure must retry the job immediately. Continuing
                // through a batch could spend the whole 90-second job timeout
                // waiting once per symbol without making a provider request.
                throw $exception;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('FetchUnderlyingQuotesJob.error', [
                    'symbol' => $symbol,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($failed > 0) {
            throw new RuntimeException("Quote refresh incomplete for {$failed} symbol(s).");
        }
    }

    /**
     * Normalize various asof formats to a UTC Carbon instance.
     *
     * Massive v2 uses epoch nanoseconds (e.g. 1765328400000000000).
     */
    private function normalizeAsof(mixed $asofRaw): ?CarbonImmutable
    {
        if ($asofRaw === null || $asofRaw === '') {
            return null;
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
