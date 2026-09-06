<?php

namespace Tests\Unit;

use App\Support\Market;
use App\Support\MarketSession;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarketSessionTest extends TestCase
{
    #[DataProvider('sessionBoundaries')]
    public function test_exact_session_and_finalization_boundaries(
        string $instant,
        string $state,
        string $tradeDate,
        bool $rth,
        bool $refreshAllowed
    ): void {
        $at = CarbonImmutable::parse($instant, 'America/New_York');
        $actual = MarketSession::describe($at);

        $this->assertSame($state, $actual['state']);
        $this->assertSame($tradeDate, $actual['trade_date']);
        $this->assertSame($at->toDateString(), $actual['session_date']);
        $this->assertTrue($actual['is_trading_day']);
        $this->assertSame($rth, $actual['is_rth']);
        $this->assertSame($refreshAllowed, $actual['refresh_allowed']);
        $this->assertSame($rth, Market::isRthOpen($at));
        $this->assertGreaterThan($at->timestamp, CarbonImmutable::parse($actual['next_open_at'])->timestamp);
    }

    public static function sessionBoundaries(): array
    {
        return [
            'preopen final second' => ['2026-09-08 09:29:59', 'premarket', '2026-09-04', false, false],
            'open exact' => ['2026-09-08 09:30:00', 'rth', '2026-09-08', true, true],
            'last regular second' => ['2026-09-08 15:59:59', 'rth', '2026-09-08', true, true],
            'close exclusive' => ['2026-09-08 16:00:00', 'postmarket', '2026-09-08', false, true],
            'last finalization second' => ['2026-09-08 16:14:59', 'postmarket', '2026-09-08', false, true],
            'finalization end exclusive' => ['2026-09-08 16:15:00', 'postmarket', '2026-09-08', false, false],
            'after hours' => ['2026-09-08 23:59:59', 'postmarket', '2026-09-08', false, false],
            'early close last second' => ['2026-11-27 12:59:59', 'rth', '2026-11-27', true, true],
            'early close exclusive' => ['2026-11-27 13:00:00', 'postmarket', '2026-11-27', false, true],
            'early finalization last second' => ['2026-11-27 13:14:59', 'postmarket', '2026-11-27', false, true],
            'early finalization end exclusive' => ['2026-11-27 13:15:00', 'postmarket', '2026-11-27', false, false],
        ];
    }

    #[DataProvider('calendarYears')]
    public function test_every_calendar_date_matches_the_published_2025_through_2028_closures(int $year, array $holidays): void
    {
        $date = CarbonImmutable::create($year, 1, 1, 12, 0, 0, 'America/New_York');
        while ($date->year === $year) {
            $expected = ! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true);
            $this->assertSame($expected, MarketSession::isTradingDay($date), $date->toDateString());
            $this->assertSame($expected, MarketSession::describe($date)['is_rth'], $date->toDateString());
            $date = $date->addDay();
        }
    }

    public static function calendarYears(): array
    {
        // NYSE published calendars, plus the announced 2025-01-09 closure.
        return [
            '2025' => [2025, [
                '2025-01-01', '2025-01-09', '2025-01-20', '2025-02-17', '2025-04-18',
                '2025-05-26', '2025-06-19', '2025-07-04', '2025-09-01', '2025-11-27', '2025-12-25',
            ]],
            '2026' => [2026, [
                '2026-01-01', '2026-01-19', '2026-02-16', '2026-04-03', '2026-05-25',
                '2026-06-19', '2026-07-03', '2026-09-07', '2026-11-26', '2026-12-25',
            ]],
            '2027' => [2027, [
                '2027-01-01', '2027-01-18', '2027-02-15', '2027-03-26', '2027-05-31',
                '2027-06-18', '2027-07-05', '2027-09-06', '2027-11-25', '2027-12-24',
            ]],
            '2028' => [2028, [
                '2028-01-17', '2028-02-21', '2028-04-14', '2028-05-29', '2028-06-19',
                '2028-07-04', '2028-09-04', '2028-11-23', '2028-12-25',
            ]],
        ];
    }

    #[DataProvider('closedSessions')]
    public function test_closed_dates_preserve_the_previous_trading_date_and_find_the_next_open(
        string $date,
        string $tradeDate,
        string $nextOpen
    ): void {
        $actual = MarketSession::describe(CarbonImmutable::parse($date.' 12:00:00', 'America/New_York'));

        $this->assertSame('closed', $actual['state']);
        $this->assertSame($date, $actual['session_date']);
        $this->assertSame($tradeDate, $actual['trade_date']);
        $this->assertFalse($actual['is_trading_day']);
        $this->assertFalse($actual['is_rth']);
        $this->assertFalse($actual['refresh_allowed']);
        $this->assertNull($actual['opens_at']);
        $this->assertNull($actual['closes_at']);
        $this->assertSame($nextOpen, $actual['next_open_at']);
    }

    public static function closedSessions(): array
    {
        return [
            'mourning' => ['2025-01-09', '2025-01-08', '2025-01-10T14:30:00+00:00'],
            'weekend before Labor Day' => ['2026-09-06', '2026-09-04', '2026-09-08T13:30:00+00:00'],
            'Labor Day' => ['2026-09-07', '2026-09-04', '2026-09-08T13:30:00+00:00'],
            'Thanksgiving' => ['2026-11-26', '2026-11-25', '2026-11-27T14:30:00+00:00'],
            'Good Friday' => ['2026-04-03', '2026-04-02', '2026-04-06T13:30:00+00:00'],
            'Saturday Juneteenth observed' => ['2027-06-18', '2027-06-17', '2027-06-21T13:30:00+00:00'],
            'Christmas observed' => ['2027-12-24', '2027-12-23', '2027-12-27T14:30:00+00:00'],
            'New Year Saturday' => ['2028-01-01', '2027-12-31', '2028-01-03T14:30:00+00:00'],
            'New Year Sunday observed' => ['2023-01-02', '2022-12-30', '2023-01-03T14:30:00+00:00'],
        ];
    }

    #[DataProvider('closeTimes')]
    public function test_early_closes_are_not_shifted_to_other_trading_days(string $date, string $expectedClose): void
    {
        $actual = MarketSession::describe(CarbonImmutable::parse($date.' 12:00:00', 'America/New_York'));

        $this->assertTrue($actual['is_trading_day']);
        $this->assertSame($expectedClose, $actual['closes_at']);
    }

    public static function closeTimes(): array
    {
        return [
            '2025 July 3' => ['2025-07-03', '2025-07-03T17:00:00+00:00'],
            '2025 Thanksgiving Friday' => ['2025-11-28', '2025-11-28T18:00:00+00:00'],
            '2025 Christmas Eve' => ['2025-12-24', '2025-12-24T18:00:00+00:00'],
            '2026 July 2 remains full day' => ['2026-07-02', '2026-07-02T20:00:00+00:00'],
            '2026 Thanksgiving Friday' => ['2026-11-27', '2026-11-27T18:00:00+00:00'],
            '2026 Christmas Eve' => ['2026-12-24', '2026-12-24T18:00:00+00:00'],
            '2027 July 2 remains full day' => ['2027-07-02', '2027-07-02T20:00:00+00:00'],
            '2027 Thanksgiving Friday' => ['2027-11-26', '2027-11-26T18:00:00+00:00'],
            '2027 December 23 remains full day' => ['2027-12-23', '2027-12-23T21:00:00+00:00'],
            '2027 December 31 not New Year observed' => ['2027-12-31', '2027-12-31T21:00:00+00:00'],
            '2028 July 3' => ['2028-07-03', '2028-07-03T17:00:00+00:00'],
            '2028 Thanksgiving Friday' => ['2028-11-24', '2028-11-24T18:00:00+00:00'],
            '2028 December 22 remains full day' => ['2028-12-22', '2028-12-22T21:00:00+00:00'],
        ];
    }

    #[DataProvider('daylightSavingSessions')]
    public function test_open_and_close_utc_times_follow_new_york_dst(string $date, string $open, string $close): void
    {
        $actual = MarketSession::describe(CarbonImmutable::parse($date.' 08:00:00', 'America/New_York'));

        $this->assertSame($open, $actual['opens_at']);
        $this->assertSame($close, $actual['closes_at']);
        $this->assertSame($open, $actual['next_open_at']);
    }

    public static function daylightSavingSessions(): array
    {
        return [
            ['2026-03-06', '2026-03-06T14:30:00+00:00', '2026-03-06T21:00:00+00:00'],
            ['2026-03-09', '2026-03-09T13:30:00+00:00', '2026-03-09T20:00:00+00:00'],
            ['2026-10-30', '2026-10-30T13:30:00+00:00', '2026-10-30T20:00:00+00:00'],
            ['2026-11-02', '2026-11-02T14:30:00+00:00', '2026-11-02T21:00:00+00:00'],
        ];
    }

    public function test_equivalent_instants_do_not_mutate_a_mutable_caller(): void
    {
        $mutable = Carbon::parse('2026-09-09T00:30:00+00:00');
        $before = $mutable->toIso8601String();
        $actual = MarketSession::describe($mutable);

        $this->assertSame($before, $mutable->toIso8601String());
        $this->assertSame('2026-09-08', $actual['session_date']);
        $this->assertSame('2026-09-08', $actual['trade_date']);
        $this->assertSame($actual, MarketSession::describe(CarbonImmutable::parse('2026-09-08T20:30:00-04:00')));
        $this->assertSame('2026-09-09T13:30:00+00:00', $actual['next_open_at']);
    }

    public function test_omitted_instant_uses_the_testable_clock_without_laravel_or_database(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T16:00:00Z'));
        try {
            $this->assertSame('closed', MarketSession::describe()['state']);
            $this->assertFalse(Market::isRthOpen());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
