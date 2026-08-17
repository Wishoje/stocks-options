<?php

namespace Tests\Unit;

use App\Support\ExchangeCalendarDte;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExchangeCalendarDteTest extends TestCase
{
    private ExchangeCalendarDte $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new ExchangeCalendarDte;
    }

    public function test_timezone_equivalent_instants_produce_the_same_exchange_date_and_dte(): void
    {
        $utc = $this->calendar->resolve(
            new DateTimeImmutable('2026-08-16T01:30:00Z'),
            '2026-08-17'
        );
        $newYorkOffset = $this->calendar->resolve(
            new DateTimeImmutable('2026-08-15T21:30:00-04:00'),
            '2026-08-17'
        );

        $this->assertSame($utc, $newYorkOffset);
        $this->assertSame([
            'exchange_as_of_date' => '2026-08-15',
            'expiration_date' => '2026-08-17',
            'dte' => 2,
        ], $utc);
    }

    #[DataProvider('calendarCases')]
    public function test_it_uses_nonnegative_new_york_calendar_day_dte(
        string $instant,
        string $expiration,
        string $expectedAsOfDate,
        int $expectedDte
    ): void {
        $result = $this->calendar->resolve(new DateTimeImmutable($instant), $expiration);

        $this->assertSame($expectedAsOfDate, $result['exchange_as_of_date']);
        $this->assertSame($expiration, $result['expiration_date']);
        $this->assertSame($expectedDte, $result['dte']);
        $this->assertGreaterThanOrEqual(0, $result['dte']);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function calendarCases(): array
    {
        return [
            'premarket is the same exchange date' => [
                '2026-07-17T08:00:00Z', '2026-07-17', '2026-07-17', 0,
            ],
            'aftermarket is the same exchange date' => [
                '2026-07-17T23:30:00Z', '2026-07-17', '2026-07-17', 0,
            ],
            'UTC next day can still be the prior New York date' => [
                '2026-07-18T02:00:00Z', '2026-07-18', '2026-07-17', 1,
            ],
            'spring DST instant before the clock change' => [
                '2026-03-08T06:30:00Z', '2026-03-09', '2026-03-08', 1,
            ],
            'spring DST instant after the clock change' => [
                '2026-03-08T07:30:00Z', '2026-03-09', '2026-03-08', 1,
            ],
            'fall DST first repeated hour' => [
                '2026-11-01T05:30:00Z', '2026-11-02', '2026-11-01', 1,
            ],
            'fall DST second repeated hour' => [
                '2026-11-01T06:30:00Z', '2026-11-02', '2026-11-01', 1,
            ],
            'month boundary' => [
                '2026-01-31T17:00:00-05:00', '2026-02-01', '2026-01-31', 1,
            ],
            'year boundary' => [
                '2026-12-31T17:00:00-05:00', '2027-01-01', '2026-12-31', 1,
            ],
            'leap-day boundary counts calendar dates' => [
                '2028-02-28T17:00:00-05:00', '2028-03-01', '2028-02-28', 2,
            ],
            'expired date clamps to zero' => [
                '2026-07-18T12:00:00-04:00', '2026-07-17', '2026-07-18', 0,
            ],
            'weekend receives calendar semantics without holiday inference' => [
                '2026-08-15T12:00:00-04:00', '2026-08-17', '2026-08-15', 2,
            ],
        ];
    }

    #[DataProvider('invalidExpirationDates')]
    public function test_it_rejects_nonexistent_or_noncanonical_expiration_dates(string $expiration): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expiration date must be a valid Y-m-d calendar date.');

        $this->calendar->resolve(new DateTimeImmutable('2026-02-01T12:00:00Z'), $expiration);
    }

    /** @return array<string, array{string}> */
    public static function invalidExpirationDates(): array
    {
        return [
            'nonexistent date' => ['2026-02-29'],
            'noncanonical month' => ['2026-2-01'],
            'timestamp instead of date' => ['2026-02-01T00:00:00Z'],
        ];
    }
}
