<?php

namespace Tests\Unit;

use App\Support\EodSnapshotManifestBuilder;
use App\Support\GexSnapshotCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GexSnapshotCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
    }

    public function test_valid_envelope_uses_one_get_and_retains_the_first_published_payload(): void
    {
        $cache = new GexSnapshotCache;
        $key = $cache->key('SPY', '30d', $this->manifest());
        $payload = ['symbol' => 'SPY', 'strike_data' => [['strike' => 100, 'net_gex' => 0.0]]];
        $cache->putIfMissing($key, $payload);
        $cache->putIfMissing($key, ['strike_data' => [['strike' => 999]]]);
        $this->assertSame($payload, $cache->get($key));
        Cache::spy();
        Cache::shouldReceive('get')->once()->with($key)->andReturn([
            'schema_version' => 1, 'payload' => $payload,
            'sha256' => hash('sha256', EodSnapshotManifestBuilder::canonicalJson($payload)),
        ]);
        $this->assertSame($payload, $cache->get($key));
        Cache::shouldNotHaveReceived('has');
    }

    public function test_corrupt_payload_is_a_miss_and_only_its_key_is_removed(): void
    {
        $cache = new GexSnapshotCache;
        Cache::put('corrupt', ['schema_version' => 1, 'payload' => ['strike_data' => []], 'sha256' => 'wrong']);
        Cache::put('unrelated', 'keep');
        $this->assertNull($cache->get('corrupt'));
        $this->assertNull(Cache::get('corrupt'));
        $this->assertSame('keep', Cache::get('unrelated'));
    }

    public function test_calendar_policy_and_generation_changes_have_distinct_keys(): void
    {
        $cache = new GexSnapshotCache;
        $manifest = $this->manifest();
        $start = CarbonImmutable::parse('2026-09-18T23:59:59Z');
        $key = $cache->key('SPY', 'monthly', $manifest, $start);
        $this->assertNotSame($key, $cache->key('SPY', 'monthly', $manifest, $start->addSecond()));
        $this->assertNotSame($key, $cache->key('SPY', 'monthly', $manifest, $start->addHours(5)));
        $this->assertNotSame($key, $cache->key('QQQ', 'monthly', $manifest, $start));
        $changed = $manifest;
        $changed['revision']++;
        $this->assertNotSame($key, $cache->key('SPY', 'monthly', $changed, $start));
        $changed = $manifest;
        $changed['policy']['min_side_ratio'] = 0.5;
        $this->assertNotSame($key, $cache->key('SPY', 'monthly', $changed, $start));
    }

    private function manifest(): array
    {
        return ['revision' => 1, 'cache_version' => 'complete',
            'policy' => EodSnapshotManifestBuilder::policy('2026-09-18', 0.35)];
    }
}
