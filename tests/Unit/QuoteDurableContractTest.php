<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use App\Jobs\FetchUnderlyingQuotesJob;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class QuoteDurableContractTest extends TestCase
{
    public function test_durable_quote_batch_uses_its_persisted_attempt_budget_not_legacy_retry_until(): void
    {
        config()->set('provider_backpressure.enabled', true);
        $job = new FetchUnderlyingQuotesJob(['SPY'], workRunDeliveries: [
            'SPY' => ['run_id' => 'fixture-run', 'delivery_token' => 'fixture-token'],
        ]);
        $this->assertNull($job->retryUntil());
        $this->assertSame(3, $job->tries);
        $this->assertSame(90, $job->timeout);
        $this->assertNotNull((new FetchUnderlyingQuotesJob(['SPY']))->retryUntil());
    }

    public function test_quote_lock_wait_is_zero_http_admission_without_a_provider_failure(): void
    {
        $now = CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC');
        $wait = new ProviderDeferred(ProviderDeferred::QUOTE_PENDING, $now->addSeconds(15));
        $this->assertTrue($wait->isAdmissionDeferral());
        $this->assertSame(15, $wait->retryAfterSeconds($now));
        $this->assertNull($wait->httpStatus);
    }
}
