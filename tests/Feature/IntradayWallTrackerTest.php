<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WallObservation;
use App\Services\IntradayWallTracker;
use App\Support\EodSnapshotSelector;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class IntradayWallTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_09_25_080000_create_wall_observations_table.php'))->up();
        (require database_path('migrations/2026_09_30_120000_index_intraday_wall_sessions.php'))->up();
        Schema::create('underlying_quotes', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->string('source');
            $t->double('last_price');
            $t->timestamp('asof');
            $t->timestamps();
        });
        Schema::create('option_expirations', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->date('expiration_date');
        });
        DB::table('option_expirations')->insert(['id' => 1, 'symbol' => 'SPY', 'expiration_date' => '2026-10-02']);
        DB::table('underlying_quotes')->insert(['symbol' => 'SPY', 'source' => 'massive-v2-snapshot', 'last_price' => 100, 'asof' => '2026-09-30 14:00:00']);
        $this->rows();
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function rows(string $date = '2026-09-29', int $oi = 200, ?float $callIv = .2): void
    {
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('selectedRows')->andReturn(collect([
            (object) ['expiration_id' => 1, 'option_type' => 'call', 'strike' => 100, 'open_interest' => $oi, 'iv' => $callIv, 'data_date' => $date],
            (object) ['expiration_id' => 1, 'option_type' => 'put', 'strike' => 95, 'open_interest' => 400, 'iv' => .2, 'data_date' => $date],
        ]));
        $this->app->instance(EodSnapshotSelector::class, $selector);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-30 14:01:00', 'UTC');
    }

    private function signIn(bool $entitled = true): void
    {
        $user = (new User)->forceFill(['id' => 987, 'email' => 'review@example.test', 'trial_ends_at' => $entitled ? now()->addDays(2) : null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
    }

    public function test_capture_is_deduplicated_and_changed_inputs_append_with_a_new_comparison(): void
    {
        $tracker = app(IntradayWallTracker::class);
        $first = $tracker->capture('SPY', $this->now());
        $again = $tracker->capture('SPY', $this->now());
        $this->assertSame('recorded', $first['status']);
        $this->assertSame('already_recorded', $again['status']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(2, WallObservation::count()); // One immutable input set, one reading.
        $this->rows(oi: 250);
        $tracker->capture('SPY', $this->now());
        $this->assertSame(4, WallObservation::count());
        $history = $tracker->history('SPY');
        $this->assertCount(2, $history['segments']);
        $this->assertSame('inputs_changed', $history['segments'][1]['start_reason']);
        $this->assertNull($history['segments'][1]['migration']['put']['amount']);
        $this->assertSame('2026-09-30T14:00:00+00:00', $history['segments'][0]['observations'][0]['observed_at']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_new_bucket_reuses_basis_and_does_not_rewrite_old_readings(): void
    {
        $tracker = app(IntradayWallTracker::class);
        $first = $tracker->capture('SPY', $this->now());
        $json = WallObservation::find($first['id'])->payload_json;
        DB::table('underlying_quotes')->update(['asof' => '2026-09-30 14:05:00', 'last_price' => 102]);
        $tracker->capture('SPY', $this->now()->addMinutes(5));
        $this->assertSame(3, WallObservation::count());
        $this->assertSame($json, WallObservation::find($first['id'])->payload_json);
        $this->assertCount(2, $tracker->history('SPY')['segments'][0]['observations']);
        $this->assertArrayNotHasKey('strike_data', $tracker->history('SPY')['segments'][0]['observations'][0]);
        $this->assertArrayHasKey('strike_data', json_decode($json, true));
    }

    public function test_a_revised_price_at_the_same_time_is_preserved_and_not_treated_as_migration(): void
    {
        $tracker = app(IntradayWallTracker::class);
        $first = $tracker->capture('SPY', $this->now());
        DB::table('underlying_quotes')->update(['last_price' => 101]);
        $revision = $tracker->capture('SPY', $this->now());
        $this->assertNotSame($first['id'], $revision['id']);
        $this->assertSame($revision['id'], $tracker->capture('SPY', $this->now())['id']);
        $segments = $tracker->history('SPY')['segments'];
        $this->assertCount(2, $segments);
        $this->assertSame('same_time_revision', $segments[1]['start_reason']);
        $this->assertNull($segments[1]['migration']['put']['amount']);
        $this->assertSame($first['id'], $segments[1]['observations'][0]['revision_of']);
    }

    public function test_model_capture_threshold_cannot_turn_a_small_subset_into_a_whole_chain_reading(): void
    {
        $this->rows(callIv: null);
        $tracker = app(IntradayWallTracker::class);
        $this->assertSame('model_inputs_below_capture_threshold', $tracker->capture('SPY', $this->now())['status']);
        $this->assertSame(0, WallObservation::count());
    }

    public function test_history_keeps_symbols_and_requested_sessions_separate(): void
    {
        $tracker = app(IntradayWallTracker::class);
        $tracker->capture('SPY', $this->now());
        $this->assertCount(1, $tracker->history('SPY', '2026-09-30')['segments']);
        $this->assertSame([], $tracker->history('SPY', '2026-09-29')['segments']);
        $this->assertSame([], $tracker->history('QQQ')['segments']);
    }

    public function test_stale_future_and_ingestion_only_quotes_never_become_observations(): void
    {
        $tracker = app(IntradayWallTracker::class);
        foreach (['2026-09-30 13:30:00', '2026-09-30 14:02:00', '2026-09-29 14:00:00'] as $asof) {
            DB::table('underlying_quotes')->update(['asof' => $asof]);
            $this->assertSame('waiting_for_current_quote', $tracker->capture('SPY', $this->now())['status']);
        }
        DB::table('underlying_quotes')->update(['asof' => '2026-09-30 14:00:00', 'source' => 'massive:ingested-at']);
        $this->assertSame('waiting_for_current_quote', $tracker->capture('SPY', $this->now())['status']);
        $this->assertSame(0, WallObservation::count());
    }

    public function test_delayed_feed_preserves_market_time_and_requires_recent_receipt(): void
    {
        config(['wall_tracking.quote_delay_seconds' => 900]);
        $now = $this->now()->addMinutes(15);
        DB::table('underlying_quotes')->update(['updated_at' => '2026-09-30 14:15:00']);
        $tracker = app(IntradayWallTracker::class);
        $this->assertSame('recorded', $tracker->capture('SPY', $now)['status']);
        $history = $tracker->history('SPY');
        $this->assertSame(900, $history['quote_delay_seconds']);
        $this->assertSame('2026-09-30T14:00:00+00:00', $history['segments'][0]['observations'][0]['observed_at']);
        foreach ([null, '2026-09-30 14:00:00', '2026-09-30 14:17:00'] as $received) {
            DB::table('underlying_quotes')->update(['updated_at' => $received]);
            $this->assertSame('waiting_for_current_quote', $tracker->capture('SPY', $now)['status']);
        }
        DB::table('underlying_quotes')->update(['updated_at' => '2026-09-30 14:15:00', 'asof' => '2026-09-30 13:50:00']);
        $this->assertSame('waiting_for_current_quote', $tracker->capture('SPY', $now)['status']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_delayed_final_session_quotes_can_arrive_after_the_close(): void
    {
        config(['wall_tracking.quote_delay_seconds' => 900]);
        DB::table('underlying_quotes')->update(['asof' => '2026-09-30 19:55:00', 'updated_at' => '2026-09-30 20:10:00']);
        $tracker = app(IntradayWallTracker::class);
        $this->assertSame('recorded', $tracker->capture('SPY', CarbonImmutable::parse('2026-09-30 20:11:00', 'UTC'))['status']);
        $this->assertSame('outside_session', $tracker->capture('SPY', CarbonImmutable::parse('2026-09-30 20:23:00', 'UTC'))['status']);
        DB::table('underlying_quotes')->update(['asof' => '2026-09-30 20:05:00']);
        $this->assertSame('waiting_for_current_quote', $tracker->capture('SPY', CarbonImmutable::parse('2026-09-30 20:11:00', 'UTC'))['status']);
    }

    public function test_old_chain_and_closed_sessions_cannot_fabricate_history(): void
    {
        $tracker = app(IntradayWallTracker::class);
        $this->rows('2026-09-28');
        $this->assertSame('waiting_for_previous_session_chain', $tracker->capture('SPY', $this->now())['status']);
        $this->assertSame('outside_session', $tracker->capture('SPY', CarbonImmutable::parse('2026-11-27 14:00', 'America/New_York'))['status']);
        $this->assertSame('outside_session', $tracker->capture('SPY', CarbonImmutable::parse('2026-12-25 10:00', 'America/New_York'))['status']);
        $this->assertSame(0, WallObservation::count());
    }

    public function test_auth_entitlement_validation_and_production_demo_denial(): void
    {
        $url = '/api/intraday/walls?symbol=SPY';
        $this->getJson($url)->assertUnauthorized();
        $this->signIn(false);
        $this->getJson($url)->assertForbidden();
        $this->signIn();
        $this->getJson('/api/intraday/walls?symbol=bad$')->assertUnprocessable();
        $this->getJson('/api/intraday/walls?symbol=SPY&session=wrong')->assertUnprocessable();
        $this->getJson($url.'&demo=1')->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('segments', [])->assertJsonPath('local_demo_available', false);
        $this->assertSame(0, WallObservation::count());
    }

    public function test_demo_is_local_only_read_only_and_cannot_mix_with_actual_history(): void
    {
        $this->app->instance('env', 'local');
        $this->signIn();
        $this->getJson('/api/intraday/walls?symbol=SPY&demo=1')->assertOk()
            ->assertJsonPath('dataset', 'synthetic_review')->assertJsonCount(8, 'segments.0.observations');
        $this->getJson('/api/intraday/walls?symbol=SPY')->assertOk()->assertJsonCount(0, 'segments');
        $this->assertSame(0, WallObservation::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_scheduler_command_does_no_work_when_disabled(): void
    {
        config(['wall_tracking.enabled' => false]);
        $this->artisan('walls:capture-intraday')->expectsOutput('Intraday wall capture is disabled.')->assertSuccessful();
        $this->assertSame(0, WallObservation::count());
    }
}
