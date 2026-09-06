<?php

namespace Tests\Unit;

use App\Jobs\RebuildEodSnapshotManifestJob;
use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotManifestBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class EodSnapshotManifestBuilderTest extends TestCase
{
    public function test_disabled_feature_never_touches_schema_or_database(): void
    {
        config()->set('eod_snapshot_health.enabled', false);
        Schema::shouldReceive('hasTable')->never();
        DB::shouldReceive('connection')->never();
        $health = new EodSnapshotHealth;
        $policy = EodSnapshotManifestBuilder::policy('2026-09-04', 0.35);
        $this->assertNull($health->begin('SPY', 'scope'));
        $this->assertNull($health->beginRecovery('SPY', str_repeat('a', 64), 'publish', false));
        $health->complete(null);
        $health->fail(null);
        $this->assertFalse($health->certify('SPY', 'version', 1));
        $this->assertNull($health->head('SPY'));
        $this->assertNull($health->read('SPY', $policy));
        $this->assertNull($health->requestRebuild('SPY', $policy));
        $this->assertNull($health->rebuild('SPY', 1, 'version', $policy));
    }

    public function test_missing_schema_is_read_fallback_without_probes_but_enabled_writer_fails_closed(): void
    {
        config()->set('eod_snapshot_health.enabled', true);
        Schema::shouldReceive('hasTable')->once()->with('eod_snapshot_states')->andReturnFalse();
        DB::shouldReceive('table')->twice()->with('eod_snapshot_states')
            ->andThrow(new \RuntimeException('Table unavailable'));
        DB::shouldReceive('table')->once()->with('eod_snapshot_states as s')
            ->andThrow(new \RuntimeException('Table unavailable'));
        $health = new EodSnapshotHealth;
        $this->assertNull($health->head('SPY'));
        $this->assertNull($health->head('SPY'));
        $this->assertNull($health->read('SPY', EodSnapshotManifestBuilder::policy('2026-09-04', 0.35)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('durable schema is unavailable');
        $health->begin('SPY', 'scope');
    }

    public function test_read_retries_after_unavailable_schema_instead_of_caching_missing_capability(): void
    {
        config()->set('eod_snapshot_health.enabled', true);
        Schema::shouldReceive('hasTable')->never();
        $query = \Mockery::mock(\Illuminate\Database\Query\Builder::class);
        $query->shouldReceive('where')->once()->with('symbol', 'SPY')->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) [
            'symbol' => 'SPY', 'revision' => 1, 'certified_revision' => 1,
            'certified_version' => 'v1', 'certified_issued_at_microseconds' => 100,
        ]);
        DB::shouldReceive('table')->once()->with('eod_snapshot_states')
            ->andThrow(new \RuntimeException('Table unavailable'))->ordered();
        DB::shouldReceive('table')->once()->with('eod_snapshot_states')->andReturn($query)->ordered();
        $health = new EodSnapshotHealth;
        $this->assertNull($health->head('SPY'));
        $this->assertSame('v1', $health->head('SPY')['cache_version']);
    }

    public function test_projection_preserves_balanced_fallback_missing_and_greek_independence(): void
    {
        $facts = (new EodSnapshotManifestBuilder)->fromRows('SPY', 2, 'complete-v2',
            EodSnapshotManifestBuilder::policy('2026-09-04', 0.5),
            collect([(object) ['id' => 1, 'expiration_date' => '2026-09-08'], (object) ['id' => 2, 'expiration_date' => '2026-09-11'], (object) ['id' => 3, 'expiration_date' => '2026-09-18']]),
            collect([
                $this->row(1, '2026-09-04', 4, 1, 3),
                $this->row(1, '2026-09-03', 2, 2, 0),
                $this->row(1, '2026-09-08', 3, 3, 2),
                $this->row(2, '2026-09-04', 2, 0, 0),
            ])
        );
        $this->assertSame('2026-09-03', $facts['expirations'][1]['selected_date']);
        $this->assertSame(0, $facts['expirations'][1]['selected_gamma_rows']);
        $this->assertSame('2026-09-08', $facts['expirations'][1]['latest_gamma_date']);
        $this->assertSame('2026-09-04', $facts['expirations'][1]['latest_any_date']);
        $this->assertSame('fallback_partial', $facts['expirations'][2]['selection_state']);
        $this->assertSame('2026-09-04', $facts['expirations'][2]['selected_date']);
        $this->assertSame('missing', $facts['expirations'][3]['selection_state']);
        $this->assertNull($facts['expirations'][3]['selected_date']);
        $this->assertSame(3, $facts['expiration_count']);
    }

    public function test_sql_selector_and_php_summary_boundary_decisions_remain_distinct(): void
    {
        $newer = $this->row(1, '2026-09-04', 351, 1003, 0);
        $newer->selector_balanced = 1;
        $older = $this->row(1, '2026-09-03', 2, 2, 0);
        $older->selector_balanced = 1;
        $facts = (new EodSnapshotManifestBuilder)->fromRows('SPY', 1, 'v1',
            EodSnapshotManifestBuilder::policy('2026-09-04', 0.35),
            collect([(object) ['id' => 1, 'expiration_date' => '2026-09-08']]), collect([$newer, $older]));
        $this->assertSame('2026-09-04', $facts['expirations'][1]['selected_date']);
        $this->assertSame('2026-09-03', $facts['expirations'][1]['latest_balanced_date']);
    }

    public function test_frozen_policy_fingerprint_separates_anchor_and_worker_ratio(): void
    {
        $a = EodSnapshotManifestBuilder::policy('2026-09-04', 0.35);
        $this->assertNotSame(EodSnapshotManifestBuilder::policyHash($a),
            EodSnapshotManifestBuilder::policyHash(EodSnapshotManifestBuilder::policy('2026-09-04', 0.5)));
        $this->assertNotSame(EodSnapshotManifestBuilder::policyHash($a),
            EodSnapshotManifestBuilder::policyHash(EodSnapshotManifestBuilder::policy('2026-09-03', 0.35)));
        $this->assertSame(EodSnapshotManifestBuilder::policyHash($a),
            EodSnapshotManifestBuilder::policyHash(array_reverse($a, true)));
    }

    public function test_job_serializes_its_requested_policy_instead_of_recomputing_worker_defaults(): void
    {
        $policy = EodSnapshotManifestBuilder::policy('2026-09-04', 0.35);
        $job = new RebuildEodSnapshotManifestJob((string) Str::uuid(), (string) Str::uuid(), 'SPY', 2, 'v2', $policy);
        config()->set('services.massive.eod_min_side_strike_ratio', 0.5);
        $restored = unserialize(serialize($job));
        $this->assertSame($policy, $restored->policy);
        $this->assertSame(90, $restored->timeout);
        $this->assertSame(3, $restored->tries);
    }

    public function test_invalid_policy_cannot_be_silently_reinterpreted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EodSnapshotManifestBuilder::policy('2026-02-30', 0.35);
    }

    public function test_valid_checksum_does_not_make_a_structurally_incomplete_manifest_safe(): void
    {
        $policy = EodSnapshotManifestBuilder::policy('2026-09-04', 0.35);
        $facts = (new EodSnapshotManifestBuilder)->fromRows('SPY', 1, 'v1', $policy,
            collect([(object) ['id' => 1, 'expiration_date' => '2026-09-11']]),
            collect([$this->row(1, '2026-09-04', 2, 2, 2)]));
        $head = ['symbol' => 'SPY', 'revision' => 1, 'cache_version' => 'v1'];
        $method = new \ReflectionMethod(EodSnapshotHealth::class, 'validatedFacts');
        foreach (['latest_unbounded_date', 'latest_gamma_date', 'selected_gamma_rows', 'selected_data_timestamp'] as $field) {
            $incomplete = $facts;
            unset($incomplete['expirations'][1][$field]);
            $json = EodSnapshotManifestBuilder::canonicalJson($incomplete);
            $row = (object) [
                'facts' => $json, 'facts_sha256' => hash('sha256', $json),
                'policy' => EodSnapshotManifestBuilder::canonicalJson($policy), 'anchor_date' => $policy['anchor_date'],
            ];
            $this->assertNull($method->invoke(new EodSnapshotHealth, $row, $head, $policy), $field);
        }
    }

    private function row(int $id, string $date, int $calls, int $puts, int $gamma): object
    {
        return (object) [
            'expiration_id' => $id, 'data_date' => $date,
            'call_strikes_n' => $calls, 'put_strikes_n' => $puts, 'strike_count' => max($calls, $puts),
            'row_count' => $calls + $puts, 'gamma_rows' => $gamma,
            'missing_gamma_rows' => $calls + $puts - $gamma, 'latest_data_timestamp' => $date.' 20:15:00',
        ];
    }
}
