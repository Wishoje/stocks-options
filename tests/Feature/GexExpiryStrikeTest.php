<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Services\GexExpiryStrikeService;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotSelector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GexExpiryStrikeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-09-30 21:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'eod_publications.read_enabled' => false, 'eod_publications.write_enabled' => false, 'eod_snapshot_health.enabled' => false]);
        DB::purge('sqlite');
        Schema::create('option_expirations', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->date('expiration_date');
        });
        Schema::create('option_chain_data', function (Blueprint $t) {
            $t->id();
            $t->integer('expiration_id');
            $t->date('data_date');
            $t->string('option_type');
            foreach (['strike', 'gamma', 'open_interest', 'underlying_price'] as $f) {
                $t->double($f)->nullable();
            }
        });
        Schema::create('prices_daily', function (Blueprint $t) {
            $t->string('symbol');
            $t->date('trade_date');
            $t->double('close');
        });
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => '2026-09-30', 'close' => 100]);
        DB::table('option_expirations')->insert([
            ['id' => 1, 'symbol' => 'SPY', 'expiration_date' => '2026-10-02'],
            ['id' => 2, 'symbol' => 'SPY', 'expiration_date' => '2026-10-09'],
            ['id' => 3, 'symbol' => 'QQQ', 'expiration_date' => '2026-10-02'],
        ]);
        // Net -6000 and +4000 offset to -2000; shares must use 10000, not 2000.
        foreach ([1 => ['call' => .01, 'put' => .07], 2 => ['call' => .05, 'put' => .01]] as $id => $legs) {
            foreach ($legs as $type => $gamma) {
                DB::table('option_chain_data')->insert(['expiration_id' => $id, 'data_date' => '2026-09-30', 'strike' => 100, 'option_type' => $type, 'gamma' => $gamma, 'open_interest' => 10, 'underlying_price' => 100]);
            }
        }
        $selector = Mockery::mock(EodSnapshotSelector::class);
        $selector->shouldReceive('selectedDatesSubquery')->andReturnUsing(fn ($ids, $anchor) => DB::table('option_chain_data')->whereIn('expiration_id', $ids)->where('data_date', '<=', $anchor)->select('expiration_id')->selectRaw('MAX(data_date) as max_date')->groupBy('expiration_id'));
        $this->app->instance(EodSnapshotSelector::class, $selector);
        Http::preventStrayRequests();
    }

    private function levels(): array
    {
        return ['symbol' => 'SPY', 'timeframe' => '14d', 'data_date' => '2026-09-30', 'expiration_dates' => ['2026-10-02', '2026-10-09'],
            'view_context' => ['view' => 'next_session', 'session_date' => '2026-10-01', 'source_anchor' => '2026-09-30'],
            'strike_data' => [['strike' => 100, 'call_gex' => 600000, 'put_gex' => 800000, 'net_gex' => -200000]]];
    }

    private function signIn(bool $entitled = true): void
    {
        $user = (new User)->forceFill(['id' => 987, 'trial_ends_at' => $entitled ? now()->addDays(2) : null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
    }

    public function test_signed_cells_reconcile_and_absolute_shares_handle_offsetting_exposure(): void
    {
        $result = app(GexExpiryStrikeService::class)->build($this->levels());
        $row = $result['strikes'][0];
        $this->assertEqualsWithDelta(-2000, $row['net_gex'], 1e-8);
        $this->assertEqualsWithDelta(.6, $row['wall_expiry_concentration'], 1e-8);
        $this->assertEqualsWithDelta(.6, $row['expiring_next_ratio'], 1e-8);
        $this->assertSame('2026-10-02', $row['dominant_expiry']);
        $this->assertEqualsWithDelta(100, array_sum(array_column($row['expirations'], 'contribution_pct')), 1e-8);
        $this->assertSame(['2026-10-02'], $result['next_expiry_dates']);
        $this->assertSame('USD per 1% underlying move', $result['unit']);
        $this->assertSame(100.0, $result['reference_price']['value']);
        $this->assertFalse($result['audit']['truncated']);
        $this->assertNull($result['summary']['call_wall']);
        Http::assertNothingSent();
    }

    public function test_zero_exposure_is_preserved_but_zero_denominator_is_not_a_zero_share(): void
    {
        DB::table('option_chain_data')->update(['gamma' => 0]);
        $levels = $this->levels();
        $levels['strike_data'][0] = ['strike' => 100, 'call_gex' => 0, 'put_gex' => 0, 'net_gex' => 0];
        $row = app(GexExpiryStrikeService::class)->build($levels)['strikes'][0];
        $this->assertSame(0.0, $row['net_gex']);
        $this->assertNull($row['wall_expiry_concentration']);
        $this->assertNull($row['expirations'][0]['contribution_pct']);
        $this->assertSame('zero_denominator', $row['concentration_reason']);
    }

    public function test_coverage_gate_keeps_exposure_but_does_not_invent_concentration(): void
    {
        DB::table('option_chain_data')->where('expiration_id', 1)->where('option_type', 'call')->update(['gamma' => null]);
        $levels = $this->levels();
        $levels['strike_data'][0]['call_gex'] = 500000;
        $levels['strike_data'][0]['net_gex'] = -300000;
        $row = app(GexExpiryStrikeService::class)->build($levels)['strikes'][0];
        $this->assertEqualsWithDelta(-3000, $row['net_gex'], 1e-8);
        $this->assertNull($row['wall_expiry_concentration']);
        $this->assertSame('coverage_gate', $row['concentration_reason']);
        $this->assertSame(1, $row['expirations'][0]['excluded_rows']);
    }

    public function test_scope_and_anchor_exclude_other_symbols_expirations_and_later_records(): void
    {
        DB::table('option_chain_data')->insert(['expiration_id' => 3, 'data_date' => '2026-09-30', 'strike' => 100, 'option_type' => 'call', 'gamma' => 1, 'open_interest' => 10, 'underlying_price' => 100]);
        DB::table('option_chain_data')->insert(['expiration_id' => 1, 'data_date' => '2026-10-01', 'strike' => 100, 'option_type' => 'call', 'gamma' => 5, 'open_interest' => 10, 'underlying_price' => 100]);
        $levels = $this->levels();
        $levels['expiration_dates'] = ['2026-10-09'];
        $levels['strike_data'][0] = ['strike' => 100, 'call_gex' => 500000, 'put_gex' => 100000, 'net_gex' => 400000];
        $result = app(GexExpiryStrikeService::class)->build($levels);
        $this->assertCount(1, $result['strikes'][0]['expirations']);
        $this->assertSame(['2026-10-09'], $result['next_expiry_dates']);
        $this->assertSame(1.0, $result['strikes'][0]['expiring_next_ratio']);
    }

    public function test_mixed_dates_keep_dates_but_disable_concentration(): void
    {
        DB::table('option_chain_data')->where('expiration_id', 1)->update(['data_date' => '2026-09-29']);
        $row = app(GexExpiryStrikeService::class)->build($this->levels())['strikes'][0];
        $this->assertSame('2026-09-29', $row['expirations'][0]['data_date']);
        $this->assertNull($row['wall_expiry_concentration']);
        $this->assertSame('mixed_source_dates', $row['concentration_reason']);
    }

    public function test_offsetting_leg_changes_cannot_pass_generation_reconciliation(): void
    {
        DB::table('option_chain_data')->where('expiration_id', 1)->increment('gamma', .02);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(GexExpiryStrikeService::class)->build($this->levels());
    }

    public function test_route_is_protected_and_validates_timeframe(): void
    {
        $this->getJson('/api/gex-expiry-strike?symbol=SPY&timeframe=14d')->assertUnauthorized();
        $this->signIn(false);
        $this->getJson('/api/gex-expiry-strike?symbol=SPY&timeframe=14d')->assertForbidden();
        $this->signIn();
        $this->getJson('/api/gex-expiry-strike?symbol=SPY&timeframe=1000d')->assertUnprocessable();
    }

    public function test_export_uses_same_complete_payload_and_cache_tracks_publication(): void
    {
        $this->signIn();
        $gex = Mockery::mock(GexController::class);
        $gex->shouldReceive('getGexLevels')->andReturnUsing(fn () => response()->json($this->levels()));
        $this->app->instance(GexController::class, $gex);
        $url = '/api/gex-expiry-strike?symbol=SPY&timeframe=14d&view=next_session&session_date=2026-10-01';
        $r = $this->getJson($url)->assertOk()->json();
        $export = app(AiExportBuilder::class)->build(['SPY'], ['gex_expiry_strike'], '14d', ['gex_view' => 'next_session', 'target_session' => '2026-10-01']);
        $this->assertSame($r, $export['items'][0]['gex_expiry_strike']['data']);
        $this->assertSame($r['summary'], $export['items'][0]['summary']['gex_expiry_strike']);
        $this->assertSame('2026-09-30', $export['items'][0]['summary']['data_dates']['gex_expiry_strike']);
        DB::table('option_chain_data')->where('expiration_id', 1)->increment('gamma', .02);
        $this->getJson($url)->assertOk(); // Completed generation remains cached.
        app(EodCacheVersion::class)->publish(['SPY']);
        $this->getJson($url)->assertStatus(409); // New generation revalidates the stored legs.
    }
}
