<?php

namespace App\Support;

use App\Models\UnderlyingQuote;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class UnderlyingQuoteRecorder
{
    public function record(
        string $symbol,
        mixed $price,
        string $source,
        mixed $asof,
        mixed $previousClose = null,
        ?CarbonInterface $recordedAt = null
    ): bool {
        $symbol = Symbols::canon($symbol);
        $source = trim($source);
        $price = $this->positiveNumber($price);
        $previousClose = $this->positiveNumber($previousClose);
        $asof = $this->normalizeAsof($asof);
        $recordedAt = $recordedAt?->toImmutable()->utc() ?? now('UTC')->toImmutable();

        if ($symbol === '' || $source === '' || $price === null || $asof === null) {
            return false;
        }

        $futureTolerance = max(0, (int) config('calculator_underlying.future_tolerance_seconds', 30));
        if ($asof->isAfter($recordedAt->addSeconds($futureTolerance))) {
            return false;
        }

        return DB::transaction(function () use (
            $symbol,
            $source,
            $price,
            $previousClose,
            $asof
        ): bool {
            $current = UnderlyingQuote::query()
                ->where('symbol', $symbol)
                ->lockForUpdate()
                ->first();
            $currentUsesIngestionTime = str_ends_with(
                strtolower((string) ($current?->source ?? '')),
                ':ingested-at'
            );

            if ($current?->asof && ! $currentUsesIngestionTime && $current->asof->gt($asof)) {
                return false;
            }

            $attributes = [
                'source' => $source,
                'last_price' => $price,
                'asof' => $asof,
            ];
            if ($previousClose !== null) {
                $attributes['prev_close'] = $previousClose;
            }

            ($current ?? new UnderlyingQuote(['symbol' => $symbol]))
                ->fill($attributes)
                ->save();

            return true;
        }, 3);
    }

    private function positiveNumber(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0 ? $number : null;
    }

    private function normalizeAsof(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $raw = (string) $value;
                $number = (int) $raw;
                $seconds = match (true) {
                    strlen($raw) >= 19 => intdiv($number, 1_000_000_000),
                    strlen($raw) >= 16 => intdiv($number, 1_000_000),
                    strlen($raw) >= 13 => intdiv($number, 1_000),
                    default => $number,
                };

                return CarbonImmutable::createFromTimestampUTC($seconds);
            }

            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
