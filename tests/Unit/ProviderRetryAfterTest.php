<?php

namespace Tests\Unit;

use App\Support\ProviderRetryAfter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderRetryAfterTest extends TestCase
{
    public function test_numeric_delay_preserves_receipt_microseconds_and_normalizes_utc(): void
    {
        $received = Carbon::parse('2026-09-06 08:00:00.250000', 'America/New_York');
        $deadline = ProviderRetryAfter::parse(" \t000120\t ", $received);

        $this->assertInstanceOf(CarbonImmutable::class, $deadline);
        $this->assertSame('2026-09-06 12:02:00.250000', $deadline->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $deadline->timezoneName);
        $this->assertSame('2026-09-06 08:00:00.250000', $received->format('Y-m-d H:i:s.u'));
        $this->assertTrue(ProviderRetryAfter::parse(0, $received)->equalTo($received));
    }

    #[DataProvider('httpDates')]
    public function test_accepts_the_three_http_date_formats(string $header): void
    {
        $deadline = ProviderRetryAfter::parse($header, CarbonImmutable::parse('2026-09-06T12:00:00Z'));

        $this->assertSame('2026-09-06T12:02:00+00:00', $deadline?->toIso8601String());
    }

    public static function httpDates(): array
    {
        return [
            ['Sun, 06 Sep 2026 12:02:00 GMT'],
            ['Sunday, 06-Sep-26 12:02:00 GMT'],
            ['Sun Sep  6 12:02:00 2026'],
            ['Sun Sep 06 12:02:00 2026'],
        ];
    }

    public function test_obsolete_two_digit_year_never_lands_more_than_fifty_years_in_the_future(): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00Z');
        $past = CarbonImmutable::parse('1976-12-01T12:00:00Z');
        $header = $past->format('l, d-M-y H:i:s').' GMT';

        $this->assertTrue(ProviderRetryAfter::parse($header, $at)->equalTo($past));
        $future = CarbonImmutable::parse('2120-01-01T12:00:00Z');
        $this->assertTrue(ProviderRetryAfter::parse(
            $future->format('l, d-M-y H:i:s').' GMT',
            CarbonImmutable::parse('2090-01-01T12:00:00Z')
        )->equalTo($future));
    }

    #[DataProvider('invalidHeaders')]
    public function test_invalid_or_missing_headers_use_the_local_backoff_without_permissive_date_parsing(mixed $header): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00Z');

        $this->assertNull(ProviderRetryAfter::parse($header, $at));
        $this->assertTrue(ProviderRetryAfter::notBefore($header, $at, 15, 3)->equalTo($at->addSeconds(18)));
    }

    public static function invalidHeaders(): array
    {
        return [
            [null], [''], [[]], [false], [1.5], ['-1'], ['+1'], ['1.5'], ['1e3'],
            ['tomorrow'], ['2026-09-06T12:02:00Z'],
            ['Mon, 06 Sep 2026 12:02:00 GMT'],
            ['Sun, 31 Sep 2026 12:02:00 GMT'],
            ['Sun, 06 Sep 2026 25:02:00 GMT'],
            ['Sun, 06 Sep 2026 12:02:00 UTC'],
            ["120\r\nX-Secret: sentinel"],
            ['120, 240'],
        ];
    }

    public function test_local_backoff_and_jitter_never_shorten_the_provider_deadline(): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00Z');

        $this->assertTrue(ProviderRetryAfter::notBefore('120', $at, 15, 3)->equalTo($at->addSeconds(123)));
        $this->assertTrue(ProviderRetryAfter::notBefore('5', $at, 60, 3)->equalTo($at->addSeconds(63)));
        $this->assertTrue(ProviderRetryAfter::notBefore('0', $at, 15)->equalTo($at->addSeconds(15)));
        $this->assertTrue(ProviderRetryAfter::notBefore(
            'Sun, 06 Sep 2026 12:02:00 GMT', $at, 15, 3
        )->equalTo($at->addSeconds(123)));
        $this->assertTrue(ProviderRetryAfter::notBefore(
            'Sun, 06 Sep 2026 11:59:00 GMT', $at, 15, 3
        )->equalTo($at->addSeconds(18)));
    }

    public function test_a_long_valid_provider_delay_is_not_capped_to_the_local_backoff(): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00Z');

        $this->assertTrue(ProviderRetryAfter::notBefore('604800', $at, 15, 3)->equalTo($at->addWeek()->addSeconds(3)));
    }

    #[DataProvider('negativeDelays')]
    public function test_negative_local_delays_are_rejected(int $fallback, int $jitter): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderRetryAfter::notBefore('120', CarbonImmutable::parse('2026-09-06T12:00:00Z'), $fallback, $jitter);
    }

    public static function negativeDelays(): array
    {
        return [[-1, 0], [15, -1]];
    }

    public function test_numeric_overflow_fails_closed_without_exposing_the_header(): void
    {
        $header = str_repeat('9', 100);
        try {
            ProviderRetryAfter::notBefore($header, CarbonImmutable::parse('2026-09-06T12:00:00Z'));
            $this->fail('An unrepresentable provider deadline must not become a short fallback.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Provider retry deadline exceeds the supported timestamp range.', $exception->getMessage());
            $this->assertStringNotContainsString($header, $exception->getMessage());
        }
    }

    public function test_jitter_overflow_does_not_wrap_the_provider_deadline(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderRetryAfter::notBefore(
            'Fri, 31 Dec 9999 23:59:59 GMT',
            CarbonImmutable::parse('2026-09-06T12:00:00Z'),
            15,
            1
        );
    }
}
