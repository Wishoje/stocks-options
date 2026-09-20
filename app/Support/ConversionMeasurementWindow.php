<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ConversionMeasurementWindow
{
    public static function startedAt(): ?CarbonImmutable
    {
        $value = config('conversion_measurement.cohort_started_at');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function includes(CarbonInterface $occurredAt): bool
    {
        $startedAt = self::startedAt();

        return $startedAt !== null && $occurredAt->greaterThanOrEqualTo($startedAt);
    }
}
