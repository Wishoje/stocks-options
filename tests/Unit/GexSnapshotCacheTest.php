<?php

namespace Tests\Unit;

use App\Support\EodCacheVersion;
use App\Support\EodSnapshotManifestBuilder;
use App\Support\GexSnapshotCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_v6_does_not_reuse_or_remove_v5_payloads_with_the_same_manifest_identity(): void
    {
        $cache = new GexSnapshotCache;
        $key = $cache->key('SPY', '30d', $this->manifest());
        $this->assertStringStartsWith('gex:levels:v6:', $key);
        $oldKey = str_replace('gex:levels:v6:', 'gex:levels:v5:', $key);
        $oldPayload = ['symbol' => 'SPY', 'strike_data' => [['strike' => 100.25, 'net_gex' => 10000000000.00003]]];
        $freshPayload = ['symbol' => 'SPY', 'strike_data' => [['strike' => 100.25, 'net_gex' => 10000000000.0]]];
        $cache->putIfMissing($oldKey, $oldPayload);

        $this->assertNull($cache->get($key), 'An existing v5 envelope must not count as a v6 warm response.');
        $cache->putIfMissing($key, $freshPayload);
        $this->assertSame($freshPayload, $cache->get($key));
        $this->assertSame($oldPayload, $cache->get($oldKey), 'Old payloads expire naturally without a shared cache clear.');
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

    public function test_compatibility_identity_separates_policy_calendars_publications_and_certified_state(): void
    {
        config()->set(['eod_snapshot_health.enabled' => true, 'eod_snapshot_health.read_enabled' => true]);
        $state = null;
        $query = \Mockery::mock(\Illuminate\Database\Query\Builder::class);
        DB::shouldReceive('table')->with('eod_snapshot_states')->andReturn($query);
        $query->shouldReceive('where')->with('symbol', 'SPY')->andReturnSelf();
        $query->shouldReceive('first')->andReturnUsing(static function () use (&$state) {
            return $state;
        });
        $cache = new GexSnapshotCache;
        $policy = $this->manifest()['policy'];
        $at = CarbonImmutable::parse('2026-09-18T23:59:59Z');
        $first = $cache->compatibilityContext('SPY', 'monthly', $policy, $at);
        $this->assertNotNull($first);
        $this->assertStringStartsWith('gex:levels:compat:v1:', $first['key']);
        $this->assertNotSame($cache->key('SPY', 'monthly', $this->manifest(), $at), $first['key']);
        $this->assertNotSame($first, $cache->compatibilityContext('SPY', 'monthly', $policy, $at->addSecond()));
        $nyBefore = $at->addHours(4);
        $this->assertNotSame($cache->compatibilityContext('SPY', 'monthly', $policy, $nyBefore),
            $cache->compatibilityContext('SPY', 'monthly', $policy, $nyBefore->addSecond()));
        $this->assertNotSame($first, $cache->compatibilityContext('SPY', '30d', $policy, $at));
        $changed = $policy;
        $changed['min_side_ratio'] = 0.5;
        $this->assertNotSame($first, $cache->compatibilityContext('SPY', 'monthly', $changed, $at));
        $changed = $policy;
        $changed['anchor_date'] = '2026-09-17';
        $this->assertNotSame($first, $cache->compatibilityContext('SPY', 'monthly', $changed, $at));
        Cache::put(app(EodCacheVersion::class)->publicationKey('gex', 'SPY'), ['version' => 'published']);
        $published = $cache->compatibilityContext('SPY', 'monthly', $policy, $at);
        $this->assertNotSame($first, $published);
        $state = (object) ['revision' => 1, 'certified_revision' => 1, 'certified_version' => 'published',
            'certified_issued_at_microseconds' => 123];
        $certified = $cache->compatibilityContext('SPY', 'monthly', $policy, $at);
        $this->assertNotNull($certified);
        $this->assertNotSame($published, $certified);
        $state->certified_issued_at_microseconds++;
        $this->assertNotSame($certified, $cache->compatibilityContext('SPY', 'monthly', $policy, $at));
        $state->revision++;
        $this->assertNull($cache->compatibilityContext('SPY', 'monthly', $policy, $at));
        $state->certified_revision++;
        $this->assertNotNull($cache->compatibilityContext('SPY', 'monthly', $policy, $at));
        $state->certified_version = 'not-the-accepted-publication';
        $this->assertNull($cache->compatibilityContext('SPY', 'monthly', $policy, $at));
        $state->certified_revision = null;
        $this->assertNull($cache->compatibilityContext('SPY', 'monthly', $policy, $at));
    }

    public function test_missing_metadata_is_not_treated_as_an_absent_state(): void
    {
        config()->set(['eod_snapshot_health.enabled' => true, 'eod_snapshot_health.read_enabled' => true]);
        DB::shouldReceive('table')->once()->with('eod_snapshot_states')->andThrow(new \RuntimeException('unavailable'));
        $this->assertNull((new GexSnapshotCache)->compatibilityContext('SPY', '30d', $this->manifest()['policy']));
    }

    public function test_tracking_only_does_not_probe_compatibility_metadata(): void
    {
        config()->set(['eod_snapshot_health.enabled' => true, 'eod_snapshot_health.read_enabled' => false]);
        DB::shouldReceive('table')->never();
        $this->assertNull((new GexSnapshotCache)->compatibilityContext('SPY', '30d', $this->manifest()['policy']));
    }

    public function test_compatibility_payloads_have_short_ttl_without_shortening_canonical_cache(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18T21:00:00Z'));
        try {
            $cache = new GexSnapshotCache;
            $key = 'gex:levels:compat:v1:'.str_repeat('a', 64);
            $canonical = $cache->key('SPY', '30d', $this->manifest());
            $payload = ['strike_data' => [['strike' => 100.25, 'net_gex' => 0.0]]];
            $cache->putCompatibilityIfMissing($key, $payload);
            $cache->putIfMissing($canonical, $payload);
            $this->travel(119)->seconds();
            $this->assertSame($payload, $cache->get($key));
            $this->travel(2)->seconds();
            $this->assertNull($cache->get($key));
            $this->assertSame($payload, $cache->get($canonical));
        } finally {
            $this->travelBack();
        }
    }

    public function test_compatibility_writer_refuses_legacy_or_manifest_cache_namespaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new GexSnapshotCache)->putCompatibilityIfMissing('gex:levels:v4:SPY:30d:initial', ['strike_data' => []]);
    }

    private function manifest(): array
    {
        return ['revision' => 1, 'cache_version' => 'complete',
            'policy' => EodSnapshotManifestBuilder::policy('2026-09-18', 0.35)];
    }
}
