<?php

namespace Tests\Unit;

use App\Support\ActivityPricingBatch;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class Gex027ActivityPricingCapabilitiesTest extends TestCase
{
    public function test_quote_table_capability_does_not_probe_chain_columns(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('option_quotes')->andReturn(true);
        Schema::shouldReceive('getColumnListing')->never();
        $batch = ActivityPricingBatch::load('FIXTURE', []);
        $this->assertSame('option_quotes', $batch->source);
        $this->assertSame([], $batch->directColumns);
    }

    public function test_column_capability_is_case_insensitive_and_keeps_legacy_precedence(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('option_quotes')->andReturn(false);
        Schema::shouldReceive('getColumnListing')->once()->with('option_chain_data')
            ->andReturn(['CLOSE', 'MID_PRICE', 'unrelated', 'BID', 'ask']);
        $batch = ActivityPricingBatch::load('FIXTURE', []);
        $this->assertSame('chain_quotes', $batch->source);
        $this->assertSame(['o.mid_price', 'o.close', 'o.bid', 'o.ask'], $batch->directColumns);
    }

    public function test_null_spot_is_cached_per_native_date_without_suppressing_a_later_date(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('option_quotes')->andReturn(true);
        $batch = ActivityPricingBatch::load('FIXTURE', []);
        $calls = 0;
        $load = function () use (&$calls): ?float {
            $calls++;

            return null;
        };
        $this->assertNull($batch->fallbackSpot('2026-09-08', $load));
        $this->assertNull($batch->fallbackSpot('2026-09-08', $load));
        $this->assertSame(1, $calls);
        $this->assertSame(123.25, $batch->fallbackSpot('2026-09-09', fn (): float => 123.25));
    }

    public function test_fractional_pricing_keys_do_not_depend_on_json_serialization_precision(): void
    {
        $previous = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '2');
            $key = new ReflectionMethod(ActivityPricingBatch::class, 'key');
            $this->assertNotSame($key->invoke(null, '2030-01-03', 100.25), $key->invoke(null, '2030-01-03', 100.75));
            $this->assertSame($key->invoke(null, '2030-01-03', 100.25), $key->invoke(null, '2030-01-03', 100.25));
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }
}
