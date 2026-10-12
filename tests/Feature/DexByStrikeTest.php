<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Services\DexByStrikeService;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotSelector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DexByStrikeTest extends TestCase
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
            foreach (['strike', 'delta', 'gamma', 'open_interest', 'underlying_price'] as $f) {
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
        foreach ([1 => ['call' => [.01, .6], 'put' => [.07, -.8]], 2 => ['call' => [.05, .2], 'put' => [.01, -.4]]] as $id => $legs) {
            foreach ($legs as $type => [$gamma, $delta]) {
                DB::table('option_chain_data')->insert(['expiration_id' => $id, 'data_date' => '2026-09-30', 'strike' => 100, 'option_type' => $type, 'gamma' => $gamma, 'delta' => $delta, 'open_interest' => 10, 'underlying_price' => 100]);
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

    public function test_signed_delta_is_summed_once_and_gex_keeps_its_own_units(): void
    {
        DB::enableQueryLog();
        $result = app(DexByStrikeService::class)->build($this->levels());
        $this->assertCount(3, DB::getQueryLog());
        $row = $result['strikes'][0];
        $this->assertSame(800.0, $row['call_dex']);
        $this->assertSame(-1200.0, $row['put_dex']);
        $this->assertSame(-400.0, $row['net_dex']);
        $this->assertEqualsWithDelta(-2000.0, $row['net_gex'], 1e-8);
        $this->assertSame(-400.0, $result['summary']['net_dex']);
        $this->assertSame('share equivalents', $result['dex_unit']);
        $this->assertSame('USD per 1% underlying move', $result['gex_unit']);
        $this->assertNull($result['pressure_direction']);
        $this->assertSame('scenario_model_required', $result['pressure_status']);
        $this->assertFalse($result['audit']['historical_outcome_eligible']);
        Http::assertNothingSent();
    }

    public function test_extrema_and_totals_use_all_strikes_and_do_not_call_delta_levels_walls(): void
    {
        DB::table('option_chain_data')->insert([
            ['expiration_id' => 1, 'data_date' => '2026-09-30', 'strike' => 110, 'option_type' => 'call', 'gamma' => .03, 'delta' => .8, 'open_interest' => 100, 'underlying_price' => 100],
            ['expiration_id' => 2, 'data_date' => '2026-09-30', 'strike' => 90, 'option_type' => 'put', 'gamma' => .02, 'delta' => -.8, 'open_interest' => 200, 'underlying_price' => 100],
        ]);
        $levels = $this->levels();
        $levels['strike_data'][] = ['strike' => 110, 'call_gex' => 3000000, 'put_gex' => 0, 'net_gex' => 3000000];
        $levels['strike_data'][] = ['strike' => 90, 'call_gex' => 0, 'put_gex' => 4000000, 'net_gex' => -4000000];
        $result = app(DexByStrikeService::class)->build($levels);
        $this->assertSame([90.0, 100.0, 110.0], array_column($result['strikes'], 'strike'));
        $this->assertSame(['strike' => 110.0, 'net_dex' => 8000.0], $result['summary']['largest_positive']);
        $this->assertSame(['strike' => 90.0, 'net_dex' => -16000.0], $result['summary']['largest_negative']);
        $this->assertSame(-8400.0, $result['summary']['net_dex']);
        $this->assertSame(0.0, $result['strikes'][0]['call_dex']);
        $this->assertArrayNotHasKey('put_wall', $result['summary']);
    }

    public function test_zero_delta_and_zero_oi_are_real_zero_exposure(): void
    {
        DB::table('option_chain_data')->update(['delta' => 0]);
        $result = app(DexByStrikeService::class)->build($this->levels());
        $this->assertSame(0.0, $result['summary']['net_dex']);
        $this->assertNull($result['summary']['largest_positive']);
        $this->assertNull($result['summary']['largest_negative']);
        DB::table('option_chain_data')->update(['open_interest' => 0, 'delta' => null]);
        $levels = $this->levels();
        $levels['strike_data'][0] = ['strike' => 100, 'call_gex' => 0, 'put_gex' => 0, 'net_gex' => 0];
        $result = app(DexByStrikeService::class)->build($levels);
        $this->assertSame(0.0, $result['summary']['net_dex']);
        $this->assertSame(0, $result['strikes'][0]['audit']['excluded_rows']);
    }

    public function test_absent_delta_stays_null_and_is_not_reconstructed_from_gamma(): void
    {
        DB::table('option_chain_data')->update(['delta' => null]);
        $result = app(DexByStrikeService::class)->build($this->levels());
        foreach (['net_dex', 'call_dex', 'put_dex'] as $field) {
            $this->assertNull($result['strikes'][0][$field]);
            $this->assertNull($result['summary'][$field]);
        }
        $this->assertEqualsWithDelta(-2000.0, $result['strikes'][0]['net_gex'], 1e-8);
        $this->assertSame('unavailable', $result['strikes'][0]['audit']['calculation_status']);
    }

    public function test_invalid_signed_deltas_are_excluded_without_silently_changing_their_sign(): void
    {
        DB::table('option_chain_data')->where('option_type', 'call')->update(['delta' => -.4]);
        DB::table('option_chain_data')->where('option_type', 'put')->update(['delta' => .7]);
        $result = app(DexByStrikeService::class)->build($this->levels());
        $this->assertNull($result['summary']['net_dex']);
        DB::table('option_chain_data')->where('option_type', 'call')->update(['delta' => 1.01]);
        DB::table('option_chain_data')->where('option_type', 'put')->update(['delta' => -1.01]);
        $this->assertNull(app(DexByStrikeService::class)->build($this->levels())['summary']['net_dex']);
    }

    public function test_available_input_estimates_preserve_audit_counts_and_wholly_unavailable_legs(): void
    {
        DB::table('option_chain_data')->where('option_type', 'call')->update(['delta' => null]);
        $row = app(DexByStrikeService::class)->build($this->levels())['strikes'][0];
        $this->assertNull($row['call_dex']);
        $this->assertSame(-1200.0, $row['put_dex']);
        $this->assertSame(-1200.0, $row['net_dex']);
        $this->assertSame(2, $row['audit']['excluded_rows']);
        $this->assertSame('available_inputs_only', $row['audit']['calculation_status']);
    }

    public function test_gamma_is_not_required_for_delta_exposure(): void
    {
        DB::table('option_chain_data')->update(['gamma' => null]);
        $levels = $this->levels();
        $levels['strike_data'][0] = ['strike' => 100, 'call_gex' => 0, 'put_gex' => 0, 'net_gex' => 0];
        $this->assertSame(-400.0, app(DexByStrikeService::class)->build($levels)['summary']['net_dex']);
    }

    public function test_scope_anchor_and_source_dates_follow_the_dashboard(): void
    {
        DB::table('option_chain_data')->insert([
            ['expiration_id' => 3, 'data_date' => '2026-09-30', 'strike' => 100, 'option_type' => 'call', 'gamma' => 1, 'delta' => 1, 'open_interest' => 10000, 'underlying_price' => 100],
            ['expiration_id' => 2, 'data_date' => '2026-10-01', 'strike' => 100, 'option_type' => 'call', 'gamma' => 1, 'delta' => 1, 'open_interest' => 10000, 'underlying_price' => 100],
        ]);
        DB::table('option_chain_data')->where('expiration_id', 2)->where('data_date', '2026-09-30')->update(['data_date' => '2026-09-29']);
        $levels = $this->levels();
        $levels['expiration_dates'] = ['2026-10-09'];
        $levels['strike_data'][0] = ['strike' => 100, 'call_gex' => 500000, 'put_gex' => 100000, 'net_gex' => 400000];
        $result = app(DexByStrikeService::class)->build($levels);
        $this->assertSame(-200.0, $result['summary']['net_dex']);
        $this->assertSame(['2026-10-09' => '2026-09-29'], $result['audit']['source_dates_by_expiry']);
        $this->assertSame($levels['view_context'], $result['view_context']);
        $this->assertFalse($result['reference_price']['live']);
    }

    public function test_generation_mismatch_in_offsetting_gex_legs_is_rejected(): void
    {
        DB::table('option_chain_data')->where('expiration_id', 1)->increment('gamma', .02);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DexByStrikeService::class)->build($this->levels());
    }

    public function test_route_requires_product_access_and_validates_scope(): void
    {
        $this->getJson('/api/dex-by-strike?symbol=SPY&timeframe=14d')->assertUnauthorized();
        $this->signIn(false);
        $this->getJson('/api/dex-by-strike?symbol=SPY&timeframe=14d')->assertForbidden();
        $this->signIn();
        $this->getJson('/api/dex-by-strike?symbol=SPY&timeframe=1000d')->assertUnprocessable();
        $this->getJson('/api/dex-by-strike?symbol=SPY&timeframe=14d&view=intraday')->assertUnprocessable();
    }

    public function test_api_and_export_are_identical_and_delta_only_publications_refresh_the_cache(): void
    {
        $this->signIn();
        $gex = Mockery::mock(GexController::class);
        $gex->shouldReceive('getGexLevels')->andReturnUsing(fn () => response()->json($this->levels()));
        $this->app->instance(GexController::class, $gex);
        $url = '/api/dex-by-strike?symbol=SPY&timeframe=14d&view=next_session&session_date=2026-10-01';
        $result = $this->getJson($url)->assertOk()->json();
        $export = app(AiExportBuilder::class)->build(['SPY'], ['dex_by_strike'], '14d', ['gex_view' => 'next_session', 'target_session' => '2026-10-01']);
        $this->assertSame($result, $export['items'][0]['dex_by_strike']['data']);
        $this->assertSame($result['summary'], $export['items'][0]['summary']['dex_by_strike']);
        $this->assertSame('2026-09-30', $export['items'][0]['summary']['data_dates']['dex_by_strike']);
        DB::table('option_chain_data')->where('option_type', 'call')->update(['delta' => .9]);
        $this->assertSame($result, $this->getJson($url)->assertOk()->json());
        app(EodCacheVersion::class)->publish(['SPY']);
        $updated = $this->getJson($url)->assertOk()->json();
        $this->assertSame(600, $updated['summary']['net_dex']);
        Http::assertNothingSent();
    }
}
