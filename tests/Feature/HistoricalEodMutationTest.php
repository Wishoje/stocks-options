<?php

namespace Tests\Feature;

use App\Services\HistoricalEodRecoveryService;
use App\Support\EodSnapshotHealth;
use App\Support\WorkRunCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;

/** Re-run the existing exact-recovery contract with durable writer fences on. */
class HistoricalEodMutationTest extends HistoricalEodRecoveryServiceTest
{
    protected function setUp(): void
    {
        $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');
        if (! is_string($database) || ! preg_match('/_(test|testing)$/i', $database)) {
            throw new RuntimeException('Mutation integration tests require an explicit *_test database.');
        }
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('Mutation integration tests require MySQL.');
        }
        // The inherited failure fixtures create MySQL triggers, so an outer
        // RefreshDatabase transaction would be implicitly committed by DDL.
        if (! Schema::hasTable('eod_snapshot_states')) {
            (require database_path('migrations/2026_09_06_170000_create_eod_snapshot_health_tables.php'))->up();
        }
        $this->clearManifestRebuildWork();
        DB::table('eod_snapshot_manifests')->delete();
        DB::table('eod_snapshot_mutations')->delete();
        DB::table('eod_snapshot_states')->delete();
        config()->set('eod_snapshot_health.enabled', true);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->clearManifestRebuildWork();
        DB::table('eod_snapshot_manifests')->delete();
        DB::table('eod_snapshot_mutations')->delete();
        DB::table('eod_snapshot_states')->delete();
        parent::tearDown();
    }

    private function clearManifestRebuildWork(): void
    {
        if (! preg_match('/_(test|testing)$/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('Recovery fixture cleanup requires an explicit *_test database.');
        }
        // Queue::fake stops delivery, not the durable publication intent. This
        // autocommit fixture owns this new kind, but never unrelated work kinds.
        DB::transaction(function (): void {
            if (Schema::hasTable('work_run_slots')) {
                DB::table('work_run_slots')->where('kind', 'eod_manifest_rebuild')->delete();
            }
            if (Schema::hasTable('work_runs')) {
                DB::table('work_runs')->where('kind', 'eod_manifest_rebuild')->delete();
            }
        });
    }

    public function test_manifest_work_cleanup_preserves_unrelated_run_and_slot(): void
    {
        $coordinator = app(WorkRunCoordinator::class);
        $unrelated = $coordinator->claim('calculator_refresh', 'FIXTUREKEEP', ['fixture' => 'recovery-cleanup'], 'calculator', applyAdmissionLimits: false)['run'];
        try {
            $coordinator->claim('eod_manifest_rebuild', 'SPY', ['fixture' => 'recovery-cleanup'], 'default', applyAdmissionLimits: false);
            $this->clearManifestRebuildWork();
            $this->assertSame(0, DB::table('work_runs')->where('kind', 'eod_manifest_rebuild')->count());
            $this->assertSame(0, DB::table('work_run_slots')->where('kind', 'eod_manifest_rebuild')->count());
            $this->assertDatabaseHas('work_runs', ['id' => $unrelated->id]);
            $this->assertDatabaseHas('work_run_slots', ['key' => $unrelated->slot_key, 'current_run_id' => $unrelated->id]);
        } finally {
            DB::table('work_run_slots')->where('key', $unrelated->slot_key)->delete();
            DB::table('work_runs')->where('id', $unrelated->id)->delete();
        }
    }

    public function test_publish_resumes_a_prepared_intent_when_exact_candidate_rows_already_exist(): void
    {
        parent::test_publish_resumes_a_prepared_intent_when_exact_candidate_rows_already_exist();
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $this->assertDatabaseHas('eod_snapshot_mutations', ['symbol' => 'SPY', 'status' => 'complete']);
        $this->assertFalse(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
    }

    public function test_publish_resumes_cache_fencing_without_reinserting_exact_rows(): void
    {
        parent::test_publish_resumes_cache_fencing_without_reinserting_exact_rows();
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $this->assertSame(0, DB::table('eod_snapshot_mutations')->where('status', 'active')->count());
        $this->assertFalse(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
    }

    public function test_rollback_resumes_a_prepared_intent_when_exact_receipt_rows_are_already_absent(): void
    {
        parent::test_rollback_resumes_a_prepared_intent_when_exact_receipt_rows_are_already_absent();
        $this->assertDatabaseCount('eod_snapshot_mutations', 2);
        $this->assertSame(2, DB::table('eod_snapshot_mutations')->where('status', 'complete')->count());
        $this->assertFalse(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
    }

    public function test_rollback_resumes_cache_fencing_without_redeleting_rows(): void
    {
        parent::test_rollback_resumes_cache_fencing_without_redeleting_rows();
        $this->assertDatabaseCount('eod_snapshot_mutations', 2);
        $this->assertSame(2, DB::table('eod_snapshot_mutations')->where('status', 'complete')->count());
        $this->assertFalse(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
    }

    public function test_raw_transaction_failure_retains_one_failed_receipt_and_exact_retry_reuses_it(): void
    {
        // Reuse the immutable archive/provider fixture already exercised by the
        // inherited recovery suite without changing production artifact formats.
        [$directory, $validation] = (new ReflectionMethod(HistoricalEodRecoveryServiceTest::class, 'validatedRun'))->invoke($this);
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inserts): void {
            if (str_starts_with($sql, 'insert into `option_chain_data`')) {
                $this->assertSame(1, DB::table('eod_snapshot_mutations')->where('symbol', 'SPY')->where('status', 'active')->count());
                if (++$inserts === 1) {
                    throw new RuntimeException('injected exact recovery transaction failure');
                }
            }
        });
        $service = app(HistoricalEodRecoveryService::class);
        try {
            $service->publish($directory, $validation['candidate_sha256']);
            $this->fail('Expected exact recovery transaction failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected exact recovery transaction failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('option_chain_data', 0);
        $this->assertDatabaseCount('option_expirations', 0);
        $failed = DB::table('eod_snapshot_mutations')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->recovery_key);

        $result = $service->publish($directory, $validation['candidate_sha256']);
        $this->assertTrue($result['ok']);
        $this->assertDatabaseCount('option_chain_data', 4);
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $this->assertDatabaseHas('eod_snapshot_mutations', ['id' => $failed->id, 'status' => 'complete']);
        $this->assertFalse(app(EodSnapshotHealth::class)->head('SPY')['dirty']);
    }
}
