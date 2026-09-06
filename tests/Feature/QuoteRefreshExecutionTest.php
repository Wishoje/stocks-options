<?php

namespace Tests\Feature;

use App\Exceptions\ProviderDeferred;
use App\Exceptions\QuoteRefreshIncomplete;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Models\WorkRun;
use App\Support\PolygonClient;
use App\Support\ProviderRequestReplay;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class QuoteRefreshExecutionTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
        config()->set('quote_refresh.enabled', true);
        config()->set('provider_backpressure.enabled', true);
        config()->set('provider_backpressure.replay.enabled', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('queue_lanes.isolated', false);
        config()->set('cache.default', 'array');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_four_quotes_use_one_batch_and_recent_completed_receipts_skip_all_provider_work(): void
    {
        $symbols = ['AAPL', 'IWM', 'QQQ', 'SPY'];
        $deliveries = $this->deliveries($symbols);
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with($symbols)->andReturn(array_fill_keys($symbols, $this->quote()));
        $this->app->instance(PolygonClient::class, $client);
        $this->job($symbols, $deliveries)->handle();
        $this->assertSame(4, DB::table('quote_refresh_states')->count());
        $this->assertSame(4, WorkRun::query()->where('status', 'completed')->count());
        $this->travel(60)->seconds();
        (new FetchUnderlyingQuotesJob($symbols))->handle();
        $this->assertSame(4, DB::table('underlying_quotes')->count());
    }

    public function test_missing_symbol_fails_only_its_intent_and_valid_siblings_complete(): void
    {
        $map = $this->deliveries(['QQQ', 'SPY']);
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->andReturn(['QQQ' => null, 'SPY' => $this->quote()]);
        $this->app->instance(PolygonClient::class, $client);
        $this->job(['QQQ', 'SPY'], $map)->handle();
        $this->assertSame('failed', WorkRun::findOrFail($map['QQQ']['run_id'])->status);
        $this->assertSame('missing_quote', WorkRun::findOrFail($map['QQQ']['run_id'])->error_code);
        $this->assertSame('completed', WorkRun::findOrFail($map['SPY']['run_id'])->status);
        $this->assertDatabaseMissing('underlying_quotes', ['symbol' => 'QQQ']);
        $this->assertDatabaseHas('underlying_quotes', ['symbol' => 'SPY']);
        $this->assertDatabaseMissing('quote_refresh_states', ['symbol' => 'QQQ']);
    }

    public function test_provider_deferral_preserves_every_unfinished_intent_with_physical_attempt_count(): void
    {
        $map = $this->deliveries(['QQQ', 'SPY']);
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->andReturnUsing(function (): never {
            app(ProviderRequestReplay::class)->recordPhysicalRequest();
            throw new ProviderDeferred(ProviderDeferred::RATE_LIMITED, CarbonImmutable::now('UTC')->addMinutes(2), 429);
        });
        $this->app->instance(PolygonClient::class, $client);
        $this->job(['QQQ', 'SPY'], $map)->handle();
        foreach ($map as $delivery) {
            $run = WorkRun::findOrFail($delivery['run_id']);
            $this->assertSame('pending', $run->status);
            $this->assertSame(1, $run->provider_deferrals);
            $this->assertSame(0, $run->provider_admission_deferrals);
            $this->assertNull($run->delivery_token);
            $this->assertTrue($run->next_dispatch_at->equalTo(now('UTC')->addMinutes(2)));
        }
        $this->assertSame(0, DB::table('quote_refresh_states')->count());
    }

    public function test_lock_contention_defers_only_the_locked_symbol_without_http_for_that_symbol(): void
    {
        $map = $this->deliveries(['QQQ', 'SPY']);
        $lock = Cache::lock('quote-refresh:symbol:'.hash('sha256', 'QQQ'), 120);
        $this->assertTrue($lock->get());
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['SPY'])->andReturn(['SPY' => $this->quote()]);
        $this->app->instance(PolygonClient::class, $client);
        try {
            $this->job(['QQQ', 'SPY'], $map)->handle();
        } finally {
            $lock->release();
        }
        $waiting = WorkRun::findOrFail($map['QQQ']['run_id']);
        $this->assertSame('pending', $waiting->status);
        $this->assertSame(1, $waiting->provider_admission_deferrals);
        $this->assertSame(ProviderDeferred::QUOTE_PENDING, $waiting->error_code);
        $this->assertSame('completed', WorkRun::findOrFail($map['SPY']['run_id'])->status);
    }

    public function test_late_rotated_delivery_cannot_publish_quote_or_receipt_state(): void
    {
        $map = $this->deliveries(['SPY']);
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->andReturnUsing(function () use ($map): array {
            WorkRun::query()->whereKey($map['SPY']['run_id'])->update(['delivery_token' => 'superseded']);

            return ['SPY' => $this->quote()];
        });
        $this->app->instance(PolygonClient::class, $client);
        $this->job(['SPY'], $map)->handle();
        $this->assertDatabaseMissing('underlying_quotes', ['symbol' => 'SPY']);
        $this->assertDatabaseMissing('quote_refresh_states', ['symbol' => 'SPY']);
        $this->assertSame('running', WorkRun::findOrFail($map['SPY']['run_id'])->status);
    }

    public function test_queued_old_session_and_closed_scheduled_jobs_use_zero_provider_requests(): void
    {
        $map = $this->deliveries(['SPY'], '2026-09-04');
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldNotReceive('underlyingQuotes');
        $this->app->instance(PolygonClient::class, $client);
        $this->job(['SPY'], $map, '2026-09-04')->handle();
        $this->travelTo(CarbonImmutable::parse('2026-09-12 14:00:00', 'UTC'));
        (new FetchUnderlyingQuotesJob(['SPY']))->handle();
        $this->assertSame(0, DB::table('quote_refresh_states')->count());
        $this->assertSame('completed', WorkRun::findOrFail($map['SPY']['run_id'])->status);
    }

    public function test_missing_first_use_outside_hours_fetches_once_and_does_not_invent_ready_on_missing_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 14:00:00', 'UTC'));
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['NEW'])->andReturn(['NEW' => null]);
        $this->app->instance(PolygonClient::class, $client);
        try {
            (new FetchUnderlyingQuotesJob(['NEW'], scheduled: false))->handle();
            $this->fail('A first-use request with no usable quote must not succeed.');
        } catch (QuoteRefreshIncomplete) {
            $this->assertDatabaseMissing('underlying_quotes', ['symbol' => 'NEW']);
            $this->assertSame(0, DB::table('quote_refresh_states')->count());
        }
    }

    public function test_valid_first_use_outside_hours_is_reused_by_the_next_explicit_first_use(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 14:00:00', 'UTC'));
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['NEW'])->andReturn(['NEW' => $this->quote()]);
        $this->app->instance(PolygonClient::class, $client);
        (new FetchUnderlyingQuotesJob(['NEW'], scheduled: false))->handle();
        (new FetchUnderlyingQuotesJob(['NEW'], scheduled: false))->handle();
        $this->assertDatabaseHas('underlying_quotes', ['symbol' => 'NEW']);
    }

    public function test_final_request_is_not_suppressed_by_regular_completion_and_only_runs_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 20:15:00', 'UTC'));
        DB::table('quote_refresh_states')->insert([
            'symbol' => 'SPY', 'session_date' => '2026-09-08',
            'captured_at' => '2026-09-08 19:59:59', 'received_at' => '2026-09-08 20:14:59',
            'ingestion_completed_at' => '2026-09-08 20:14:59',
        ]);
        $map = $this->deliveries(['SPY'], phase: 'final');
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuotes')->once()->with(['SPY'])->andReturn(['SPY' => $this->quote()]);
        $this->app->instance(PolygonClient::class, $client);
        $this->job(['SPY'], $map, phase: 'final')->handle();
        $this->travel(5)->minutes();
        (new FetchUnderlyingQuotesJob(['SPY']))->handle();
        $state = DB::table('quote_refresh_states')->where('symbol', 'SPY')->first();
        $this->assertSame('2026-09-08 20:15:00.000000', $state->final_captured_at);
        $this->assertSame('2026-09-08 20:15:00.000000', $state->final_received_at);
        $this->assertSame('completed', WorkRun::findOrFail($map['SPY']['run_id'])->status);
    }

    #[DataProvider('writerCases')]
    public function test_v2_writer_fields_match_legacy_exactly(?array $stored, array $incoming): void
    {
        if ($stored) {
            foreach (['OLD', 'NEW'] as $symbol) {
                DB::table('underlying_quotes')->insert($stored + ['symbol' => $symbol, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuote')->once()->with('OLD')->andReturn($incoming);
        $client->shouldReceive('underlyingQuotes')->once()->with(['NEW'])->andReturn(['NEW' => $incoming]);
        $this->app->instance(PolygonClient::class, $client);
        config()->set('quote_refresh.enabled', false);
        (new FetchUnderlyingQuotesJob(['OLD']))->handle();
        config()->set('quote_refresh.enabled', true);
        (new FetchUnderlyingQuotesJob(['NEW']))->handle();
        $fields = ['source', 'last_price', 'prev_close', 'asof'];
        $old = DB::table('underlying_quotes')->where('symbol', 'OLD')->first($fields);
        $new = DB::table('underlying_quotes')->where('symbol', 'NEW')->first($fields);
        $this->assertSame((array) $old, (array) $new);
    }

    public static function writerCases(): array
    {
        $stored = ['source' => 'massive-v2-snapshot', 'last_price' => 101, 'prev_close' => 100, 'asof' => '2026-09-08 13:40:00'];

        return [
            'normal' => [null, ['source' => 'massive-v2-snapshot', 'last_price' => 102, 'prev_close' => 100, 'asof' => '2026-09-08 13:45:00']],
            'missing prev close clears' => [$stored, ['source' => 'massive-v2-snapshot', 'last_price' => 102, 'asof' => '2026-09-08 13:45:00']],
            'older source preserves' => [$stored, ['source' => 'massive-v2-snapshot', 'last_price' => 90, 'prev_close' => 88, 'asof' => '2026-09-08 13:39:59']],
            'unknown cannot replace timestamped' => [$stored, ['source' => 'massive-v2-snapshot', 'last_price' => 90, 'asof' => null]],
            'unknown initial uses receipt' => [null, ['source' => 'massive-v2-snapshot', 'last_price' => 90, 'asof' => null]],
            'real source replaces ingestion time' => [array_replace($stored, ['source' => 'massive-v2-snapshot:ingested-at']), ['source' => 'massive-v2-snapshot', 'last_price' => 90, 'asof' => '2026-09-08 13:30:00']],
            'nanoseconds' => [null, ['source' => 'massive-v2-snapshot', 'last_price' => 90, 'prev_close' => 88, 'asof' => '1788874500000000000']],
        ];
    }

    private function quote(): array
    {
        return ['source' => 'massive-v2-snapshot', 'last_price' => 101, 'prev_close' => 100, 'asof' => now('UTC')->subMinutes(15)->toIso8601String()];
    }

    private function deliveries(array $symbols, string $date = '2026-09-08', string $phase = 'regular'): array
    {
        $result = [];
        $runs = app(WorkRunCoordinator::class);
        foreach ($symbols as $symbol) {
            $run = $runs->claim('quote_refresh', $symbol, ['session_date' => $date, 'phase' => $phase], 'quotes', applyAdmissionLimits: false)['run'];
            $reserved = $runs->reserveDispatch($run->id);
            $result[$symbol] = ['run_id' => $run->id, 'delivery_token' => $reserved['delivery_token']];
        }

        return $result;
    }

    private function job(array $symbols, array $map, string $date = '2026-09-08', string $phase = 'regular'): FetchUnderlyingQuotesJob
    {
        return new FetchUnderlyingQuotesJob($symbols, scheduled: true, sessionDate: $date, workRunDeliveries: $map, phase: $phase);
    }
}
