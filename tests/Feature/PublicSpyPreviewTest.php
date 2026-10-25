<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Support\EodCacheVersion;
use App\Support\PublicSpyPreview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PublicSpyPayload;
use Tests\TestCase;

class PublicSpyPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'cache.coordination_enabled' => false,
            'eod_publications.read_enabled' => false, 'eod_publications.write_enabled' => false]);
        Cache::flush();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00', 'America/New_York'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_public_page_uses_fixed_dashboard_inputs_and_renders_real_values_without_javascript(): void
    {
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldReceive('getGexLevels')->once()->withArgs(fn (Request $request) => $request->query() === [
            'symbol' => 'SPY', 'timeframe' => '14d', 'view' => 'latest_eod',
        ])->andReturn(response()->json(PublicSpyPayload::make()));
        $this->app->instance(GexController::class, $mock);
        $response = $this->get('/spy-gex-levels?symbol=QQQ&timeframe=90d&refresh=1&view=next_session')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('<h1 class="mk-title">SPY GEX levels</h1>', $html);
        $this->assertStringContainsString('2026-10-23', $html);
        $this->assertStringContainsString('595', $html);
        $this->assertStringContainsString('605', $html);
        $this->assertStringContainsString('-70M', $html);
        $this->assertStringContainsString('USD per 1% underlying move', $html);
        $this->assertStringContainsString('<svg viewBox=', $html);
        $this->assertStringContainsString('https://gexoptions.com/spy-gex-levels', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('DO-NOT-EXPOSE', $html);
        $this->assertStringNotContainsString('total_open_interest', $html);
        Bus::assertNothingDispatched();
        $this->getJson('/api/gex-levels?symbol=QQQ')->assertUnauthorized();
    }

    public function test_normalization_preserves_signs_totals_and_both_peaks_without_private_fields(): void
    {
        $preview = (new PublicSpyPreview)->normalize(PublicSpyPayload::make());
        $this->assertSame(-70000000., $preview['total_gex']);
        $this->assertSame(595., $preview['put_wall']['strike']);
        $this->assertSame(605., $preview['call_wall']['strike']);
        $this->assertSame(-100000000., $preview['put_wall']['net_gex']);
        $this->assertSame(50000000., $preview['call_wall']['net_gex']);
        $this->assertSame([595., 600., 605.], array_column($preview['chart']['bars'], 'strike'));
        foreach ($preview['chart']['bars'] as $bar) {
            $this->assertGreaterThanOrEqual(0, $bar['height']);
            $this->assertTrue(is_finite($bar['x']) && is_finite($bar['y']));
        }
        $this->assertArrayNotHasKey('run', $preview);
        $this->assertArrayNotHasKey('social_quality', $preview);
        $this->assertArrayNotHasKey('strike_data', $preview);
    }

    public function test_cached_reads_do_not_rebuild_and_new_publications_invalidate_the_preview(): void
    {
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldReceive('getGexLevels')->twice()->andReturn(response()->json(PublicSpyPayload::make()));
        $this->app->instance(GexController::class, $mock);
        $source = new PublicSpyPreview;
        $this->assertSame($source->read(), $source->read());
        Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), 'new-generation');
        $this->assertNotNull($source->read());
    }

    public function test_failed_refresh_keeps_a_dated_last_good_preview_and_retries_are_cached(): void
    {
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldReceive('getGexLevels')->once()->andReturn(response()->json(PublicSpyPayload::make()));
        $this->app->instance(GexController::class, $mock);
        $source = new PublicSpyPreview;
        $source->read();
        Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), 'new-generation');
        $failed = \Mockery::mock(GexController::class);
        $failed->shouldReceive('getGexLevels')->once()->andReturn(response()->json([], 503));
        $this->app->instance(GexController::class, $failed);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 17:00', 'America/New_York'));
        $preview = $source->read();
        $this->assertSame('2026-10-23', $preview['data_date']);
        $this->assertFalse($preview['is_latest_session']);
        $this->assertSame($preview, $source->read());
    }

    public function test_a_publication_race_does_not_publish_under_the_old_version(): void
    {
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldReceive('getGexLevels')->once()->andReturnUsing(function () {
            Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), 'advanced-during-build');
            return response()->json(PublicSpyPayload::make());
        });
        $this->app->instance(GexController::class, $mock);
        $this->assertNull((new PublicSpyPreview)->read());
        $this->assertNull(Cache::get('public:spy-preview:v1:last-good'));
    }

    public function test_concurrent_cold_requests_do_not_rebuild_the_chain(): void
    {
        $lock = Cache::lock('public:spy-preview:v1:build', 90);
        $this->assertTrue($lock->get());
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldNotReceive('getGexLevels');
        $this->app->instance(GexController::class, $mock);
        try {
            $this->assertNull((new PublicSpyPreview)->read());
        } finally {
            $lock->release();
        }
    }

    public function test_invalid_or_unpublished_data_is_not_presented_as_a_chart(): void
    {
        $source = new PublicSpyPreview;
        foreach (['future', 'mixed-dates', 'bad-number', 'bad-date', 'other-symbol', 'wrong-scope', 'duplicate'] as $case) {
            $payload = PublicSpyPayload::make();
            match ($case) {
                'future' => $payload['data_date'] = '2026-10-26',
                'mixed-dates' => $payload['social_quality']['source_dates'][] = '2026-10-22',
                'bad-number' => $payload['strike_data'][0]['net_gex'] = INF,
                'bad-date' => $payload['data_date'] = '2026-02-30',
                'other-symbol' => $payload['symbol'] = 'QQQ',
                'wrong-scope' => $payload['timeframe'] = '7d',
                'duplicate' => $payload['strike_data'][] = $payload['strike_data'][0],
            };
            $this->assertNull($source->normalize($payload), $case);
        }
        $this->assertNull($source->normalize([]));
    }

    public function test_small_gamma_gaps_follow_the_existing_95_percent_input_rule(): void
    {
        $payload = PublicSpyPayload::make();
        $payload['social_quality'] += ['gamma_only_gaps' => true, 'missing_gamma_oi_share' => .04];
        $payload['social_quality']['publishable'] = false;
        $this->assertNotNull((new PublicSpyPreview)->normalize($payload));
        $payload['social_quality']['missing_gamma_oi_share'] = .06;
        $this->assertNull((new PublicSpyPreview)->normalize($payload));
    }

    public function test_completed_session_respects_weekends_premarket_holidays_and_early_closes(): void
    {
        foreach ([['2026-10-25 12:00', '2026-10-23'], ['2026-10-26 08:00', '2026-10-23'],
            ['2026-10-26 15:00', '2026-10-23'], ['2026-10-26 16:01', '2026-10-26'],
            ['2026-09-07 12:00', '2026-09-04'], ['2026-11-27 13:01', '2026-11-27']] as [$at, $expected]) {
            $this->travelTo(CarbonImmutable::parse($at, 'America/New_York'));
            $this->assertSame($expected, PublicSpyPreview::completedSession());
        }
    }

    public function test_empty_data_keeps_a_useful_public_page_without_fake_levels(): void
    {
        $mock = \Mockery::mock(GexController::class);
        $mock->shouldReceive('getGexLevels')->once()->andReturn(response()->json([], 404));
        $this->app->instance(GexController::class, $mock);
        $html = $this->get('/spy-gex-levels')->assertOk()->getContent();
        $this->assertStringContainsString('SPY EOD levels are being prepared.', $html);
        $this->assertStringContainsString('What should you do with these levels?', $html);
        $this->assertStringNotContainsString('<svg viewBox=', $html);
        $this->assertStringNotContainsString('data_date', $html);
        Bus::assertNothingDispatched();
    }
}
