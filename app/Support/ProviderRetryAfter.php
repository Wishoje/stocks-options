<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Interpret a provider deadline without sleeping or reading the system clock.
 */
final class ProviderRetryAfter
{
    private const MAX_TIMESTAMP = 253402300799; // 9999-12-31 23:59:59 UTC.

    public static function parse(mixed $header, CarbonInterface $receivedAt): ?CarbonImmutable
    {
        if (is_int($header)) {
            $header = (string) $header;
        }
        if (! is_string($header)) {
            return null;
        }
        $header = trim($header, " \t");
        $at = CarbonImmutable::instance($receivedAt)->utc();

        if (preg_match('/^[0-9]+$/D', $header)) {
            $seconds = ltrim($header, '0');
            $seconds = $seconds === '' ? '0' : $seconds;
            $maximum = (string) max(0, self::MAX_TIMESTAMP - $at->getTimestamp());
            if (strlen($seconds) > strlen($maximum)
                || (strlen($seconds) === strlen($maximum) && strcmp($seconds, $maximum) > 0)) {
                // A valid but unrepresentable delay must not silently become
                // the short fallback delay or overflow a durable timestamp.
                throw new InvalidArgumentException('Provider retry deadline exceeds the supported timestamp range.');
            }

            return self::addSeconds($at, (int) $seconds);
        }

        // IMF-fixdate, the current HTTP-date format.
        if (preg_match('/^([A-Za-z]{3}), ([0-9]{2} [A-Za-z]{3} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}) GMT$/D', $header, $parts)) {
            $date = self::date('d M Y H:i:s', $parts[2]);

            return $date && $date->format('D') === $parts[1] ? $date : null;
        }

        // Obsolete RFC 850 format. Interpret its two-digit year as the most
        // recent matching year no more than 50 years after receipt.
        if (preg_match('/^([A-Za-z]+), ([0-9]{2}-[A-Za-z]{3})-([0-9]{2}) ([0-9]{2}:[0-9]{2}:[0-9]{2}) GMT$/D', $header, $parts)) {
            $futureLimit = $at->addYearsNoOverflow(50);
            $year = intdiv($futureLimit->year, 100) * 100 + (int) $parts[3];
            if ($year > $futureLimit->year) {
                $year -= 100;
            }
            $date = self::date('d-M-Y H:i:s', $parts[2].'-'.$year.' '.$parts[4]);
            if ($date && $date->greaterThan($futureLimit)) {
                $date = self::date('d-M-Y H:i:s', $parts[2].'-'.($year - 100).' '.$parts[4]);
            }

            return $date && $date->format('l') === $parts[1] ? $date : null;
        }

        // Obsolete ANSI C asctime format, including its space-padded day.
        if (preg_match('/^([A-Za-z]{3}) ([A-Za-z]{3}) ( [0-9]|[0-9]{2}) ([0-9]{2}:[0-9]{2}:[0-9]{2}) ([0-9]{4})$/D', $header, $parts)) {
            $day = str_pad((string) (int) $parts[3], 2, '0', STR_PAD_LEFT);
            $date = self::date('d M Y H:i:s', $day.' '.$parts[2].' '.$parts[5].' '.$parts[4]);

            return $date && $date->format('D') === $parts[1] ? $date : null;
        }

        return null;
    }

    /**
     * Positive jitter is added after the later of provider and local backoff.
     * It can never move execution before the provider's requested deadline.
     */
    public static function notBefore(
        mixed $header,
        CarbonInterface $receivedAt,
        int $fallbackSeconds = 15,
        int $jitterSeconds = 0
    ): CarbonImmutable {
        if ($fallbackSeconds < 0 || $jitterSeconds < 0) {
            throw new InvalidArgumentException('Provider retry delays must not be negative.');
        }
        $at = CarbonImmutable::instance($receivedAt)->utc();
        $fallback = self::addSeconds($at, $fallbackSeconds);
        $provider = self::parse($header, $at);
        $deadline = $provider && $provider->greaterThan($fallback) ? $provider : $fallback;

        return self::addSeconds($deadline, $jitterSeconds);
    }

    private static function date(string $format, string $value): ?CarbonImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format($format) !== $value || (int) $date->format('Y') < 1) {
            return null;
        }

        return CarbonImmutable::instance($date);
    }

    private static function addSeconds(CarbonImmutable $at, int $seconds): CarbonImmutable
    {
        if ($at->year < 1 || $at->year > 9999
            || $seconds > self::MAX_TIMESTAMP - $at->getTimestamp()) {
            throw new InvalidArgumentException('Provider retry deadline exceeds the supported timestamp range.');
        }

        return $at->addSeconds($seconds);
    }
}
