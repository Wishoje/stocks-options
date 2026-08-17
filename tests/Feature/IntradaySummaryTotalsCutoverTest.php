<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;
use Tests\Support\MarketDataScenario;

class IntradaySummaryTotalsCutoverTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York'));
        $scenario = MarketDataScenario::seed();
        $this->actingAs($scenario['user']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_summary_payload_is_identical_before_and_after_canonical_read_cutover(): void
    {
        Config::set('option_live_totals.read_from_canonical', false);
        $legacy = $this->getJson('/api/intraday/summary?symbol=SPY')
            ->assertOk()
            ->json();

        Config::set('option_live_totals.read_from_canonical', true);
        $canonical = $this->getJson('/api/intraday/summary?symbol=SPY')
            ->assertOk()
            ->json();

        $this->assertSame($legacy, $canonical);
        $this->assertSame(
            ['open', 'trade_date', 'asof', 'stale_seconds', 'totals'],
            array_keys($canonical)
        );
        $this->assertSame(
            ['call_vol', 'put_vol', 'total', 'pcr_vol', 'premium'],
            array_keys($canonical['totals'])
        );
        $this->assertSame(MarketDataScenario::DATE, $canonical['trade_date']);
        $this->assertSame(100, $canonical['totals']['call_vol']);
        $this->assertSame(120, $canonical['totals']['put_vol']);
        $this->assertSame(220, $canonical['totals']['total']);
        $this->assertSame(1.2, $canonical['totals']['pcr_vol']);
    }

    public function test_read_flag_switches_only_summary_totals_to_the_canonical_row(): void
    {
        $byStrikeBefore = $this->getJson('/api/intraday/volume-by-strike?symbol=SPY')
            ->assertOk()
            ->json();

        DB::table('option_live_totals')
            ->where('symbol', 'SPY')
            ->whereDate('trade_date', MarketDataScenario::DATE)
            ->update([
                'call_volume' => 8,
                'put_volume' => 13,
                'volume' => 21,
                'premium_usd' => 123.45,
                'asof' => '2026-03-18 20:56:00.000000',
            ]);

        Config::set('option_live_totals.read_from_canonical', false);
        $legacy = $this->getJson('/api/intraday/summary?symbol=SPY')
            ->assertOk()
            ->json();

        Config::set('option_live_totals.read_from_canonical', true);
        $canonical = $this->getJson('/api/intraday/summary?symbol=SPY')
            ->assertOk()
            ->json();
        $byStrikeAfter = $this->getJson('/api/intraday/volume-by-strike?symbol=SPY')
            ->assertOk()
            ->json();

        $this->assertSame(100, $legacy['totals']['call_vol']);
        $this->assertSame(120, $legacy['totals']['put_vol']);
        $this->assertSame(220, $legacy['totals']['total']);

        $this->assertSame(8, $canonical['totals']['call_vol']);
        $this->assertSame(13, $canonical['totals']['put_vol']);
        $this->assertSame(21, $canonical['totals']['total']);
        $this->assertSame(1.625, $canonical['totals']['pcr_vol']);
        $this->assertSame(123.45, $canonical['totals']['premium']);
        $this->assertSame('2026-03-18T20:56:00.000000Z', $canonical['asof']);
        $this->assertSame($byStrikeBefore, $byStrikeAfter);
    }
}
