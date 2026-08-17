<?php

namespace Tests\Feature;

use App\Console\Commands\PruneCalculatorSnapshots;
use App\Support\CalculatorPublicationRepository;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneCalculatorPublicationsTest extends TestCase
{
    private CalculatorPublicationRepository $publications;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('calculator_publication_runs')) {
            $migration = require database_path(
                'migrations/2026_08_16_000003_create_calculator_publication_tables.php'
            );
            $migration->up();
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
                $table->decimal('underlying_price', 12, 2)->nullable();
                $table->timestamp('fetched_at');
            });
        }

        $this->clearPublicationTables();
        DB::table('option_snapshots')->where('symbol', 'PRUNE')->delete();
        $this->publications = app(CalculatorPublicationRepository::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('option_snapshots')->where('symbol', 'PRUNE')->delete();
        $this->clearPublicationTables();

        parent::tearDown();
    }

    public function test_prune_keeps_current_and_previous_pointers_and_removes_only_old_unreferenced_runs(): void
    {
        Carbon::setTestNow('2026-08-16 14:00:00 UTC');
        $first = $this->publish('first', '2026-07-01 14:00:00');
        $second = $this->publish('second', '2026-07-02 14:00:00');
        $current = $this->publish('current', '2026-07-03 14:00:00');

        $failed = $this->publications->startCatalogRun(
            'PRUNE',
            ownerKey: 'test:failed',
            at: CarbonImmutable::parse('2026-07-01 15:00:00', 'UTC')
        );
        $this->publications->markRunFailed(
            (string) $failed['id'],
            'provider_http_error',
            'test failure',
            CarbonImmutable::parse('2026-07-01 15:01:00', 'UTC')
        );

        DB::table('option_snapshots')->insert([
            'symbol' => 'PRUNE',
            'ticker' => 'LEGACY-OLD',
            'type' => 'call',
            'strike' => 100,
            'expiry' => '2026-09-18',
            'bid' => 1,
            'ask' => 1.2,
            'mid' => 1.1,
            'underlying_price' => 100,
            'fetched_at' => '2026-07-01 14:00:00',
        ]);

        $this->assertSame(0, Artisan::call('calculator:prune-snapshots', [
            '--hours' => 168,
            '--batch' => 1000,
            '--sleep-ms' => 0,
        ]));

        $this->assertDatabaseMissing('calculator_publication_runs', ['id' => $first['id']]);
        $this->assertDatabaseMissing('calculator_publication_runs', ['id' => $failed['id']]);
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $second['id']]);
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $current['id']]);
        $this->assertDatabaseHas('calculator_catalog_heads', [
            'symbol' => 'PRUNE',
            'current_run_id' => $current['id'],
            'previous_run_id' => $second['id'],
        ]);
        $this->assertDatabaseHas('calculator_symbol_generations', [
            'symbol' => 'PRUNE',
            'last_generation' => 4,
        ]);
        $this->assertSame(2, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(2, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'PRUNE')->count());
    }

    public function test_delete_rechecks_heads_when_a_selected_candidate_becomes_protected(): void
    {
        $first = $this->publish('first', '2026-07-01 14:00:00');
        $second = $this->publish('second', '2026-07-02 14:00:00');
        $third = $this->publish('third', '2026-07-03 14:00:00');

        $firstPublicationId = DB::table('calculator_expiry_publications')
            ->where('run_id', $first['id'])
            ->value('id');
        DB::table('calculator_catalog_heads')->where('symbol', 'PRUNE')->update([
            'current_run_id' => $first['id'],
            'previous_run_id' => $third['id'],
        ]);
        DB::table('calculator_expiry_heads')
            ->where('symbol', 'PRUNE')
            ->where('expiration', '2026-09-18')
            ->update([
                'current_publication_id' => $firstPublicationId,
                'previous_publication_id' => DB::table('calculator_expiry_publications')
                    ->where('run_id', $third['id'])
                    ->value('id'),
            ]);

        $command = new class extends PruneCalculatorSnapshots
        {
            /** @param list<string> $runIds */
            public function deleteSelected(array $runIds, CarbonImmutable $cutoff): int
            {
                return $this->deletePublicationCandidates($runIds, $cutoff);
            }
        };

        $this->assertSame(0, $command->deleteSelected(
            [(string) $first['id']],
            CarbonImmutable::parse('2026-08-09 14:00:00', 'UTC')
        ));
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $first['id']]);
        $this->assertDatabaseHas('calculator_expiry_publications', ['id' => $firstPublicationId]);
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $second['id']]);
    }

    public function test_delete_rechecks_the_retention_cutoff_under_the_run_lock(): void
    {
        $first = $this->publish('first', '2026-07-01 14:00:00');
        $this->publish('second', '2026-07-02 14:00:00');
        $this->publish('third', '2026-07-03 14:00:00');
        DB::table('calculator_publication_runs')->where('id', $first['id'])->update([
            'completed_at' => '2026-08-16 13:59:00',
        ]);

        $command = new class extends PruneCalculatorSnapshots
        {
            /** @param list<string> $runIds */
            public function deleteSelected(array $runIds, CarbonImmutable $cutoff): int
            {
                return $this->deletePublicationCandidates($runIds, $cutoff);
            }
        };

        $this->assertSame(0, $command->deleteSelected(
            [(string) $first['id']],
            CarbonImmutable::parse('2026-08-09 14:00:00', 'UTC')
        ));
        $this->assertDatabaseHas('calculator_publication_runs', ['id' => $first['id']]);
    }

    /** @return array<string,mixed> */
    private function publish(string $owner, string $timestamp): array
    {
        $at = CarbonImmutable::parse($timestamp, 'UTC');
        $run = $this->publications->startCatalogRun(
            'PRUNE',
            ownerKey: 'test:'.$owner,
            at: $at
        );
        $this->publications->freezeCatalog(
            (string) $run['id'],
            ['2026-09-18'],
            'test',
            $at,
            terminalCursorReached: true,
            at: $at
        );
        $this->publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-09-18',
            'test',
            $at,
            $at,
            [[
                'ticker' => strtoupper($owner),
                'type' => 'call',
                'strike' => 100,
                'bid' => 1,
                'ask' => 1.2,
                'mid' => 1.1,
            ]],
            $at
        );
        $this->publications->completeCatalog((string) $run['id'], $at);

        return $run;
    }

    private function clearPublicationTables(): void
    {
        foreach ([
            'calculator_expiry_heads',
            'calculator_catalog_heads',
            'calculator_run_expirations',
            'calculator_expiry_publication_rows',
            'calculator_expiry_publications',
            'calculator_publication_runs',
            'calculator_symbol_generations',
        ] as $table) {
            DB::table($table)->delete();
        }
    }
}
