<?php

namespace Tests\Feature;

use App\Http\Controllers\IntradayVolatilityController;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Services\IntradayVolatilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntradayVolatilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-02 21:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Cache::flush();
        Schema::create('intraday_option_volumes', function (Blueprint $t) {
            $t->id();
            foreach (['symbol', 'contract_symbol', 'contract_type', 'expiration_date', 'captured_at'] as $key) {
                $t->string($key);
            }
            foreach (['strike_price', 'delta', 'implied_volatility'] as $key) {
                $t->double($key)->nullable();
            }
        });
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function sample(float $add = 0): array
    {
        $rows = [];
        foreach (['call' => 0, 'put' => .03] as $side => $spread) {
            foreach ([.2, .3, .45, .55] as $i => $delta) {
                $rows[] = ['contract_symbol' => $side.$i, 'contract_type' => $side, 'strike_price' => 100 + $i,
                    'delta' => $side === 'call' ? $delta : -$delta, 'implied_volatility' => .20 + $spread + $delta / 10 + $add];
            }
        }

        return $rows;
    }

    private function seedCapture(string $time = '2026-10-02 14:00:00', string $expiry = '2026-10-09', string $symbol = 'SPY', float $add = 0): void
    {
        DB::table('intraday_option_volumes')->insert(array_map(fn ($row) => $row + ['symbol' => $symbol, 'expiration_date' => $expiry, 'captured_at' => $time], $this->sample($add)));
    }

    public function test_interpolates_consistent_delta_targets_and_uses_percentage_points(): void
    {
        $result = app(IntradayVolatilityService::class)->calculate($this->sample());
        $this->assertEqualsWithDelta(22.5, $result['call_iv'], 1e-7);
        $this->assertEqualsWithDelta(25.5, $result['put_iv'], 1e-7);
        $this->assertEqualsWithDelta(26.5, $result['iv'], 1e-7);
        $this->assertEqualsWithDelta(3, $result['skew'], 1e-7);
        $this->assertCount(2, $result['inputs']['call_25']['contracts']);
        $this->assertSame('interpolated', $result['inputs']['call_25']['status']);
    }

    public function test_rejects_invalid_sides_wide_brackets_and_extrapolation_instead_of_inventing_zero(): void
    {
        $rows = $this->sample();
        foreach ($rows as &$row) {
            if ($row['contract_type'] === 'put') {
                $row['delta'] *= -1;
            }
        }
        unset($row);
        $result = app(IntradayVolatilityService::class)->calculate($rows);
        $this->assertNull($result['iv']);
        $this->assertNull($result['skew']);
        $this->assertNotNull($result['call_iv']);
        $rows = [['contract_symbol' => 'a', 'contract_type' => 'call', 'strike_price' => 100, 'delta' => .10, 'implied_volatility' => .2],
            ['contract_symbol' => 'b', 'contract_type' => 'call', 'strike_price' => 101, 'delta' => .5, 'implied_volatility' => .3]];
        $this->assertNull(app(IntradayVolatilityService::class)->calculate($rows)['call_iv']);
        $this->assertNull(app(IntradayVolatilityService::class)->calculate([$rows[1]])['call_iv']);
        $rows[0]['delta'] = .25;
        $this->assertSame('exact', app(IntradayVolatilityService::class)->calculate($rows)['inputs']['call_25']['status']);
        $rows[0]['implied_volatility'] = INF;
        $this->assertNull(app(IntradayVolatilityService::class)->calculate($rows)['call_iv']);
    }

    public function test_uses_first_comparable_baseline_and_never_carries_values_into_later_empty_reading(): void
    {
        $this->seedCapture();
        $this->seedCapture('2026-10-02 14:10:00', add: .02);
        DB::table('intraday_option_volumes')->where('captured_at', '2026-10-02 14:00:00')->where('contract_type', 'put')->update(['delta' => null]);
        $this->seedCapture('2026-10-02 14:20:00', add: .04);
        $this->seedCapture('2026-10-02 14:30:00');
        DB::table('intraday_option_volumes')->where('captured_at', '2026-10-02 14:30:00')->update(['implied_volatility' => null]);
        $result = app(IntradayVolatilityService::class)->build('SPY');
        $this->assertCount(4, $result['readings']);
        $this->assertNull($result['readings'][0]['iv']);
        $this->assertSame(0.0, $result['readings'][1]['iv_change']);
        $this->assertSame(2.0, $result['readings'][2]['iv_change']);
        $this->assertSame(0.0, $result['readings'][2]['skew_change']);
        $this->assertNull($result['summary']['iv']);
        $this->assertSame('2026-10-02T14:10:00+00:00', $result['summary']['iv_baseline_at']);
    }

    public function test_expiration_session_and_symbol_are_isolated_with_bounded_queries(): void
    {
        $this->seedCapture();
        $this->seedCapture('2026-10-02 14:05:00', '2026-10-02', add: .8);
        $this->seedCapture('2026-10-02 14:10:00', symbol: 'QQQ', add: .9);
        $this->seedCapture('2026-10-01 14:00:00', add: .7);
        DB::enableQueryLog();
        $result = app(IntradayVolatilityService::class)->build('SPY');
        $this->assertCount(4, DB::getQueryLog());
        $this->assertSame('2026-10-09', $result['expiry']);
        $this->assertCount(1, $result['readings']);
        $this->assertEquals(26.5, $result['summary']['iv']);
        $other = app(IntradayVolatilityService::class)->build('SPY', '2026-10-02', '2026-10-02');
        $this->assertEquals(106.5, $other['summary']['iv']);
        $this->assertSame('collection_time', $result['timestamp_basis']);
        $this->assertNull($result['source_observed_at']);
        $this->assertSame([], app(IntradayVolatilityService::class)->build('SPY', '2026-10-02', '2026-11-01')['readings']);
        Bus::assertNothingDispatched();
    }

    public function test_session_hours_and_settling_cutoff_exclude_overnight_and_in_progress_captures(): void
    {
        $this->seedCapture('2026-10-02 12:00:00');
        $this->seedCapture('2026-10-02 13:35:00');
        $this->seedCapture('2026-10-02 14:00:00');
        $this->seedCapture('2026-10-02 20:30:00');
        $result = app(IntradayVolatilityService::class)->build('SPY');
        $this->assertCount(1, $result['readings']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-02 14:00:30', 'UTC'));
        $this->assertCount(0, app(IntradayVolatilityService::class)->build('SPY')['readings']);
        $this->assertCount(0, app(IntradayVolatilityService::class)->build('SPY', '2026-10-03')['readings']);
    }

    public function test_empty_symbol_returns_a_clear_empty_contract(): void
    {
        $result = app(IntradayVolatilityService::class)->build('NONE');
        $this->assertNull($result['session']);
        $this->assertNull($result['summary']);
        $this->assertSame('no_readings', $result['status']);
    }

    public function test_endpoint_requires_intraday_access_and_validates_dates(): void
    {
        $this->getJson('/api/intraday/volatility?symbol=SPY')->assertUnauthorized();
        $user = (new User)->forceFill(['id' => 789, 'trial_ends_at' => null]);
        $user->setRelation('subscriptions', collect());
        Sanctum::actingAs($user);
        $this->getJson('/api/intraday/volatility?symbol=SPY')->assertForbidden();
        $user->trial_ends_at = now()->addDay();
        $this->getJson('/api/intraday/volatility?symbol=SPY&session=2026-10-03')->assertUnprocessable();
        $this->getJson('/api/intraday/volatility?symbol=SPY&expiry=bad')->assertUnprocessable();
        $this->getJson('/api/intraday/volatility?symbol=SPY')->assertOk()->assertJsonPath('status', 'no_readings');
    }

    public function test_cached_endpoint_and_ai_export_share_exact_values_without_provider_work(): void
    {
        $this->seedCapture();
        $request = Request::create('/api/intraday/volatility', 'GET', ['symbol' => 'SPY']);
        $data = app(IntradayVolatilityController::class)->show($request)->getData(true);
        DB::enableQueryLog();
        $again = app(IntradayVolatilityController::class)->show($request)->getData(true);
        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame($data, $again);
        $export = app(AiExportBuilder::class)->build(['SPY'], ['intraday_volatility'], '14d');
        $this->assertSame($data, $export['items'][0]['intraday_volatility']['data']);
        $this->assertSame($data['summary'], $export['items'][0]['summary']['intraday_volatility']['latest']);
        Bus::assertNothingDispatched();
    }
}
