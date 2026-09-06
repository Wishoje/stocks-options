<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * NYSE core-session calendar and the application's intraday ingestion window.
 *
 * Scheduled rules verified against https://www.nyse.com/trade/hours-calendars
 * for 2025-2028. Exceptional closures require an explicit calendar update.
 * This is not an options-expiration/DTE or extended-hours trading calendar.
 */
final class MarketSession
{
    private const TIMEZONE = 'America/New_York';

    private const FINALIZATION_MINUTES = 15;

    // NYSE's announced National Day of Mourning for President Jimmy Carter.
    private const EXTRA_CLOSED_DATES = ['2025-01-09'];

    /** @var array<int, array<string, true>> */
    private static array $holidaysByYear = [];

    /**
     * Pre/postmarket describe the calendar day's position around the core
     * session, not whether a particular venue accepts extended-hours orders.
     * next_open_at is strictly after the supplied instant.
     *
     * @return array{state:string,trade_date:string,session_date:string,is_trading_day:bool,is_rth:bool,refresh_allowed:bool,opens_at:?string,closes_at:?string,next_open_at:string}
     */
    public static function describe(?CarbonInterface $at = null): array
    {
        $ny = ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now(self::TIMEZONE))
            ->setTimezone(self::TIMEZONE);
        $date = $ny->startOfDay();
        $tradingDay = self::isTradingDay($date);
        $opens = $tradingDay ? $date->setTime(9, 30) : null;
        $closes = $tradingDay ? $date->setTime(self::isEarlyClose($date) ? 13 : 16, 0) : null;
        $rth = $tradingDay && $ny->greaterThanOrEqualTo($opens) && $ny->lessThan($closes);
        $state = match (true) {
            ! $tradingDay => 'closed',
            $ny->lessThan($opens) => 'premarket',
            $rth => 'rth',
            default => 'postmarket',
        };
        $tradeDate = $tradingDay && $ny->greaterThanOrEqualTo($opens)
            ? $date
            : self::adjacentTradingDay($date, -1);
        $nextOpen = $tradingDay && $ny->lessThan($opens)
            ? $opens
            : self::adjacentTradingDay($date, 1)->setTime(9, 30);

        return [
            'state' => $state,
            'trade_date' => $tradeDate->toDateString(),
            'session_date' => $date->toDateString(),
            'is_trading_day' => $tradingDay,
            'is_rth' => $rth,
            'refresh_allowed' => $tradingDay
                && $ny->greaterThanOrEqualTo($opens)
                && $ny->lessThan($closes->addMinutes(self::FINALIZATION_MINUTES)),
            'opens_at' => $opens?->utc()->toIso8601String(),
            'closes_at' => $closes?->utc()->toIso8601String(),
            'next_open_at' => $nextOpen->utc()->toIso8601String(),
        ];
    }

    public static function isTradingDay(CarbonInterface $at): bool
    {
        $date = CarbonImmutable::instance($at)->setTimezone(self::TIMEZONE);

        return ! $date->isWeekend()
            && ! isset(self::holidays($date->year)[$date->toDateString()])
            && ! in_array($date->toDateString(), self::EXTRA_CLOSED_DATES, true);
    }

    private static function adjacentTradingDay(CarbonImmutable $date, int $direction): CarbonImmutable
    {
        do {
            $date = $date->addDays($direction);
        } while (! self::isTradingDay($date));

        return $date;
    }

    private static function isEarlyClose(CarbonImmutable $date): bool
    {
        // The caller already established that this is a trading day. Do not
        // shift an early close when July 3 or Christmas Eve is itself closed.
        return in_array($date->format('m-d'), ['07-03', '12-24'], true)
            || $date->toDateString() === self::nthWeekday($date->year, 11, CarbonInterface::THURSDAY, 4)
                ->addDay()->toDateString();
    }

    /** @return array<string, true> */
    private static function holidays(int $year): array
    {
        if (isset(self::$holidaysByYear[$year])) {
            return self::$holidaysByYear[$year];
        }

        $newYear = self::date($year, 1, 1);
        $dates = [
            // Unlike Christmas/July 4, a Saturday New Year does not close
            // the preceding Friday. Sunday is observed on Monday.
            $newYear->isSunday() ? $newYear->addDay() : $newYear,
            self::nthWeekday($year, 2, CarbonInterface::MONDAY, 3),
            self::easterSunday($year)->subDays(2),
            self::date($year, 5, 1)->lastOfMonth(CarbonInterface::MONDAY),
            self::observed(self::date($year, 7, 4)),
            self::nthWeekday($year, 9, CarbonInterface::MONDAY, 1),
            self::nthWeekday($year, 11, CarbonInterface::THURSDAY, 4),
            self::observed(self::date($year, 12, 25)),
        ];
        if ($year >= 1998) {
            $dates[] = self::nthWeekday($year, 1, CarbonInterface::MONDAY, 3);
        }
        if ($year >= 2022) {
            $dates[] = self::observed(self::date($year, 6, 19));
        }

        return self::$holidaysByYear[$year] = array_fill_keys(array_map(
            static fn (CarbonImmutable $date): string => $date->toDateString(),
            $dates
        ), true);
    }

    private static function observed(CarbonImmutable $date): CarbonImmutable
    {
        return match (true) {
            $date->isSaturday() => $date->subDay(),
            $date->isSunday() => $date->addDay(),
            default => $date,
        };
    }

    private static function nthWeekday(int $year, int $month, int $weekday, int $occurrence): CarbonImmutable
    {
        return self::date($year, $month, 1)->firstOfMonth($weekday)->addWeeks($occurrence - 1);
    }

    private static function date(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, self::TIMEZONE);
    }

    /** Gregorian computus; no dependency on the optional PHP calendar extension. */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $value = $h + $l - 7 * $m + 114;

        return self::date($year, intdiv($value, 31), $value % 31 + 1);
    }
}
