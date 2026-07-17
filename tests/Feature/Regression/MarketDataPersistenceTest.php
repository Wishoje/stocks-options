<?php

namespace Tests\Feature\Regression;

use App\Services\IntradayOptionVolumeIngestor;
use App\Support\Regression\BaselineComparator;
use App\Support\Regression\MarketDataBaseline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;
use Tests\Support\MarketDataScenario;

class MarketDataPersistenceTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York'));
        MarketDataScenario::seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_snapshot_command_persists_the_exact_chain_aggregates(): void
    {
        $expiry = MarketDataScenario::expirationDates()[0];
        $expirationId = (int) DB::table('option_expirations')
            ->where('symbol', 'SPY')
            ->whereDate('expiration_date', $expiry)
            ->value('id');

        $expected = DB::table('option_chain_data')
            ->where('expiration_id', $expirationId)
            ->whereDate('data_date', MarketDataScenario::DATE)
            ->selectRaw("SUM(CASE WHEN option_type='call' THEN open_interest ELSE 0 END) as call_oi")
            ->selectRaw("SUM(CASE WHEN option_type='put' THEN open_interest ELSE 0 END) as put_oi")
            ->selectRaw("SUM(CASE WHEN option_type='call' THEN volume ELSE 0 END) as call_vol")
            ->selectRaw("SUM(CASE WHEN option_type='put' THEN volume ELSE 0 END) as put_vol")
            ->first();

        $this->artisan('chain:snapshot', ['date' => MarketDataScenario::DATE])
            ->assertSuccessful();

        $actual = DB::table('daily_chain_snapshot')
            ->where('symbol', 'SPY')
            ->whereDate('data_date', MarketDataScenario::DATE)
            ->where('expiration_id', $expirationId)
            ->first();

        $this->assertNotNull($actual);
        $this->assertSame((int) $expected->call_oi, (int) $actual->call_oi);
        $this->assertSame((int) $expected->put_oi, (int) $actual->put_oi);
        $this->assertSame((int) $expected->call_vol, (int) $actual->call_vol);
        $this->assertSame((int) $expected->put_vol, (int) $actual->put_vol);
    }

    public function test_intraday_contract_ingestion_is_idempotent_for_its_mysql_natural_key(): void
    {
        $capturedAt = Carbon::parse('2026-03-18 20:59:00', 'UTC');
        $payload = $this->intradayContract(75);
        $ingestor = app(IntradayOptionVolumeIngestor::class);

        $ingestor->ingest($payload, 'regression-idempotency', $capturedAt);
        $payload['day']['volume'] = 99;
        $ingestor->ingest($payload, 'regression-idempotency', $capturedAt);

        $query = DB::table('intraday_option_volumes')
            ->where('contract_symbol', 'O:SPY260318C00101000')
            ->where('captured_at', $capturedAt);

        $this->assertSame(1, $query->count());
        $this->assertSame(99, (int) $query->value('volume'));
    }

    public function test_baseline_exposes_duplicate_nullable_totals_instead_of_hiding_them(): void
    {
        $service = app(MarketDataBaseline::class);
        $baseline = $service->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);

        DB::table('option_live_counters')->insert([
            'symbol' => 'SPY',
            'trade_date' => MarketDataScenario::DATE,
            'exp_date' => null,
            'strike' => null,
            'option_type' => null,
            'volume' => 999,
            'premium_usd' => 9999,
            'asof' => '2026-03-18 20:56:00',
            'created_at' => '2026-03-18 20:56:00',
            'updated_at' => '2026-03-18 20:56:00',
        ]);

        $candidate = $service->capture(MarketDataScenario::SYMBOLS, MarketDataScenario::DATE);

        $this->assertSame(1, data_get($candidate, 'tables.option_live_counters.duplicate_natural_key_count'));
        $this->assertSame(1, data_get($candidate, 'tables.option_live_counters.duplicate_row_count'));
        $this->assertFalse((new BaselineComparator())->compare($baseline, $candidate)['matches']);
    }

    /** @return array<string,mixed> */
    private function intradayContract(int $volume): array
    {
        return [
            'underlying_asset' => ['ticker' => 'SPY'],
            'details' => [
                'ticker' => 'O:SPY260318C00101000',
                'contract_type' => 'call',
                'expiration_date' => MarketDataScenario::DATE,
                'strike_price' => 101,
            ],
            'day' => [
                'volume' => $volume,
                'close' => 3.25,
                'change' => 0.10,
                'change_percent' => 3.17,
            ],
            'open_interest' => 500,
            'implied_volatility' => 0.25,
            'greeks' => [
                'delta' => 0.52,
                'gamma' => 0.01,
                'theta' => -0.02,
                'vega' => 0.20,
            ],
        ];
    }
}
