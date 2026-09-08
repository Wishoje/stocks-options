<?php

namespace Tests\Feature;

use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PrimeSymbolJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PricesDailySessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 07:00:00', 'UTC'));
        config()->set([
            'cache.default' => 'array',
            'queue_lanes.isolated' => false,
            'services.massive.concurrency.enabled' => false,
            'provider_backpressure.enabled' => false,
            'services.massive.key' => 'test-only',
            'services.massive.base' => 'https://api.massive.test',
        ]);
        Http::preventStrayRequests();
    }

    public function test_new_jobs_normalize_holidays_but_keep_explicit_trading_days(): void
    {
        $this->assertSame('2026-09-04', (new PricesDailyJob(['HOLI']))->targetDate);
        $this->assertSame('2026-09-04', (new PricesDailyJob(['HOLI'], '2026-09-07'))->targetDate);
        $this->assertSame('2026-09-08', (new PricesDailyJob(['HOLI'], '2026-09-08'))->targetDate);
    }

    public function test_pre_deployment_serialized_holiday_job_writes_the_real_session_only(): void
    {
        Http::fake(['api.massive.test/*' => Http::response(['from' => '2026-09-04', 'open' => 100, 'high' => 105, 'low' => 99, 'close' => 104])]);
        $job = new PricesDailyJob(['HOLI'], '2026-09-04');
        $job->targetDate = '2026-09-07'; // Existing serialized queue payload.
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/open-close/HOLI/2026-09-04'));
        $this->assertDatabaseHas('prices_daily', ['symbol' => 'HOLI', 'trade_date' => '2026-09-04', 'close' => 104]);
        $this->assertDatabaseMissing('prices_daily', ['symbol' => 'HOLI', 'trade_date' => '2026-09-07']);
    }

    public function test_missing_bar_on_a_real_trading_day_still_fails(): void
    {
        Cache::put('px:finnhub:deny:HOLI', 1, 60);
        Http::fake(fn () => Http::response([]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Daily price refresh incomplete');
        (new PricesDailyJob(['HOLI'], '2026-09-04'))->handle();
    }

    public function test_holiday_planning_reuses_friday_bar_without_changing_option_analytics_anchor(): void
    {
        DB::table('prices_daily')->insert(['symbol' => 'HOLI', 'trade_date' => '2026-09-04', 'close' => 104]);
        $jobs = collect((new PrimeSymbolJob('HOLI'))->plannedJobs('2026-09-07'));
        $this->assertFalse($jobs->contains(fn ($job) => $job instanceof PricesDailyJob));
        $this->assertSame('2026-09-07', $jobs->first(fn ($job) => $job instanceof ComputeVolMetricsJob)->anchorDate);
    }

    public function test_holiday_planning_requests_friday_if_that_bar_is_missing(): void
    {
        $jobs = collect((new PrimeSymbolJob('HOLI'))->plannedJobs('2026-09-07'));
        $daily = $jobs->first(fn ($job) => $job instanceof PricesDailyJob);
        $this->assertInstanceOf(PricesDailyJob::class, $daily);
        $this->assertSame('2026-09-04', $daily->targetDate);
    }
}
