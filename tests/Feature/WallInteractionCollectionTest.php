<?php

namespace Tests\Feature;

use App\Models\WallObservation;
use App\Services\WallInteractionService;
use App\Support\PolygonClient;
use App\Support\WallIntelligence\IntradayWallModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WallInteractionCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'services.massive.key' => 'test-key', 'services.massive.mode' => 'header',
            'services.massive.concurrency.enabled' => false, 'provider_backpressure.enabled' => true]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        (require database_path('migrations/2026_09_30_120000_index_intraday_wall_sessions.php'))->up();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-09-30T14:31:00Z'));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    private function observation(string $timeframe = '14d'): array
    {
        $payload = ['scope' => ['symbol' => 'SPY', 'session' => '2026-09-30', 'timeframe' => $timeframe],
            'observed_at' => '2026-09-30T14:00:00Z', 'scope_key' => $timeframe, 'basis_key' => 'basis',
            'provenance' => ['quote_source' => 'test'], 'walls' => ['put' => [['strike' => 100]], 'call' => [['strike' => 110]]]];
        $json = json_encode($payload);
        WallObservation::insert(['content_hash' => hash('sha256', $json), 'scope_key' => hash('sha256', $timeframe),
            'symbol' => 'SPY', 'dataset' => 'intraday_capture', 'schema_version' => IntradayWallModel::SCHEMA,
            'model_version' => IntradayWallModel::MODEL, 'observation_kind' => 'model_observation',
            'analysis_session' => '2026-09-30', 'source_date' => '2026-09-29', 'observed_at' => '2026-09-30 14:00:00',
            'captured_at' => $this->now(), 'recorded_at' => $this->now(), 'quality_state' => 'modeled',
            'payload_bytes' => strlen($json), 'payload_json' => $json, 'historical_outcome_eligible' => false]);

        return $payload;
    }

    private function provider(array $replace = []): array
    {
        return array_replace(['status' => 'OK', 'ticker' => 'SPY', 'adjusted' => false, 'results' => [
            ['t' => strtotime('2026-09-30T14:00:00Z') * 1000, 'o' => 100.5, 'h' => 100.6, 'l' => 100.4, 'c' => 100.5],
            ['t' => strtotime('2026-09-30T14:05:00Z') * 1000, 'o' => 100.5, 'h' => 100.6, 'l' => 99.7, 'c' => 99.8],
            ['t' => strtotime('2026-09-30T14:10:00Z') * 1000, 'o' => 99.8, 'h' => 99.9, 'l' => 99.6, 'c' => 99.7],
            ['t' => strtotime('2026-09-30T14:15:00Z') * 1000, 'o' => 99.7, 'h' => 99.8, 'l' => 99.5, 'c' => 99.6],
        ]], $replace);
    }

    public function test_one_request_serves_every_recorded_scope_and_keeps_only_completed_delayed_bars(): void
    {
        $observation = $this->observation();
        $this->observation('7d');
        Http::fake(['*' => Http::response($this->provider())]);
        $service = app(WallInteractionService::class);
        $result = $service->collect('SPY', '2026-09-30', $this->now());
        $this->assertSame('recorded', $result['status']);
        $this->assertSame(3, $result['bars']); // 10:15 bar closes after the delayed cutoff.
        $this->assertSame(2, $result['scopes']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/range/5/minute/2026-09-30/2026-09-30')
            && $request['adjusted'] === 'false' && $request['limit'] === 5000);
        $this->assertSame(1, WallObservation::where('dataset', 'intraday_price_bars')->count());
        $this->assertSame(2, WallObservation::where('dataset', 'wall_interactions')->count());
        $before = WallObservation::count();
        $read = $service->read('SPY', '2026-09-30', '14d', [$observation]);
        $this->assertSame('acceptance_below', $read['current']['put']['status']);
        $this->assertNotNull($read['price_evidence_hash']);
        $this->assertSame($before, WallObservation::count());
        $this->assertSame('recently_checked', $service->collect('SPY', '2026-09-30', $this->now())['status']);
        Http::assertSentCount(1);
    }

    public function test_revised_bars_append_evidence_without_overwriting_the_previous_assessment(): void
    {
        $this->observation();
        $revised = $this->provider();
        $revised['results'][2]['c'] = 99.85;
        Http::fakeSequence()->push($this->provider())->push($revised)->push($this->provider());
        $service = app(WallInteractionService::class);
        $service->collect('SPY', '2026-09-30', $this->now());
        $original = WallObservation::where('dataset', 'intraday_price_bars')->first();
        foreach ([1, 2] as $i) {
            $this->travel(6)->minutes();
            $service->collect('SPY', '2026-09-30', $this->now());
        }
        $this->assertSame($original->payload_json, $original->fresh()->payload_json);
        $this->assertSame(3, WallObservation::where('dataset', 'intraday_price_bars')->count());
        $latest = json_decode(WallObservation::where('dataset', 'intraday_price_bars')->latest('id')->first()->payload_json, true);
        $this->assertSame(99.7, $latest['bars'][2]['c']); // A provider reverting a correction is also recorded.
    }

    public function test_rate_limit_and_permission_failures_stop_following_provider_requests(): void
    {
        $this->observation();
        Http::fake(['*' => Http::response([], 429)]);
        $service = app(WallInteractionService::class);
        $this->assertSame('rate_limited', $service->collect('SPY', '2026-09-30', $this->now())['status']);
        $this->assertSame('provider_backoff', $service->collect('QQQ', '2026-09-30', $this->now())['status']);
        Http::assertSentCount(1);
        $this->assertSame(0, WallObservation::where('dataset', 'intraday_price_bars')->count());
    }

    public function test_wrong_symbol_split_adjusted_or_paginated_responses_are_rejected(): void
    {
        foreach ([['ticker' => 'QQQ'], ['adjusted' => true], ['next_url' => 'https://api.massive.com/next']] as $change) {
            Http::fake(['*' => Http::response($this->provider($change))]);
            $this->assertSame('unexpected_response', app(PolygonClient::class)->wallPriceBars('SPY', '2026-09-30')['status']);
        }
    }

    public function test_symbol_and_session_isolation_and_no_fetch_without_walls(): void
    {
        $service = app(WallInteractionService::class);
        $this->assertSame('waiting_for_walls', $service->collect('SPY', '2026-09-30', $this->now())['status']);
        $result = $service->read('QQQ', '2026-09-29', '7d', []);
        $this->assertSame('waiting_for_bars', $result['state']);
        $this->assertEmpty($result['events']);
        Http::assertNothingSent();
    }

    public function test_disabled_or_out_of_session_schedule_does_not_fetch_and_historical_review_is_local_only(): void
    {
        config(['wall_tracking.interactions_enabled' => false]);
        $this->artisan('walls:capture-interactions')->expectsOutput('Wall interaction collection is disabled.')->assertSuccessful();
        $this->artisan('walls:capture-interactions --local-review')->assertFailed();
        config(['wall_tracking.interactions_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-03T15:00:00Z'));
        $this->artisan('walls:capture-interactions')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_eod_review_clock_does_not_hide_newer_recorded_intraday_bars(): void
    {
        $this->app->instance('env', 'local');
        config(['ui_review.now' => '2026-09-14T12:00:00Z']);
        $middleware = new \App\Http\Middleware\UseLocalReviewClock;
        $middleware->handle(\Illuminate\Http\Request::create('/api/intraday/walls'), function () {
            $this->assertSame('2026-09-30', now()->toDateString());

            return response('ok');
        });
        $middleware->handle(\Illuminate\Http\Request::create('/api/gex-levels'), function () {
            $this->assertSame('2026-09-14', now()->toDateString());

            return response('ok');
        });
    }

    public function test_previous_session_recovery_previews_then_records_real_bars_once_across_scopes(): void
    {
        config(['wall_tracking.interactions_enabled' => true]);
        $this->observation();
        $this->observation('7d');
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        Http::fake(['*' => Http::response($this->provider())]);
        $wallRows = WallObservation::where('dataset', 'intraday_capture')->pluck('payload_json', 'id')->all();
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY --dry-run')
            ->expectsOutput('SPY: {"session":"2026-09-30","wall_readings":2,"status":"would_collect"}')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(2, WallObservation::count());
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY --session=2026-09-30')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(1, WallObservation::where('dataset', 'intraday_price_bars')->count());
        $this->assertSame(2, WallObservation::where('dataset', 'wall_interactions')->count());
        $assessment = json_decode(WallObservation::where('dataset', 'wall_interactions')->first()->payload_json, true);
        $this->assertNotEmpty($assessment['events']);
        $this->assertFalse($assessment['historical_outcome_eligible']);
        $this->assertSame($wallRows, WallObservation::where('dataset', 'intraday_capture')->pluck('payload_json', 'id')->all());
        $this->travel(6)->minutes(); // Skip already stored evidence even after the request cache expires.
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY')
            ->expectsOutput('SPY: {"session":"2026-09-30","wall_readings":2,"status":"already_recorded"}')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(5, WallObservation::count());
    }

    public function test_recovery_requires_enabled_collection_and_a_small_explicit_symbol_list(): void
    {
        $this->observation();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        config(['wall_tracking.interactions_enabled' => false]);
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY')
            ->expectsOutput('Wall interaction collection is disabled.')->assertSuccessful();
        config(['wall_tracking.interactions_enabled' => true]);
        $this->artisan('walls:capture-interactions --recover-previous')->assertFailed();
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY --symbol=QQQ --symbol=TSLA --symbol=AAPL --symbol=IWM --symbol=NVDA')->assertFailed();
        $this->artisan('walls:capture-interactions --recover-previous --symbol=bad/symbol')->assertFailed();
        $this->artisan('walls:capture-interactions --recover-previous --local-review --symbol=SPY')->assertFailed();
        $this->artisan('walls:capture-interactions --dry-run')->assertFailed();
        $this->artisan('walls:capture-interactions --recover-previous --symbol=QQQ')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(1, WallObservation::count());
    }

    public function test_recovery_rejects_other_dates_and_live_hours_and_respects_early_closes(): void
    {
        config(['wall_tracking.interactions_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        foreach (['2026-10-01', '2026-09-29', '2026-02-30'] as $date) {
            $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY --session='.$date)->assertFailed();
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-01T15:00:00Z'));
        $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY')->assertFailed();
        foreach (['2026-11-27T18:30:00Z', '2026-11-28T12:00:00Z'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at));
            $this->artisan('walls:capture-interactions --recover-previous --symbol=SPY --dry-run')
                ->expectsOutput('SPY: {"session":"2026-11-27","wall_readings":0,"status":"waiting_for_walls"}')->assertSuccessful();
        }
        Http::assertNothingSent();
        $this->assertSame(0, WallObservation::count());
    }
}
