<?php

namespace Tests\Feature;

use App\Jobs\FetchOptionChainDataJob;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use App\Support\Expirations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\MySqlTestCase;

class EodWriterMutationTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
        config()->set([
            'eod_snapshot_health.enabled' => true,
            'provider_backpressure.enabled' => false,
            'services.massive.concurrency.enabled' => false,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fetch_fences_before_catalog_and_raw_writes_and_does_not_publish_prematurely(): void
    {
        $observed = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$observed): void {
            if (preg_match('/^insert.*`option_(expirations|chain_data)`/i', $sql)) {
                $this->assertSame(1, DB::table('eod_snapshot_mutations')->where('symbol', 'SPY')->where('status', 'active')->count());
                $observed++;
            }
        });
        $this->fetch()->handle();

        $this->assertSame(3, $observed);
        $this->assertDatabaseCount('option_chain_data', 4);
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $this->assertDatabaseHas('eod_snapshot_mutations', ['symbol' => 'SPY', 'status' => 'complete']);
        $this->assertNull(app(EodSnapshotHealth::class)->head('SPY'));
        $this->assertSame('initial', app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));
    }

    public function test_feature_off_preserves_all_raw_fields_and_has_no_health_queries(): void
    {
        config()->set('eod_snapshot_health.enabled', false);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'eod_snapshot_')) {
                $queries[] = $query->sql;
            }
        });
        $this->fetch('LEGACY')->handle();
        $this->assertSame([], $queries);
        config()->set('eod_snapshot_health.enabled', true);
        $this->fetch()->handle();

        $rows = fn (string $symbol): array => DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)->orderBy('e.expiration_date')->orderBy('o.option_type')
            ->get(['o.*', 'e.expiration_date'])->map(function ($row): array {
                $values = (array) $row;
                unset($values['id'], $values['expiration_id']);

                return $values;
            })->all();
        $this->assertSame($rows('LEGACY'), $rows('SPY'));
    }

    public function test_provider_incomplete_and_outside_window_do_not_dirty_or_write_anything(): void
    {
        foreach ([$this->fetch(complete: false), $this->fetch(dates: ['2027-01-15'])] as $job) {
            try {
                $job->handle();
                $this->fail('Expected incomplete fetch failure.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('incomplete', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('option_expirations', 0);
        $this->assertDatabaseCount('option_chain_data', 0);
        $this->assertDatabaseCount('eod_snapshot_mutations', 0);
    }

    public function test_partial_fetch_failure_is_dirty_and_only_exact_scope_retry_supersedes_it(): void
    {
        $inserts = 0;
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inserts, &$fail): void {
            if (str_starts_with($sql, 'insert into `option_chain_data`') && ++$inserts === 2 && $fail) {
                $fail = false;
                throw new RuntimeException('injected second expiry failure');
            }
        });
        try {
            $this->fetch()->handle();
            $this->fail('Expected partial failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected second expiry failure', $exception->getMessage());
        }
        $failed = DB::table('eod_snapshot_mutations')->first();
        $this->assertSame('failed', $failed->status);
        $this->assertDatabaseCount('option_chain_data', 2);
        $this->assertFalse(app(EodSnapshotHealth::class)->certify('SPY', 'partial', 1));

        $this->fetch(dates: ['2026-09-11'], mergeOnly: true)->handle();
        $this->assertDatabaseHas('eod_snapshot_mutations', ['id' => $failed->id, 'status' => 'failed']);
        $this->assertFalse(app(EodSnapshotHealth::class)->certify('SPY', 'different-scope', 2));

        $this->fetch(dates: ['2026-09-18', '2026-09-11'])->handle();
        $this->assertDatabaseHas('eod_snapshot_mutations', ['id' => $failed->id, 'status' => 'superseded']);
        $this->assertDatabaseCount('option_chain_data', 4);
        $this->assertTrue(app(EodSnapshotHealth::class)->certify('SPY', 'exact-retry', 3));
    }

    public function test_enabled_missing_schema_refuses_the_first_catalog_write(): void
    {
        Schema::partialMock()->shouldReceive('hasTable')->with('eod_snapshot_states')->andReturn(false);
        try {
            $this->fetch()->handle();
            $this->fail('Missing health schema must block raw writers.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('schema', strtolower($exception->getMessage()));
        }
        $this->assertDatabaseCount('option_expirations', 0);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_catalog_completion_publishes_gex_and_noop_does_not_advance_revision(): void
    {
        $this->assertSame(3, Expirations::ensureForward('SPY', 2));
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $head = app(EodSnapshotHealth::class)->head('SPY');
        $this->assertNotNull($head);
        $this->assertFalse($head['dirty']);
        $this->assertSame(0, Expirations::ensureForward('SPY', 2));
        $this->assertDatabaseCount('eod_snapshot_mutations', 1);
        $this->assertSame($head, app(EodSnapshotHealth::class)->head('SPY'));
        $this->assertSame('initial', app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_ACTIVITY, 'SPY'));
    }

    public function test_catalog_failure_keeps_partial_work_failed_without_publication(): void
    {
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inserts): void {
            if (str_starts_with($sql, 'insert into `option_expirations`') && ++$inserts === 2) {
                throw new RuntimeException('injected catalog failure');
            }
        });
        try {
            Expirations::ensureForward('SPY', 2);
            $this->fail('Expected catalog failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected catalog failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('option_expirations', 1);
        $this->assertDatabaseHas('eod_snapshot_mutations', ['symbol' => 'SPY', 'status' => 'failed']);
        $this->assertSame('initial', app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));
    }

    public function test_prune_dry_run_is_read_only_and_real_prune_keeps_exact_cutoff_and_publishes_fenced_symbols(): void
    {
        $this->seedPruneRows();
        $this->artisan('options:prune-chain-data', ['--days' => 2, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('option_chain_data', 4);
        $this->assertDatabaseCount('eod_snapshot_mutations', 0);

        $deletes = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$deletes): void {
            if (str_starts_with($sql, 'delete from `option_chain_data`')) {
                $this->assertSame(2, DB::table('eod_snapshot_mutations')->where('status', 'active')->count());
                $deletes[] = $sql;
            }
        });
        $this->artisan('options:prune-chain-data', ['--days' => 2, '--sleep-ms' => 0])->assertSuccessful();
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('`id` in (', $deletes[0]);
        $this->assertStringContainsString('`data_date` < ?', $deletes[0]);
        $this->assertDatabaseCount('option_chain_data', 1);
        $this->assertDatabaseHas('option_chain_data', ['data_date' => '2026-09-04']);
        foreach (['SPY', 'QQQ'] as $symbol) {
            $this->assertFalse(app(EodSnapshotHealth::class)->head($symbol)['dirty']);
        }
        $this->assertNull(app(EodSnapshotHealth::class)->head('AAPL'));
    }

    public function test_failed_prune_keeps_all_begun_symbols_dirty_without_cache_publication(): void
    {
        $this->seedPruneRows();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'delete from `option_chain_data`')) {
                throw new RuntimeException('injected prune failure');
            }
        });
        try {
            $this->artisan('options:prune-chain-data', ['--days' => 2, '--sleep-ms' => 0])->run();
            $this->fail('Expected prune failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected prune failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('option_chain_data', 4);
        $this->assertSame(2, DB::table('eod_snapshot_mutations')->where('status', 'failed')->count());
        foreach (['SPY', 'QQQ'] as $symbol) {
            $this->assertSame('initial', app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, $symbol));
        }
    }

    public function test_feature_off_prune_keeps_original_global_limited_delete_without_health_queries(): void
    {
        $this->seedPruneRows();
        config()->set('eod_snapshot_health.enabled', false);
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $this->artisan('options:prune-chain-data', ['--days' => 2, '--sleep-ms' => 0])->assertSuccessful();
        $this->assertCount(2, array_filter($sql, fn ($query) => str_starts_with($query, 'delete from `option_chain_data` where `data_date` < ? limit 50000')));
        $this->assertSame([], array_values(array_filter($sql, fn ($query) => str_contains($query, 'eod_snapshot_'))));
        $this->assertDatabaseCount('option_chain_data', 1);
    }

    private function fetch(string $symbol = 'SPY', bool $complete = true, array $dates = ['2026-09-11', '2026-09-18'], bool $mergeOnly = false): FetchOptionChainDataJob
    {
        return new class([$symbol], 30, '2026-09-04', null, $mergeOnly, $dates, $complete) extends FetchOptionChainDataJob
        {
            public function __construct(array $symbols, int $days, string $targetDate, ?array $scope, bool $mergeOnly, private array $dates, private bool $complete)
            {
                parent::__construct($symbols, $days, $targetDate, null, $scope, $mergeOnly);
            }

            protected function fetchChain(string $symbol, ?Carbon $windowStart = null, ?Carbon $windowEnd = null): array
            {
                $option = ['strike' => 500, 'openInterest' => 100, 'volume' => 50, 'impliedVolatility' => 0.2, 'apiGamma' => 0.01, 'apiDelta' => 0.5, 'apiVega' => 0.1];

                return [500.0, array_map(fn ($date) => ['expirationDate' => $date, 'options' => ['CALL' => [$option], 'PUT' => [$option]]], $this->dates), ['provider_complete' => $this->complete]];
            }
        };
    }

    private function seedPruneRows(): void
    {
        foreach (['SPY', 'QQQ', 'AAPL'] as $symbol) {
            $id = DB::table('option_expirations')->insertGetId(['symbol' => $symbol, 'expiration_date' => '2026-09-11']);
            foreach ($symbol === 'AAPL' ? ['2026-09-04'] : ($symbol === 'SPY' ? ['2026-09-01', '2026-09-02'] : ['2026-09-03']) as $date) {
                DB::table('option_chain_data')->insert(['expiration_id' => $id, 'data_date' => $date, 'option_type' => 'call', 'strike' => 500, 'volume' => 50]);
            }
        }
    }
}
