<?php

namespace Tests\Feature;

use App\Support\IntradayExpiryCatalog;
use App\Support\MassiveExpirationCatalog;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IntradayExpiryCatalogTest extends TestCase
{
    public function test_only_a_complete_listing_can_exclude_dates_in_its_verified_window(): void
    {
        config(['cache.default' => 'array']);
        $at = CarbonImmutable::parse('2026-10-12T16:00:00Z');
        $this->travelTo($at);
        $provider = \Mockery::mock(new MassiveExpirationCatalog);
        $this->app->instance(MassiveExpirationCatalog::class, $provider);
        $provider->shouldReceive('discover')->once()->with('DRAM', '2026-10-12', 7)->andReturn([
            'expirations' => ['2026-10-12', '2026-10-16', '2026-10-19'],
            'meta' => ['complete' => true, 'capped' => false, 'status' => 'ok'],
        ]);
        $catalog = app(IntradayExpiryCatalog::class);
        $expiries = [1 => '2026-10-12', 2 => '2026-10-14', 3 => '2026-10-16', 4 => '2026-10-23'];
        $this->assertSame($expiries, $catalog->filter('DRAM', $expiries, $at));
        $this->assertTrue($catalog->refresh('DRAM', $at));
        $this->assertTrue($catalog->refresh('DRAM', $at));
        $this->assertSame([1 => '2026-10-12', 3 => '2026-10-16', 4 => '2026-10-23'], $catalog->filter('DRAM', $expiries, $at));
        $this->assertSame($expiries, $catalog->filter('SPY', $expiries, $at));
        $this->assertSame($expiries, $catalog->filter('DRAM', $expiries, $at->addDay()));
        $this->travelTo($at->addMinutes(31));
        $this->assertSame($expiries, $catalog->filter('DRAM', $expiries, $at->addMinutes(31)));
        $this->travelBack();
    }

    public function test_partial_or_capped_provider_results_never_narrow_the_basket_and_are_backed_off(): void
    {
        config(['cache.default' => 'array']);
        $at = CarbonImmutable::parse('2026-10-12T16:00:00Z');
        $this->travelTo($at);
        $provider = \Mockery::mock(new MassiveExpirationCatalog);
        $this->app->instance(MassiveExpirationCatalog::class, $provider);
        $provider->shouldReceive('discover')->once()->with('DRAM', '2026-10-12', 7)->andReturn([
            'expirations' => ['2026-10-16'], 'meta' => ['complete' => false, 'capped' => true, 'status' => 'pagination_capped'],
        ]);
        $catalog = app(IntradayExpiryCatalog::class);
        $this->assertFalse($catalog->refresh('DRAM', $at));
        $this->assertFalse($catalog->refresh('DRAM', $at));
        $expiries = ['2026-10-14', '2026-10-16'];
        $this->assertSame($expiries, $catalog->filter('DRAM', $expiries, $at));
        $this->travelBack();
    }
}
