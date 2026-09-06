<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderDeferredTest extends TestCase
{
    public function test_exception_contains_only_allowlisted_safe_retry_metadata(): void
    {
        $deadline = CarbonImmutable::parse('2026-09-06 08:02:00', 'America/New_York');
        $exception = new ProviderDeferred(ProviderDeferred::RATE_LIMITED, $deadline, 429);

        $this->assertSame('provider_rate_limited', $exception->reason);
        $this->assertSame(429, $exception->httpStatus);
        $this->assertSame(429, $exception->getCode());
        $this->assertSame('UTC', $exception->notBefore->timezoneName);
        $this->assertSame('2026-09-06T12:02:00+00:00', $exception->notBefore->toIso8601String());
        $this->assertSame('Provider work deferred: provider_rate_limited.', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function test_second_resolution_retry_delay_rounds_up_and_never_becomes_negative(): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00.250000Z');
        $exception = new ProviderDeferred(ProviderDeferred::CAPACITY, $at->addSeconds(120)->addMicroseconds(1));

        $this->assertSame(121, $exception->retryAfterSeconds($at));
        $this->assertSame(0, $exception->retryAfterSeconds($at->addSeconds(121)));
        $this->assertSame(0, $exception->retryAfterSeconds($exception->notBefore));
        $this->assertSame(1, $exception->retryAfterSeconds($exception->notBefore->subMicrosecond()));
    }

    #[DataProvider('httpReasons')]
    public function test_http_classification_keeps_permanent_and_non_retryable_statuses_separate(int $status, ?string $expected): void
    {
        $this->assertSame($expected, ProviderDeferred::reasonForHttpStatus($status));
    }

    public static function httpReasons(): array
    {
        return [
            [200, null], [202, null], [400, null], [401, null], [403, null], [404, null],
            [429, ProviderDeferred::RATE_LIMITED],
            [500, ProviderDeferred::SERVER_ERROR], [503, ProviderDeferred::SERVER_ERROR],
            [599, ProviderDeferred::SERVER_ERROR], [600, null],
        ];
    }

    public function test_admission_wait_is_distinguished_from_an_attempt_that_reached_the_provider(): void
    {
        $at = CarbonImmutable::parse('2026-09-06T12:00:00Z');
        foreach ([ProviderDeferred::CAPACITY, ProviderDeferred::RATE_WINDOW, ProviderDeferred::COOLDOWN, ProviderDeferred::COORDINATION, ProviderDeferred::BACKPRESSURE] as $reason) {
            $this->assertTrue((new ProviderDeferred($reason, $at))->isAdmissionDeferral());
        }
        foreach ([ProviderDeferred::RATE_LIMITED, ProviderDeferred::SERVER_ERROR, ProviderDeferred::TIMEOUT, ProviderDeferred::NETWORK] as $reason) {
            $this->assertFalse((new ProviderDeferred($reason, $at))->isAdmissionDeferral());
        }
    }

    public function test_arbitrary_reason_cannot_leak_provider_credentials_into_the_exception_message(): void
    {
        try {
            new ProviderDeferred('https://provider.test?apiKey=secret-sentinel', CarbonImmutable::now());
            $this->fail('Only allowlisted reason codes are accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Provider deferral reason is not recognized.', $exception->getMessage());
            $this->assertStringNotContainsString('secret-sentinel', $exception->getMessage());
        }
    }

    public function test_invalid_http_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderDeferred(ProviderDeferred::SERVER_ERROR, CarbonImmutable::now(), 999);
    }

    public function test_a_deadline_outside_the_durable_utc_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderDeferred(
            ProviderDeferred::CAPACITY,
            CarbonImmutable::parse('9999-12-31T23:59:59-12:00')
        );
    }
}
