<?php

namespace Tests\Unit;

use App\Jobs\FetchUnderlyingQuotesJob;
use App\Support\QuoteRefreshPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuoteRefreshPolicyTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function test_regular_and_delayed_final_session_boundaries(string $at, ?string $phase): void
    {
        $result = (new QuoteRefreshPolicy)->window(CarbonImmutable::parse($at, 'America/New_York'));
        $this->assertSame($phase, $result['phase']);
        $this->assertSame($phase !== null, $result['eligible']);
    }

    public static function boundaries(): array
    {
        return [
            ['2026-09-08 09:29:59', null],
            ['2026-09-08 09:30:00', 'regular'],
            ['2026-09-08 15:59:59', 'regular'],
            ['2026-09-08 16:00:00', null],
            ['2026-09-08 16:14:59', null],
            ['2026-09-08 16:15:00', 'final'],
            ['2026-09-08 16:29:59', 'final'],
            ['2026-09-08 16:30:00', null],
            ['2026-11-27 12:59:59', 'regular'],
            ['2026-11-27 13:00:00', null],
            ['2026-11-27 13:15:00', 'final'],
            ['2026-11-27 13:30:00', null],
            ['2026-11-27 16:15:00', null],
            ['2026-09-05 12:00:00', null],
            ['2026-09-06 16:15:00', null],
            ['2026-09-07 12:00:00', null],
            ['2026-12-25 16:15:00', null],
            ['2026-03-09 09:30:00', 'regular'],
        ];
    }

    public function test_receipt_completion_controls_regular_freshness_not_delayed_source_age(): void
    {
        $policy = new QuoteRefreshPolicy;
        $window = $policy->window(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
        $state = (object) ['source_asof' => '2026-09-08 13:30:00', 'captured_at' => '2026-09-08 14:00:00', 'ingestion_completed_at' => '2026-09-08 14:00:00'];
        $this->assertFalse($policy->isDue($state, $window));
        $state->captured_at = '2026-09-08 13:55:00';
        $state->ingestion_completed_at = '2026-09-08 13:55:02';
        $this->assertTrue($policy->isDue($state, $window));
        $this->assertTrue($policy->isDue(null, $window));
    }

    public function test_a_two_second_successful_fetch_does_not_delay_the_next_five_minute_tick(): void
    {
        $policy = new QuoteRefreshPolicy;
        $state = (object) ['captured_at' => '2026-09-08 13:30:00', 'ingestion_completed_at' => '2026-09-08 13:30:02'];
        $this->assertFalse($policy->isDue($state, $policy->window(CarbonImmutable::parse('2026-09-08 13:34:59', 'UTC'))));
        $this->assertTrue($policy->isDue($state, $policy->window(CarbonImmutable::parse('2026-09-08 13:35:00', 'UTC'))));
        $state->captured_at = '2026-09-08 13:35:01';
        $this->assertTrue($policy->isDue($state, $policy->window(CarbonImmutable::parse('2026-09-08 13:35:00', 'UTC'))));
    }

    public function test_regular_completion_cannot_hide_final_and_old_capture_cannot_claim_final_success(): void
    {
        $policy = new QuoteRefreshPolicy;
        $window = $policy->window(CarbonImmutable::parse('2026-09-08 20:16:00', 'UTC'));
        $state = (object) ['ingestion_completed_at' => '2026-09-08 20:15:59'];
        $this->assertTrue($policy->isDue($state, $window));
        $state->final_completed_at = '2026-09-08 20:15:59';
        $state->final_received_at = '2026-09-08 20:15:58';
        $state->final_captured_at = '2026-09-08 20:14:59';
        $this->assertTrue($policy->isDue($state, $window));
        $state->final_captured_at = '2026-09-08 20:15:00';
        $this->assertFalse($policy->isDue($state, $window));
        $state->final_received_at = '2026-09-08 20:14:59';
        $this->assertTrue($policy->isDue($state, $window));
    }

    public function test_disabled_default_and_closed_window_do_not_request_refresh(): void
    {
        $this->assertFalse(QuoteRefreshPolicy::enabled());
        $policy = new QuoteRefreshPolicy;
        $this->assertFalse($policy->isDue(null, $policy->window(CarbonImmutable::parse('2026-09-06', 'UTC'))));
    }

    public function test_old_serialized_quote_jobs_receive_safe_class_defaults(): void
    {
        $payload = (new FetchUnderlyingQuotesJob(['SPY']))->__serialize();
        unset($payload['scheduled'], $payload['sessionDate'], $payload['workRunDeliveries'], $payload['phase']);
        $restored = (new \ReflectionClass(FetchUnderlyingQuotesJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);
        $this->assertSame(['SPY'], $restored->symbols);
        $this->assertTrue($restored->scheduled);
        $this->assertNull($restored->sessionDate);
        $this->assertNull($restored->phase);
        $this->assertSame([], $restored->workRunDeliveries);
    }
}
