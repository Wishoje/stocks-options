<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class CalculatorUnderlyingResolver
{
    public const STATUS_LIVE = 'live';

    public const STATUS_STALE = 'stale';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const SESSION_REGULAR = 'regular';

    public const SESSION_EXTENDED = 'extended';

    public const SESSION_CLOSED = 'closed';

    /**
     * Resolve the calculator's underlying price without fabricating a fallback.
     *
     * @return array{
     *     symbol:string,
     *     price:float|null,
     *     status:string,
     *     usable_for_calculation:bool,
     *     source:string|null,
     *     asof:string|null,
     *     age_seconds:int|null,
     *     session:string,
     *     live_max_age_seconds:int,
     *     stale_usable_max_age_seconds:int,
     *     reason:string|null
     * }
     */
    public function resolve(string $symbol, ?DateTimeInterface $at = null): array
    {
        $symbol = Symbols::canon($symbol);
        $now = $at
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');
        $session = $this->session($now);
        [$liveMaxAge, $usableMaxAge] = $this->freshnessFor($session);
        $base = [
            'symbol' => $symbol,
            'price' => null,
            'status' => self::STATUS_UNAVAILABLE,
            'usable_for_calculation' => false,
            'source' => null,
            'asof' => null,
            'age_seconds' => null,
            'session' => $session,
            'live_max_age_seconds' => $liveMaxAge,
            'stale_usable_max_age_seconds' => $usableMaxAge,
            'reason' => null,
        ];

        if ($symbol === '') {
            return array_replace($base, ['reason' => 'invalid_symbol']);
        }

        $quote = DB::table('underlying_quotes')
            ->where('symbol', $symbol)
            ->first(['last_price', 'source', 'asof']);

        if (! $quote) {
            return array_replace($base, ['reason' => 'missing_quote']);
        }

        $source = trim((string) ($quote->source ?? ''));
        $base['source'] = $source !== '' ? $source : null;
        if (! $this->hasVerifiableSource($source)) {
            return array_replace($base, ['reason' => 'unverifiable_source']);
        }

        $price = $this->positivePrice($quote->last_price ?? null);
        if ($price === null) {
            return array_replace($base, ['reason' => 'invalid_price']);
        }

        $asofValue = trim((string) ($quote->asof ?? ''));
        if ($asofValue === '') {
            return array_replace($base, ['reason' => 'invalid_asof']);
        }

        try {
            $asof = CarbonImmutable::parse($asofValue, 'UTC')->utc();
        } catch (\Throwable) {
            return array_replace($base, ['reason' => 'invalid_asof']);
        }

        $base['asof'] = $asof->toIso8601String();
        $ageSeconds = $now->getTimestamp() - $asof->getTimestamp();
        $futureTolerance = max(0, (int) config('calculator_underlying.future_tolerance_seconds', 30));
        if ($ageSeconds < -$futureTolerance) {
            return array_replace($base, ['reason' => 'future_asof']);
        }

        $ageSeconds = max(0, $ageSeconds);
        $base['age_seconds'] = $ageSeconds;
        if ($ageSeconds <= $liveMaxAge) {
            return array_replace($base, [
                'price' => $price,
                'status' => self::STATUS_LIVE,
                'usable_for_calculation' => true,
            ]);
        }

        $allowStale = (bool) config('calculator_underlying.allow_stale_for_calculation', true);
        $usable = $allowStale && $ageSeconds <= $usableMaxAge;

        return array_replace($base, [
            'price' => $usable ? $price : null,
            'status' => self::STATUS_STALE,
            'usable_for_calculation' => $usable,
            'reason' => match (true) {
                ! $allowStale => 'stale_not_allowed',
                ! $usable => 'stale_too_old',
                default => 'outside_live_window',
            },
        ]);
    }

    private function hasVerifiableSource(string $source): bool
    {
        return $source !== '' && ! str_ends_with(strtolower($source), ':ingested-at');
    }

    private function positivePrice(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $price = (float) $value;

        return is_finite($price) && $price > 0 ? $price : null;
    }

    private function session(CarbonImmutable $nowUtc): string
    {
        $timezone = (string) config('calculator_underlying.timezone', 'America/New_York');
        $local = $nowUtc->setTimezone($timezone);
        if (Market::isRthOpen($local->toMutable())) {
            return self::SESSION_REGULAR;
        }

        if ($local->isWeekday()) {
            $time = $local->format('H:i');
            $start = (string) config('calculator_underlying.extended_hours.start', '04:00');
            $end = (string) config('calculator_underlying.extended_hours.end', '20:00');
            $isPreMarket = $time >= $start && $time < '09:30';
            $isPostMarket = $time > '16:00' && $time <= $end;

            if ($isPreMarket || $isPostMarket) {
                return self::SESSION_EXTENDED;
            }
        }

        return self::SESSION_CLOSED;
    }

    /** @return array{int, int} */
    private function freshnessFor(string $session): array
    {
        $live = max(0, (int) config("calculator_underlying.freshness_seconds.{$session}.live", 0));
        $usable = max(
            $live,
            (int) config("calculator_underlying.freshness_seconds.{$session}.usable", $live)
        );

        return [$live, $usable];
    }
}
