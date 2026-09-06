<?php

namespace Tests\Feature;

use App\Jobs\RebuildEodSnapshotManifestJob;
use App\Models\WorkRun;
use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotManifestBuilder;
use App\Support\EodSnapshotSelector;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\MySqlTestCase;

class EodSnapshotHealthTest extends MySqlTestCase
{
    use RefreshDatabase;

    private EodSnapshotHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 14:00:00', 'UTC'));
        config()->set('eod_snapshot_health.enabled', true);
        config()->set('services.massive.eod_min_side_strike_ratio', 0.35);
        config()->set('queue_lanes.isolated', false);
        $this->health = new EodSnapshotHealth;
        $this->app->instance(EodSnapshotHealth::class, $this->health);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_no_unobserved_or_active_raw_history_can_be_certified(): void
    {
        $this->fixture();
        $this->assertFalse($this->health->certify('SPY', 'v1', 100));
        $token = $this->health->begin('SPY', 'fetch:2026-09-04');
        $this->assertFalse($this->health->certify('SPY', 'v1', 100));
        $this->health->complete($token);
        $this->assertTrue($this->health->certify('SPY', 'v1', 100));
        $this->assertNull($this->health->read('SPY', $this->policy()));
        $facts = $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $this->assertNotNull($facts);
        $this->assertNotNull($this->health->read('SPY', $this->policy()));
    }

    public function test_failed_scopes_require_successful_exact_retry_and_active_scopes_are_never_superseded(): void
    {
        $failed = $this->health->begin('SPY', 'scope-a');
        $this->health->fail($failed);
        $other = $this->health->begin('SPY', 'scope-b');
        $this->health->complete($other);
        $this->assertFalse($this->health->certify('SPY', 'v1', 100));
        $retry = $this->health->begin('SPY', 'scope-a');
        $this->health->complete($retry);
        $this->assertDatabaseHas('eod_snapshot_mutations', ['id' => $failed, 'status' => 'superseded', 'superseded_by' => $retry]);
        $this->assertTrue($this->health->certify('SPY', 'v1', 100));
        $active = $this->health->begin('SPY', 'scope-c');
        $same = $this->health->begin('SPY', 'scope-c');
        $this->health->complete($same);
        $this->travel(30)->days();
        $this->assertDatabaseHas('eod_snapshot_mutations', ['id' => $active, 'status' => 'active']);
        $this->assertFalse($this->health->certify('SPY', 'v2', 200));
    }

    public function test_publication_ordering_and_same_version_cannot_relabel_new_raw_mutations(): void
    {
        $this->certified();
        $token = $this->health->begin('SPY', 'next');
        $this->assertFalse($this->health->certify('SPY', 'v2', 200));
        $this->health->complete($token);
        $this->assertFalse($this->health->certify('SPY', 'v1', 100));
        $this->assertFalse($this->health->certify('SPY', 'older', 99));
        $this->assertTrue($this->health->certify('SPY', 'v2', 200));
        $this->assertTrue($this->health->certify('SPY', 'v2', 200));
        $this->assertSame(2, $this->health->head('SPY')['revision']);
    }

    public function test_dirty_revision_keeps_existing_materialization_available_only_for_warm_cache_reads(): void
    {
        $this->certified();
        $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $before = $this->health->read('SPY', $this->policy());
        $token = $this->health->begin('SPY', 'failed-next');
        $this->health->fail($token);
        $this->assertNull($this->health->read('SPY', $this->policy()));
        $warm = $this->health->read('SPY', $this->policy(), false);
        $this->assertTrue($warm['dirty']);
        $this->assertSame($before['manifest_id'], $warm['manifest_id']);
        $this->assertNull($this->health->requestRebuild('SPY', $this->policy()));
        $this->assertNull($this->health->rebuild('SPY', 1, 'v1', $this->policy()));
        Bus::assertNothingDispatched();
    }

    public function test_intervening_mutation_after_history_scan_prevents_manifest_insertion(): void
    {
        $this->certified();
        $result = $this->health->rebuild('SPY', 1, 'v1', $this->policy(), checkpoint: function (): void {
            $this->health->begin('SPY', 'concurrent-fetch');
        });
        $this->assertNull($result);
        $this->assertSame(0, DB::table('eod_snapshot_manifests')->count());
        $this->assertTrue($this->health->head('SPY')['dirty']);
    }

    public function test_corrupt_materialization_falls_back_then_is_atomically_repaired_without_changing_certificate(): void
    {
        $this->certified();
        $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $before = $this->health->read('SPY', $this->policy());
        $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $this->assertSame($before['manifest_id'], $this->health->read('SPY', $this->policy())['manifest_id']);
        DB::table('eod_snapshot_manifests')->update(['facts' => '{"broken":true}']);
        $this->assertNull($this->health->read('SPY', $this->policy()));
        $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $after = $this->health->read('SPY', $this->policy());
        $this->assertNotSame($before['manifest_id'], $after['manifest_id']);
        $this->assertSame($before['expirations'], $after['expirations']);
        $this->assertSame('v1', $after['cache_version']);
    }

    public function test_fresh_service_reads_with_exactly_two_metadata_queries_and_no_schema_or_history_discovery(): void
    {
        $this->certified();
        $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->assertNotNull((new EodSnapshotHealth)->read('SPY', $this->policy()));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('inner join', $queries[0]);
        $this->assertStringContainsString('eod_snapshot_states', $queries[1]);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('option_chain_data', $query);
            $this->assertStringNotContainsString('option_expirations', $query);
            $this->assertStringNotContainsString('information_schema', $query);
        }
        $queries = [];
        $this->assertNotNull((new EodSnapshotHealth)->read('SPY', $this->policy(), false));
        $this->assertCount(2, $queries);
    }

    public function test_missing_materialization_rebuild_requests_coalesce_and_keep_the_requested_ratio_on_worker(): void
    {
        $this->certified();
        $a = $this->health->requestRebuild('SPY', $this->policy());
        $b = $this->health->requestRebuild('SPY', $this->policy());
        $this->assertNotNull($a);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, WorkRun::query()->where('kind', 'eod_manifest_rebuild')->count());
        Bus::assertDispatchedTimes(RebuildEodSnapshotManifestJob::class, 1);
        $job = Bus::dispatched(RebuildEodSnapshotManifestJob::class)->first();
        config()->set('services.massive.eod_min_side_strike_ratio', 0.5);
        $job->handle($this->health, app(WorkRunCoordinator::class));
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertNotNull($this->health->read('SPY', $this->policy()));
        $this->assertNull($this->health->read('SPY', $this->policy(0.5)));
    }

    public function test_late_rebuild_delivery_cannot_publish_after_ownership_rotates(): void
    {
        $this->certified();
        $run = $this->health->requestRebuild('SPY', $this->policy());
        $run->refresh();
        $token = $run->delivery_token;
        $this->assertTrue(app(WorkRunCoordinator::class)->markStarted($run->id, $token, 1));
        $result = $this->health->rebuild('SPY', 1, 'v1', $this->policy(), [
            'run_id' => $run->id, 'delivery_token' => $token, 'attempt' => 1,
        ], function () use ($run): void {
            WorkRun::query()->whereKey($run->id)->update(['delivery_token' => (string) Str::uuid()]);
        });
        $this->assertNull($result);
        $this->assertSame(0, DB::table('eod_snapshot_manifests')->count());
    }

    public function test_recovery_receipt_resumes_exact_operation_and_complete_receipt_cannot_authorize_new_writes(): void
    {
        $sha = str_repeat('a', 64);
        $token = $this->health->beginRecovery('SPY', $sha, 'publish', false);
        $this->assertSame($token, $this->health->beginRecovery('SPY', $sha, 'publish', false));
        $this->health->fail($token);
        $this->assertSame($token, $this->health->beginRecovery('SPY', $sha, 'publish', true));
        $this->health->complete($token);
        $this->assertSame($token, $this->health->beginRecovery('SPY', $sha, 'publish', true));
        $this->assertSame(1, (int) DB::table('eod_snapshot_states')->where('symbol', 'SPY')->value('revision'));
        $this->expectException(RuntimeException::class);
        $this->health->beginRecovery('SPY', $sha, 'publish', false);
    }

    public function test_manifest_selected_dates_match_legacy_sql_including_integer_division_boundary(): void
    {
        $token = $this->health->begin('SPY', 'boundary-fixture');
        $id = $this->expiration();
        $rows = [];
        foreach (['call' => 351, 'put' => 1003] as $side => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = $this->chainRow($id, '2026-09-04', $side, $i);
            }
        }
        $rows[] = $this->chainRow($id, '2026-09-03', 'call', 100);
        $rows[] = $this->chainRow($id, '2026-09-03', 'put', 100);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
        $this->health->complete($token);
        $this->health->certify('SPY', 'v1', 100);
        config()->set('eod_snapshot_health.enabled', false);
        $selected = app(EodSnapshotSelector::class)->selectedDateRows([$id], '2026-09-04', 0.35)->first();
        $summary = app(EodSnapshotSelector::class)->summary([$id], '2026-09-04', 0.35)->get($id);
        config()->set('eod_snapshot_health.enabled', true);
        $facts = $this->health->rebuild('SPY', 1, 'v1', $this->policy());
        $this->assertSame($selected->max_date, $facts['expirations'][$id]['selected_date']);
        foreach ($summary as $key => $value) {
            $this->assertEquals($value, $facts['expirations'][$id][$key], $key);
        }
        $this->assertNotNull($this->health->read('SPY', $this->policy()));
    }

    private function certified(): void
    {
        $token = $this->health->begin('SPY', 'fixture');
        $this->fixture();
        $this->health->complete($token);
        $this->assertTrue($this->health->certify('SPY', 'v1', 100));
    }

    private function fixture(): void
    {
        $id = $this->expiration();
        DB::table('option_chain_data')->insert([
            $this->chainRow($id, '2026-09-04', 'call', 100),
            $this->chainRow($id, '2026-09-04', 'put', 100),
        ]);
    }

    private function expiration(): int
    {
        return DB::table('option_expirations')->insertGetId([
            'symbol' => 'SPY', 'expiration_date' => '2026-09-11', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function chainRow(int $id, string $date, string $side, int $strike): array
    {
        return [
            'expiration_id' => $id, 'data_date' => $date, 'option_type' => $side, 'strike' => $strike,
            'open_interest' => 100, 'volume' => 10, 'gamma' => 0.1, 'delta' => 0.5, 'iv' => 0.2,
            'underlying_price' => 100, 'data_timestamp' => $date.' 20:15:00', 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function policy(float $ratio = 0.35): array
    {
        return EodSnapshotManifestBuilder::policy('2026-09-04', $ratio);
    }
}
