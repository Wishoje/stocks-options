<?php

namespace Tests\Feature\Regression;

use App\Support\Regression\MarketDataBaseline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\MySqlTestCase;
use Tests\Support\MarketDataScenario;

class MarketDataApiContractTest extends MySqlTestCase
{
    use RefreshDatabase;

    private array $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York'));
        Queue::fake();
        Http::preventStrayRequests();
        $this->scenario = MarketDataScenario::seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_gex_contract_is_stable_for_every_frontend_timeframe(): void
    {
        $payloads = [];
        $expectedRootKeys = [
            'available_timeframes',
            'call_interest_percentage',
            'call_open_interest_total',
            'call_resistance',
            'call_volume_total',
            'call_wall_2',
            'call_wall_3',
            'data_age_days',
            'data_date',
            'date_prev',
            'date_prev_gap_trading_days',
            'date_prev_is_stale',
            'date_prev_week',
            'date_prev_week_gap_trading_days',
            'expiration_dates',
            'gamma_sign',
            'hvl',
            'pcr_volume',
            'put_interest_percentage',
            'put_open_interest_total',
            'put_support',
            'put_volume_total',
            'put_wall_2',
            'put_wall_3',
            'regime_source_meta',
            'regime_strength',
            'strike_data',
            'symbol',
            'timeframe',
            'timeframe_expirations',
            'total_oi_delta',
            'total_volume_delta',
        ];

        foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
            $response = $this->getJson('/api/gex-levels?symbol=SPY&timeframe='.$timeframe.'&refresh=1');
            $response->assertOk();
            $payload = $response->json();

            $this->assertRootKeys($expectedRootKeys, $payload);
            $this->assertSame('SPY', $payload['symbol']);
            $this->assertSame($timeframe, $payload['timeframe']);
            $this->assertSame(MarketDataScenario::DATE, $payload['data_date']);
            $this->assertNotEmpty($payload['expiration_dates']);
            $this->assertNotEmpty($payload['strike_data']);
            $this->assertGreaterThan(0, $payload['call_open_interest_total']);
            $this->assertGreaterThan(0, $payload['put_open_interest_total']);

            $payloads['gex_spy_'.$timeframe] = $payload;
        }

        $artifact = app(MarketDataBaseline::class)->capture(
            MarketDataScenario::SYMBOLS,
            MarketDataScenario::DATE,
            $payloads
        );

        $this->assertCount(6, $artifact['api']);
    }

    public function test_calculator_contract_covers_complete_partial_history_and_empty_symbol_states(): void
    {
        $response = $this->getJson('/api/option-chain?symbol=SPY');
        $response->assertOk();
        $payload = $response->json();

        $this->assertRootKeys([
            'chain',
            'expirations',
            'fetch_meta',
            'health',
            'refresh_queued',
            'requested_expiry',
            'snapshot_at',
            'snapshot_stats',
            'status',
            'underlying',
        ], $payload);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(42, count($payload['chain']));
        $this->assertSame(6, count($payload['expirations']));
        $this->assertSame(MarketDataScenario::expirationDates()[0], $payload['requested_expiry']);
        $this->assertFalse($payload['health']['is_partial']);

        $cold = $this->getJson('/api/option-chain?symbol=COLD');
        $cold->assertStatus(202);
        $this->assertRootKeys([
            'chain',
            'expirations',
            'fetch_meta',
            'refresh_queued',
            'status',
            'underlying',
        ], $cold->json());
        $cold->assertJsonPath('status', 'no_snapshot');
        $cold->assertJsonCount(0, 'chain');
        $cold->assertJsonCount(0, 'expirations');

        $artifact = app(MarketDataBaseline::class)->capture(
            MarketDataScenario::SYMBOLS,
            MarketDataScenario::DATE,
            [
                'calculator_spy' => $payload,
                'calculator_cold' => $cold->json(),
            ]
        );
        $expiry = MarketDataScenario::expirationDates()[0];
        $this->assertTrue(data_get($artifact, "calculator.QQQ.expirations.{$expiry}.latest_is_partial"));
        $this->assertFalse(data_get($artifact, "calculator.SPY.expirations.{$expiry}.latest_is_partial"));
    }

    public function test_intraday_watchlist_and_derived_endpoint_contracts_are_stable(): void
    {
        $summary = $this->getJson('/api/intraday/summary?symbol=SPY');
        $summary->assertOk();
        $this->assertRootKeys(['asof', 'open', 'stale_seconds', 'totals', 'trade_date'], $summary->json());
        $summary->assertJsonPath('trade_date', MarketDataScenario::DATE);
        $summary->assertJsonPath('totals.call_vol', 100);
        $summary->assertJsonPath('totals.put_vol', 120);
        $summary->assertJsonPath('totals.total', 220);

        $byStrike = $this->getJson('/api/intraday/volume-by-strike?symbol=SPY');
        $byStrike->assertOk()->assertJsonCount(2, 'items');
        $this->assertRootKeys(['items'], $byStrike->json());
        $this->assertRootKeys(['call_vol', 'put_vol', 'strike'], $byStrike->json('items.0'));

        $watchlist = $this->actingAs($this->scenario['user'])->getJson('/api/watchlist');
        $watchlist->assertOk()->assertJsonCount(3);
        $this->assertSame(['AAPL', 'COLD', 'SPY'], collect($watchlist->json())->pluck('symbol')->all());
        foreach ($watchlist->json() as $item) {
            $this->assertRootKeys(['id', 'symbol'], $item);
        }

        $ua = $this->getJson('/api/ua?symbol=SPY&with_premium=false&min_z=0&min_vol_oi=0');
        $ua->assertOk();
        $this->assertRootKeys(['data_date', 'items', 'symbol'], $ua->json());
        $ua->assertJsonPath('symbol', 'SPY')->assertJsonCount(1, 'items');
        $this->assertRootKeys(['exp_date', 'meta', 'strike', 'vol_oi', 'z_score'], $ua->json('items.0'));

        $pressure = $this->getJson('/api/expiry-pressure?symbol=SPY&days=3');
        $pressure->assertOk();
        $this->assertRootKeys(['data_date', 'entries', 'headline_pin', 'symbol'], $pressure->json());
        $pressure->assertJsonPath('symbol', 'SPY');
        $this->assertNotEmpty($pressure->json('entries'));

        $dex = $this->getJson('/api/dex?symbol=SPY&days_ahead=90&days_back=30');
        $dex->assertOk();
        $this->assertRootKeys([
            'by_expiry',
            'data_date',
            'gamma_sign',
            'regime_source_meta',
            'regime_strength',
            'symbol',
            'today',
            'total',
            'window',
        ], $dex->json());
        $dex->assertJsonPath('symbol', 'SPY');
        $this->assertNotEmpty($dex->json('by_expiry'));

        $artifact = app(MarketDataBaseline::class)->capture(
            MarketDataScenario::SYMBOLS,
            MarketDataScenario::DATE,
            [
                'intraday_summary_spy' => $summary->json(),
                'intraday_by_strike_spy' => $byStrike->json(),
                'watchlist' => $watchlist->json(),
                'ua_spy' => $ua->json(),
                'expiry_pressure_spy' => $pressure->json(),
                'dex_spy' => $dex->json(),
            ]
        );

        $this->assertCount(6, $artifact['api']);
    }

    /**
     * @param  array<int,string>  $expected
     * @param  array<string,mixed>  $payload
     */
    private function assertRootKeys(array $expected, array $payload): void
    {
        sort($expected, SORT_STRING);
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);

        $this->assertSame($expected, $actual);
    }
}
