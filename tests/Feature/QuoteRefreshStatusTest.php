<?php

namespace Tests\Feature;

use App\Models\UnderlyingQuote;
use App\Support\QuoteRefreshStatus;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\MySqlTestCase;

class QuoteRefreshStatusTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-06 09:00:00', 'UTC'));
        config()->set([
            'quote_refresh.enabled' => true, 'cache.default' => 'array',
            'queue.default' => 'redis', 'queue.connections.redis.driver' => 'redis',
            'queue.connections.redis.connection' => 'quote-status-test-queue',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_status_reads_queue_and_stored_quotes_without_changing_data_or_creating_intent(): void
    {
        UnderlyingQuote::create(['symbol' => 'SPY', 'last_price' => 620.25, 'prev_close' => 619.5,
            'asof' => now('UTC')->subDays(2), 'source' => 'massive']);
        app(WorkRunCoordinator::class)->claim('quote_refresh', 'SPY',
            ['session_date' => '2026-09-04', 'phase' => 'regular'], 'quotes',
            at: now('UTC')->subMinutes(10), applyAdmissionLimits: false);
        $before = DB::table('underlying_quotes')->get()->toJson();
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('quote-status-test-queue')->andReturn($connection);
        $connection->shouldReceive('pipeline')->once()->andReturn([null, 0, 0, 0]);

        $result = app(QuoteRefreshStatus::class)->inspect(['SPY', 'QQQ']);

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['window']['eligible']);
        $this->assertTrue($result['queue']['available']);
        $this->assertSame(0, $result['queue']['ready']);
        $this->assertSame(1, $result['pending_intents']);
        $this->assertSame(0, $result['running_intents']);
        $this->assertSame(600, $result['oldest_pending_intent_age_seconds']);
        $this->assertFalse($result['symbols'][0]['scheduled_refresh_due']);
        $this->assertSame('SPY', $result['symbols'][0]['published_quote']->symbol);
        $this->assertNull($result['symbols'][1]['published_quote']);
        $this->assertSame($before, DB::table('underlying_quotes')->get()->toJson());
        $this->assertDatabaseCount('quote_refresh_states', 0);
        $this->assertDatabaseCount('work_runs', 1);
        Http::assertNothingSent();
    }

    public function test_queue_failure_is_reported_without_exposing_credentials_or_losing_stored_read_status(): void
    {
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException('secret-sentinel'));
        $result = app(QuoteRefreshStatus::class)->inspect(['SPY']);
        $this->assertFalse($result['queue']['available']);
        $this->assertSame('quote_queue_telemetry_unavailable', $result['queue']['error']);
        $this->assertStringNotContainsString('secret-sentinel', json_encode($result));
        $this->assertSame(0, $result['pending_intents']);
        Http::assertNothingSent();
    }

    public function test_disabled_policy_does_not_require_the_new_schema_or_queue_connection(): void
    {
        config()->set('quote_refresh.enabled', false);
        Redis::shouldReceive('connection')->never();
        DB::enableQueryLog();
        $result = app(QuoteRefreshStatus::class)->inspect(['SPY']);
        $this->assertFalse($result['enabled']);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_symbol_limit_is_enforced_before_any_queue_read(): void
    {
        Redis::shouldReceive('connection')->never();
        $this->expectException(\InvalidArgumentException::class);
        app(QuoteRefreshStatus::class)->inspect(array_fill(0, 21, 'SPY'));
    }
}
