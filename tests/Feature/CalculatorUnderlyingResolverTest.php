<?php

namespace Tests\Feature;

use App\Support\CalculatorUnderlyingResolver;
use App\Support\UnderlyingQuoteRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalculatorUnderlyingResolverTest extends TestCase
{
    use DatabaseTransactions;

    private CalculatorUnderlyingResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('underlying_quotes')) {
            Schema::create('underlying_quotes', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol')->unique();
                $table->string('source')->nullable();
                $table->decimal('last_price', 14, 6);
                $table->decimal('prev_close', 14, 6)->nullable();
                $table->timestamp('asof');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('option_snapshots')) {
            Schema::create('option_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->string('ticker');
                $table->string('type');
                $table->decimal('strike', 12, 2);
                $table->date('expiry');
                $table->decimal('bid', 10, 2);
                $table->decimal('ask', 10, 2);
                $table->decimal('mid', 10, 2);
                $table->decimal('underlying_price', 12, 2);
                $table->timestamp('fetched_at');
            });
        }

        config()->set('calculator_underlying.extended_hours', [
            'start' => '04:00',
            'end' => '20:00',
        ]);
        config()->set('calculator_underlying.freshness_seconds', [
            'regular' => ['live' => 300, 'usable' => 900],
            'extended' => ['live' => 900, 'usable' => 3600],
            'closed' => ['live' => 0, 'usable' => 259200],
        ]);
        config()->set('calculator_underlying.allow_stale_for_calculation', true);
        config()->set('calculator_underlying.future_tolerance_seconds', 30);

        $this->resolver = new CalculatorUnderlyingResolver;
    }

    public function test_a_canonicalized_exactly_one_hundred_dollar_quote_remains_live_and_usable(): void
    {
        $this->quote('SPY', 100, '2026-07-17 13:58:00');

        $result = $this->resolver->resolve(
            ' spy ',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('SPY', $result['symbol']);
        $this->assertSame(100.0, $result['price']);
        $this->assertSame('live', $result['status']);
        $this->assertTrue($result['usable_for_calculation']);
        $this->assertSame('massive-v2-snapshot', $result['source']);
        $this->assertSame('2026-07-17T13:58:00+00:00', $result['asof']);
        $this->assertSame(120, $result['age_seconds']);
        $this->assertSame('regular', $result['session']);
    }

    public function test_recording_a_new_price_without_a_previous_close_preserves_existing_quote_data(): void
    {
        DB::table('underlying_quotes')->insert([
            'symbol' => 'KEEP',
            'source' => 'massive-v2-snapshot',
            'last_price' => 100,
            'prev_close' => 97.5,
            'asof' => '2026-07-17 13:50:00',
            'created_at' => '2026-07-17 13:50:00',
            'updated_at' => '2026-07-17 13:50:00',
        ]);

        $recorded = app(UnderlyingQuoteRecorder::class)->record(
            'KEEP',
            101,
            'massive-v3-snapshot',
            '2026-07-17 13:55:00',
            recordedAt: CarbonImmutable::parse('2026-07-17 14:00:00', 'UTC')
        );

        $this->assertTrue($recorded);
        $this->assertDatabaseHas('underlying_quotes', [
            'symbol' => 'KEEP',
            'last_price' => 101,
            'prev_close' => 97.5,
            'source' => 'massive-v3-snapshot',
        ]);
    }

    public function test_a_timestamped_quote_inside_the_stale_policy_window_is_labeled_stale_and_usable(): void
    {
        $this->quote('AAPL', 225.5, '2026-07-17 13:50:00');

        $result = $this->resolver->resolve(
            'AAPL',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('stale', $result['status']);
        $this->assertSame(225.5, $result['price']);
        $this->assertTrue($result['usable_for_calculation']);
        $this->assertSame(600, $result['age_seconds']);
        $this->assertSame('outside_live_window', $result['reason']);
    }

    public function test_a_quote_beyond_the_stale_policy_window_is_not_exposed_for_calculation(): void
    {
        $this->quote('AAPL', 225.5, '2026-07-17 13:40:00');

        $result = $this->resolver->resolve(
            'AAPL',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('stale', $result['status']);
        $this->assertNull($result['price']);
        $this->assertFalse($result['usable_for_calculation']);
        $this->assertSame('stale_too_old', $result['reason']);
    }

    public function test_stale_calculation_can_be_disabled_without_relabeling_the_real_quote(): void
    {
        config()->set('calculator_underlying.allow_stale_for_calculation', false);
        $this->quote('AAPL', 225.5, '2026-07-17 13:50:00');

        $result = $this->resolver->resolve(
            'AAPL',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('stale', $result['status']);
        $this->assertNull($result['price']);
        $this->assertFalse($result['usable_for_calculation']);
        $this->assertSame('stale_not_allowed', $result['reason']);
    }

    public function test_extended_and_regular_sessions_use_different_live_windows(): void
    {
        $this->quote('QQQ', 600, '2026-07-17 11:50:00');

        $extended = $this->resolver->resolve(
            'QQQ',
            CarbonImmutable::parse('2026-07-17 08:00:00', 'America/New_York')
        );
        $regular = $this->resolver->resolve(
            'QQQ',
            CarbonImmutable::parse('2026-07-17 09:40:00', 'America/New_York')
        );

        $this->assertSame('extended', $extended['session']);
        $this->assertSame('live', $extended['status']);
        $this->assertSame(900, $extended['live_max_age_seconds']);
        $this->assertSame('regular', $regular['session']);
        $this->assertSame('stale', $regular['status']);
        $this->assertSame(300, $regular['live_max_age_seconds']);
    }

    public function test_closed_session_uses_the_documented_last_known_quote_window(): void
    {
        $this->quote('SPY', 600, '2026-07-17 20:00:00');

        $result = $this->resolver->resolve(
            'SPY',
            CarbonImmutable::parse('2026-07-18 12:00:00', 'America/New_York')
        );

        $this->assertSame('closed', $result['session']);
        $this->assertSame('stale', $result['status']);
        $this->assertTrue($result['usable_for_calculation']);
        $this->assertSame(0, $result['live_max_age_seconds']);
        $this->assertSame(259200, $result['stale_usable_max_age_seconds']);
    }

    public function test_missing_zero_and_negative_prices_are_unavailable_and_never_become_one_hundred(): void
    {
        $at = CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York');
        $missing = $this->resolver->resolve('MISS', $at);

        $this->quote('ZERO', 0, '2026-07-17 14:00:00');
        $zero = $this->resolver->resolve('ZERO', $at);

        $this->quote('NEG', -1, '2026-07-17 14:00:00');
        $negative = $this->resolver->resolve('NEG', $at);

        foreach ([$missing, $zero, $negative] as $result) {
            $this->assertSame('unavailable', $result['status']);
            $this->assertNull($result['price']);
            $this->assertFalse($result['usable_for_calculation']);
        }
        $this->assertSame('missing_quote', $missing['reason']);
        $this->assertSame('invalid_price', $zero['reason']);
        $this->assertSame('invalid_price', $negative['reason']);
    }

    public function test_blank_and_ingestion_time_sources_are_never_labeled_live(): void
    {
        $at = CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York');
        $this->quote('BLANK', 50, '2026-07-17 14:00:00', null);
        $this->quote('SYNTH', 50, '2026-07-17 14:00:00', 'massive-v2-snapshot:ingested-at');

        foreach (['BLANK', 'SYNTH'] as $symbol) {
            $result = $this->resolver->resolve($symbol, $at);
            $this->assertSame('unavailable', $result['status']);
            $this->assertNull($result['price']);
            $this->assertFalse($result['usable_for_calculation']);
            $this->assertSame('unverifiable_source', $result['reason']);
        }
    }

    public function test_source_less_legacy_snapshot_data_is_not_used_as_a_live_quote(): void
    {
        DB::table('option_snapshots')->insert([
            'symbol' => 'LEGACY',
            'ticker' => 'O:LEGACY260717C00100000',
            'type' => 'call',
            'strike' => 100,
            'expiry' => '2026-07-17',
            'bid' => 1,
            'ask' => 2,
            'mid' => 1.5,
            'underlying_price' => 100,
            'fetched_at' => '2026-07-17 14:00:00',
        ]);

        $result = $this->resolver->resolve(
            'LEGACY',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['price']);
        $this->assertFalse($result['usable_for_calculation']);
        $this->assertSame('missing_quote', $result['reason']);
    }

    public function test_a_materially_future_source_timestamp_is_unavailable(): void
    {
        $this->quote('FUTURE', 50, '2026-07-17 14:01:00');

        $result = $this->resolver->resolve(
            'FUTURE',
            CarbonImmutable::parse('2026-07-17 10:00:00', 'America/New_York')
        );

        $this->assertSame('unavailable', $result['status']);
        $this->assertNull($result['price']);
        $this->assertFalse($result['usable_for_calculation']);
        $this->assertSame('future_asof', $result['reason']);
    }

    private function quote(
        string $symbol,
        float $price,
        string $asof,
        ?string $source = 'massive-v2-snapshot'
    ): void {
        DB::table('underlying_quotes')->insert([
            'symbol' => $symbol,
            'source' => $source,
            'last_price' => $price,
            'prev_close' => null,
            'asof' => $asof,
            'created_at' => $asof,
            'updated_at' => $asof,
        ]);
    }
}
