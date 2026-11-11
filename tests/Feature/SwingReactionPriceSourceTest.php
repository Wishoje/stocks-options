<?php

namespace Tests\Feature;

use App\Services\SwingReactionPriceSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SwingReactionPriceSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_11_11_000000_create_swing_reaction_tables.php'))->up();
        Schema::create('wall_observations', function (Blueprint $t) {
            $t->id();
            $t->string('symbol');
            $t->string('dataset');
            $t->string('schema_version');
            $t->date('analysis_session');
            $t->longText('payload_json');
            $t->dateTime('recorded_at');
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-26 11:00', 'America/New_York'));
        Http::preventStrayRequests();
    }

    private function bundle(array $bars, string $recorded = '2026-10-26 14:55:00', array $extra = []): void
    {
        DB::table('wall_observations')->insert(['symbol' => 'SPY', 'dataset' => 'intraday_price_bars',
            'schema_version' => 'wall-price-bars.v1', 'analysis_session' => '2026-10-26',
            'payload_json' => json_encode([...['symbol' => 'SPY', 'source' => 'massive_5m_unadjusted', 'bars' => $bars], ...$extra]),
            'recorded_at' => $recorded]);
    }

    private function bar(string $time, float $high = 102.): array
    {
        return ['t' => CarbonImmutable::parse('2026-10-26 '.$time, 'America/New_York')->getTimestamp() * 1000,
            'o' => 100., 'h' => $high, 'l' => 99., 'c' => 101.];
    }

    public function test_pins_only_completed_delayed_regular_session_prices_without_http_and_never_rewrites(): void
    {
        $source = new SwingReactionPriceSource;
        $this->bundle([$this->bar('09:25'), $this->bar('09:30'), $this->bar('10:40'), $this->bar('10:45')]);
        $result = $source->capture('SPY', CarbonImmutable::now('UTC'));
        // At11:00,10:40-10:45 is not yet beyond the15min+30sec availability cutoff.
        $this->assertSame(1, $result['pinned']);
        $original = DB::table('swing_reaction_prices')->first();
        $this->assertSame(0, $source->capture('SPY', CarbonImmutable::now('UTC'))['pinned']);
        $this->bundle([$this->bar('09:30', 103.)]);
        $correction = $source->capture('SPY', CarbonImmutable::now('UTC'));
        $this->assertCount(1, $correction['conflicts']);
        $this->assertEquals($original, DB::table('swing_reaction_prices')->first());
        $bars = $source->bars('SPY', CarbonImmutable::parse('2026-10-26T13:00:00Z'), CarbonImmutable::now('UTC'));
        $this->assertCount(1, $bars);
        $this->assertSame(102., $bars[0]['high']);
        Http::assertNothingSent();
    }

    public function test_synthetic_future_duplicate_and_malformed_bars_cannot_enter_saved_evidence(): void
    {
        $source = new SwingReactionPriceSource;
        $this->bundle([$this->bar('09:30')], extra: ['demonstration' => true]);
        $this->assertSame(0, $source->capture('SPY', CarbonImmutable::now('UTC'))['pinned']);
        $this->bundle([$this->bar('09:30'), $this->bar('09:30'), [...$this->bar('09:35'), 'h' => 98.]]);
        $this->assertSame(0, $source->capture('SPY', CarbonImmutable::now('UTC'))['pinned']);
        $this->bundle([$this->bar('09:30')], '2026-10-27 14:55:00');
        $this->assertSame(0, $source->capture('SPY', CarbonImmutable::now('UTC'))['pinned']);
        $this->assertSame(0, DB::table('swing_reaction_prices')->count());
    }
}
