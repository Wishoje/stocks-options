<?php

namespace Tests\Feature;

use App\Support\IntradayCompositeCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;
use Tests\Support\MarketDataScenario;

class IntradayStrikesCacheTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York'));
        Cache::flush();
        MarketDataScenario::seed();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_deploy_preserves_a_warm_legacy_payload_without_a_blocking_rebuild(): void
    {
        $cacheKey = $this->cacheKey();
        $legacy = [
            'open' => false,
            'asof' => '2026-03-18 20:55:00',
            'stale_seconds' => 300,
            'totals' => [
                'call_vol' => 123,
                'put_vol' => 456,
                'pcr_vol' => 3.707,
                'premium' => 789,
            ],
            'items' => [['strike' => 100]],
        ];
        Cache::put($cacheKey, $legacy, 90);

        $response = $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk();

        $this->assertSame($legacy, $response->json());
        $this->assertSame($legacy, Cache::get($cacheKey));
        $this->assertSame(
            now()->getTimestamp(),
            Cache::get(IntradayCompositeCache::createdKey($cacheKey))
        );
    }

    public function test_market_hours_serves_stale_immediately_and_only_the_lock_owner_refreshes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-18 15:00:00', 'America/New_York'));
        Cache::flush();

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);

        $this->setSpyCallVolumePerStrike(100);
        Carbon::setTestNow(Carbon::parse('2026-03-18 15:01:31', 'America/New_York'));

        $cacheKey = $this->cacheKey();
        $refreshLock = Cache::lock(
            "illuminate:cache:flexible:lock:{$cacheKey}",
            300
        );
        $this->assertTrue($refreshLock->get());

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);
        $this->assertSame(100, Cache::get($cacheKey)['totals']['call_vol']);

        $refreshLock->release();

        // The stale response is returned before Laravel executes the deferred
        // rebuild during response termination.
        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);
        $this->assertSame(200, Cache::get($cacheKey)['totals']['call_vol']);

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 200);
    }

    public function test_closed_market_payload_stays_fresh_for_fifteen_minutes_then_refreshes_deferred(): void
    {
        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);

        $this->setSpyCallVolumePerStrike(100);
        Carbon::setTestNow(Carbon::parse(MarketDataScenario::NOW, 'America/New_York')->addMinutes(5));

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);
        $this->assertSame(100, Cache::get($this->cacheKey())['totals']['call_vol']);

        Carbon::setTestNow(
            Carbon::parse(MarketDataScenario::NOW, 'America/New_York')->addSeconds(901)
        );

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 100);
        $this->assertSame(200, Cache::get($this->cacheKey())['totals']['call_vol']);

        $this->setSpyCallVolumePerStrike(150);
        Carbon::setTestNow(Carbon::parse('2026-03-23 08:00:00', 'America/New_York'));

        $refreshLock = Cache::lock(
            "illuminate:cache:flexible:lock:{$this->cacheKey()}",
            300
        );
        $this->assertTrue($refreshLock->get());

        // The seven-day stale window bridges a weekend, so Monday pre-open
        // still serves the last completed payload instead of cold rebuilding.
        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 200);

        $refreshLock->release();
    }

    public function test_eod_only_response_is_not_cached_and_new_live_rows_are_visible_next_poll(): void
    {
        DB::table('option_live_counters')
            ->where('symbol', 'SPY')
            ->delete();

        $first = $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('asof', null)
            ->json();

        $this->assertNotEmpty($first['items']);
        $this->assertFalse(Cache::has($this->cacheKey()));
        $this->assertFalse(Cache::has(
            IntradayCompositeCache::createdKey($this->cacheKey())
        ));

        DB::table('option_live_counters')->insert([
            'symbol' => 'SPY',
            'trade_date' => MarketDataScenario::DATE,
            'exp_date' => MarketDataScenario::expirationDates()[0],
            'strike' => 100,
            'option_type' => 'call',
            'volume' => 77,
            'premium_usd' => 1234.0,
            'asof' => '2026-03-18 21:00:00',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->getJson('/api/intraday/strikes?symbol=SPY')
            ->assertOk()
            ->assertJsonPath('totals.call_vol', 77)
            ->assertJsonPath('asof', '2026-03-18 21:00:00');

        $this->assertTrue(Cache::has($this->cacheKey()));
    }

    public function test_successful_publication_marks_a_complete_payload_stale_without_deleting_it(): void
    {
        $cacheKey = $this->cacheKey();
        $payload = ['asof' => '2026-03-18 20:55:00', 'items' => [['strike' => 100]]];
        Cache::put($cacheKey, $payload, 3600);
        Cache::put(IntradayCompositeCache::createdKey($cacheKey), now()->timestamp, 3600);
        Cache::put(
            'intraday:resolvedTradeDate:SPY:'.MarketDataScenario::DATE,
            MarketDataScenario::DATE,
            3600
        );
        Cache::put(
            'intraday:repricedGex:SPY:'.MarketDataScenario::DATE,
            ['items' => [['strike' => 100]]],
            3600
        );

        IntradayCompositeCache::markPublished('SPY', MarketDataScenario::DATE);

        $this->assertSame($payload, Cache::get($cacheKey));
        $this->assertSame(0, Cache::get(IntradayCompositeCache::createdKey($cacheKey)));
        $this->assertFalse(Cache::has(
            'intraday:resolvedTradeDate:SPY:'.MarketDataScenario::DATE
        ));
        $this->assertFalse(Cache::has(
            'intraday:repricedGex:SPY:'.MarketDataScenario::DATE
        ));
    }

    private function cacheKey(): string
    {
        return IntradayCompositeCache::key('SPY', MarketDataScenario::DATE);
    }

    private function setSpyCallVolumePerStrike(int $volume): void
    {
        DB::table('option_live_counters')
            ->where('symbol', 'SPY')
            ->where('trade_date', MarketDataScenario::DATE)
            ->whereNotNull('strike')
            ->where('option_type', 'call')
            ->update([
                'volume' => $volume,
                'updated_at' => now('UTC'),
            ]);
    }
}
