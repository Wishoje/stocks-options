<?php

namespace App\Support;

use App\Models\UnderlyingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class WallTrackingQuoteStore
{
    /** Keep the regular quote feed independent of calculator snapshot writes. */
    public function record(string $symbol, array $quote, CarbonImmutable $asof, CarbonImmutable $received): void
    {
        if (! is_numeric($quote['last_price'] ?? null) || ! is_finite((float) $quote['last_price'])
            || $quote['last_price'] <= 0 || $asof->greaterThan($received)
            || empty($quote['source']) || str_contains($quote['source'], ':ingested-at')) {
            return;
        }
        $key = $this->key($symbol, $received);
        Cache::lock($key.':lock', 10)->get(function () use ($key, $symbol, $quote, $asof, $received): void {
            $previous = Cache::get($key);
            if ($previous && (CarbonImmutable::parse($previous['asof'])->greaterThan($asof)
                || CarbonImmutable::parse($previous['updated_at'])->greaterThan($received))) {
                return;
            }
            Cache::put($key, [
                'symbol' => $symbol, 'source' => $quote['source'], 'last_price' => (float) $quote['last_price'],
                'asof' => $asof->toIso8601String(), 'updated_at' => $received->toIso8601String(),
            ], $received->addDay());
        });
    }

    public function current(string $symbol, CarbonImmutable $now): ?UnderlyingQuote
    {
        $stored = Cache::get($this->key($symbol, $now));
        if ($stored) {
            // This model is an in-memory value; observation persistence remains unchanged.
            return (new UnderlyingQuote)->forceFill($stored);
        }
        $quote = UnderlyingQuote::where('symbol', $symbol)->first();

        // During rollout or cache recovery, wait for the regular feed instead
        // of switching the wall series to the calculator's price convention.
        return $quote?->source === 'massive-v3-snapshot' ? null : $quote;
    }

    private function key(string $symbol, CarbonImmutable $at): string
    {
        return 'walls:quote:v1:'.$symbol.':'.$at->setTimezone('America/New_York')->toDateString();
    }
}
