<?php

namespace Tests\Feature;

use App\Support\CalculatorPruneArchive;
use App\Support\CalculatorPublicationPruner;
use App\Support\CalculatorPublicationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class CalculatorPublicationRetentionTest extends TestCase
{
    private string $archives;

    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_08_16_000003_create_calculator_publication_tables.php');
        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(app()->environment('testing'));
            $this->assertMatchesRegularExpression('/_test$/', DB::getDatabaseName());
            $this->assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost', '::1']);
            $migration->down();
        } else {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
        }
        config(['cache.default' => 'array']);
        $migration->up();
        $this->archives = sys_get_temp_dir().'/gex-retention-'.bin2hex(random_bytes(8));
        config(['maintenance.calculator_archive_path' => $this->archives]);
        $this->travelTo(CarbonImmutable::parse('2026-10-23T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->archives)) {
            File::deleteDirectory($this->archives);
        }
        parent::tearDown();
    }

    private function publish(string $label, array $expiries): array
    {
        $repository = app(CalculatorPublicationRepository::class);
        $at = CarbonImmutable::parse('2026-10-01T14:00:00Z')->addMinutes((int) substr($label, -1));
        $run = $repository->startCatalogRun('PRUNE', ownerKey: $label, at: $at);
        $repository->freezeCatalog($run['id'], $expiries, 'test', $at, terminalCursorReached: true, at: $at);
        foreach ($expiries as $expiry) {
            $repository->stageAndPublishExpiry($run['id'], $expiry, 'test', $at, $at,
                [['ticker' => $label.'-'.$expiry, 'type' => 'call', 'strike' => 100, 'bid' => 1, 'ask' => 1.2, 'mid' => 1.1]], $at);
        }
        $repository->completeCatalog($run['id'], $at);

        return $run;
    }

    private function fixture(): array
    {
        // The first run must remain because its second expiry is still current.
        $first = $this->publish('old-1', ['2026-10-30', '2026-11-20']);
        $this->publish('old-2', ['2026-10-30']);
        $this->publish('old-3', ['2026-10-30']);
        $this->publish('old-4', ['2026-10-30']);
        $candidate = DB::table('calculator_expiry_publications')->where('run_id', $first['id'])->where('expiration', '2026-10-30')->first();

        return [$first, $candidate, CarbonImmutable::now('UTC')->subDays(7)];
    }

    public function test_individual_cleanup_archives_stale_siblings_without_changing_any_current_or_rollback_pointer(): void
    {
        [$first, $candidate, $cutoff] = $this->fixture();
        $heads = DB::table('calculator_expiry_heads')->orderBy('id')->get()->toJson();
        $catalogs = DB::table('calculator_catalog_heads')->get()->toJson();
        $originalRows = DB::table('calculator_expiry_publication_rows')->where('publication_id', $candidate->id)->get()->toArray();
        $pruner = app(CalculatorPublicationPruner::class);
        $selected = $pruner->candidates($cutoff);
        $this->assertCount(2, $selected);
        $result = $pruner->prune($selected, $cutoff);
        $this->assertSame(2, $result['publications']);
        $this->assertSame(2, $result['rows']);
        $this->assertSame($heads, DB::table('calculator_expiry_heads')->orderBy('id')->get()->toJson());
        $this->assertSame($catalogs, DB::table('calculator_catalog_heads')->get()->toJson());
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $first['id']]);
        $this->assertDatabaseHas('calculator_run_expirations', ['run_id' => $first['id'], 'expiration' => '2026-10-30', 'publication_id' => null, 'readiness' => 'pruned']);
        $this->assertDatabaseMissing('calculator_expiry_publications', ['id' => $candidate->id]);
        $decoded = gzdecode(file_get_contents($result['archive']['path']));
        $this->assertSame(hash('sha256', $decoded), $result['archive']['decoded_sha256']);
        $this->assertSame(hash_file('sha256', $result['archive']['path']), $result['archive']['sha256']);
        $lines = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $decoded)));
        $contracts = array_values(array_filter($lines, fn ($line) => $line['kind'] === 'contract' && $line['row']['publication_id'] === $candidate->id));
        $this->assertEquals((array) $originalRows[0], $contracts[0]['row']);
        $this->assertCount(3, DB::table('calculator_expiry_publication_rows')->get());
    }

    public function test_dry_run_does_not_delete_or_create_archives(): void
    {
        $this->fixture();
        $this->artisan('calculator:prune-publications --dry-run')->assertSuccessful();
        $this->assertSame(5, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertFalse(is_dir($this->archives));
    }

    public function test_recent_or_incomplete_runs_and_recent_publications_are_protected(): void
    {
        [$first, $candidate, $cutoff] = $this->fixture();
        DB::table('calculator_expiry_publications')->where('id', $candidate->id)->update(['created_at' => now('UTC')]);
        $pruner = app(CalculatorPublicationPruner::class);
        $this->assertNotContains($candidate->id, array_column($pruner->candidates($cutoff), 'id'));
        DB::table('calculator_expiry_publications')->where('id', $candidate->id)->update(['created_at' => '2026-10-01 14:01:00']);
        DB::table('calculator_publication_runs')->where('id', $first['id'])->update(['status' => 'partial', 'completed_at' => null]);
        $this->assertNotContains($candidate->id, array_column($pruner->candidates($cutoff), 'id'));
    }

    public function test_candidates_that_become_current_or_rollback_are_rechecked_before_archiving(): void
    {
        [$first, $candidate, $cutoff] = $this->fixture();
        $pruner = app(CalculatorPublicationPruner::class);
        $selected = array_values(array_filter($pruner->candidates($cutoff), fn ($p) => $p['id'] === $candidate->id));
        DB::table('calculator_expiry_heads')->where('expiration', '2026-10-30')->update(['previous_publication_id' => $candidate->id]);
        $this->assertSame(0, $pruner->prune($selected, $cutoff)['rows']);
        DB::table('calculator_catalog_heads')->where('symbol', 'PRUNE')->update(['previous_run_id' => $first['id']]);
        DB::table('calculator_expiry_heads')->where('expiration', '2026-10-30')->update(['previous_publication_id' => null]);
        $this->assertSame(0, $pruner->prune($selected, $cutoff)['rows']);
        $this->assertFalse(is_dir($this->archives));
    }

    public function test_cross_run_references_and_revised_cutoffs_are_rechecked_under_locks(): void
    {
        [$first, $candidate, $cutoff] = $this->fixture();
        $pruner = app(CalculatorPublicationPruner::class);
        $selected = array_values(array_filter($pruner->candidates($cutoff), fn ($p) => $p['id'] === $candidate->id));
        DB::table('calculator_run_expirations')->where('run_id', '<>', $first['id'])->limit(1)->update(['publication_id' => $candidate->id]);
        $this->assertSame(0, $pruner->prune($selected, $cutoff)['rows']);
        DB::table('calculator_run_expirations')->where('publication_id', $candidate->id)->where('run_id', '<>', $first['id'])->update(['publication_id' => null]);
        DB::table('calculator_publication_runs')->where('id', $first['id'])->update(['completed_at' => now('UTC')]);
        $this->assertSame(0, $pruner->prune($selected, $cutoff)['rows']);
        $this->assertFalse(is_dir($this->archives));
    }

    public function test_archive_failures_or_bad_row_counts_prevent_deletion(): void
    {
        [, $candidate, $cutoff] = $this->fixture();
        DB::table('calculator_expiry_publications')->where('id', $candidate->id)->update(['row_count' => 2]);
        $pruner = app(CalculatorPublicationPruner::class);
        try {
            $pruner->prune(array_values(array_filter($pruner->candidates($cutoff), fn ($p) => $p['id'] === $candidate->id)), $cutoff);
            $this->fail('Invalid archive count must block deletion.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row count', $e->getMessage());
        }
        $this->assertDatabaseHas('calculator_expiry_publications', ['id' => $candidate->id]);
        $this->assertSame(5, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame([], glob($this->archives.'/*.gz'));
        $this->app->instance(CalculatorPruneArchive::class, new class
        {
            public function write(array $publications, array $runs, array $references): array
            {
                throw new RuntimeException('Archive unavailable');
            }
        });
        DB::table('calculator_expiry_publications')->where('id', $candidate->id)->update(['row_count' => 1]);
        try {
            $pruner->prune(array_values(array_filter($pruner->candidates($cutoff), fn ($p) => $p['id'] === $candidate->id)), $cutoff);
            $this->fail('Archive failure must block deletion.');
        } catch (RuntimeException $e) {
            $this->assertSame('Archive unavailable', $e->getMessage());
        }
        $this->assertSame(5, DB::table('calculator_expiry_publication_rows')->count());
    }
}
