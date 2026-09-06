<?php

namespace Tests\Feature;

use App\Support\IntradayReadIndex;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\MySqlTestCase;

class IntradayReadIndexTest extends MySqlTestCase
{
    private string $table;

    private const FREE_BYTES = 2_147_483_648;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = 'gex_intraday_idx_test_'.bin2hex(random_bytes(8));
        Schema::create($this->table, function (Blueprint $t): void {
            $t->id();
            $t->string('symbol', 16)->index();
            $t->string('contract_symbol', 32)->index();
            $t->enum('contract_type', ['call', 'put'])->index();
            $t->date('expiration_date')->index();
            $t->decimal('strike_price', 12, 4)->index();
            $t->unsignedBigInteger('volume')->nullable();
            $t->unsignedBigInteger('open_interest')->nullable();
            $t->decimal('implied_volatility', 12, 6)->nullable();
            $t->timestamp('captured_at')->index();
            $t->timestamps();
            $t->unique(['contract_symbol', 'captured_at'], 'u_contract_at');
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->table) && preg_match('/^gex_intraday_idx_test_[a-f0-9]{16}$/D', $this->table)) {
            // Only this test's randomly named fixture is removed. The service
            // and command contain no removal or destructive rollback path.
            Schema::dropIfExists($this->table);
        }
        parent::tearDown();
    }

    public function test_default_command_is_select_only_and_does_not_claim_operator_evidence_is_verified(): void
    {
        $this->app->instance(IntradayReadIndex::class, $this->index());
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertSame(0, Artisan::call('gex:indexes:intraday'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['dry_run']);
        $this->assertSame('planned', $report['status']);
        $this->assertFalse($report['backup_verified_by_command']);
        $this->assertFalse($report['database_free_bytes_verified_by_command']);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select/i', $query);
        }
        $this->assertStringContainsString('ALGORITHM=INPLACE, LOCK=NONE', $report['sql']);
        $this->assertStringContainsString('MAX_EXECUTION_TIME(3000)', implode(' ', $queries));
        $this->assertNull($report['compatible_index']);
    }

    public function test_apply_requires_backup_disk_and_row_admission_and_rejects_unsafe_table_names(): void
    {
        foreach ([['', self::FREE_BYTES], ['operator-test-reference', 0]] as [$backup, $free]) {
            try {
                $this->index()->apply($backup, $free);
                $this->fail('Missing operator preflight must refuse apply.');
            } catch (InvalidArgumentException|RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->seedRows(12);
        $index = new IntradayReadIndex($this->table, 10);
        $this->assertSame(11, $index->inspect()['actual_rows_up_to_cap']);
        $this->assertFalse($index->inspect()['row_count_complete']);
        try {
            $index->apply('operator-test-reference', self::FREE_BYTES);
            $this->fail('The tightened row cap must be enforced.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('row cap', $exception->getMessage());
        }
        $this->assertNull($this->index()->inspect()['compatible_index']);
        $this->expectException(InvalidArgumentException::class);
        new IntradayReadIndex('prices_daily');
    }

    public function test_a_visible_full_column_left_prefix_is_idempotent_without_adding_a_duplicate(): void
    {
        DB::statement('ALTER TABLE `'.$this->table.'` ADD INDEX `existing_compatible` (`symbol`,`captured_at`,`strike_price`,`volume`)');
        $report = $this->index()->apply('operator-test-reference', self::FREE_BYTES);
        $this->assertSame('already_present', $report['status']);
        $this->assertSame('existing_compatible', $report['compatible_index']);
        $this->assertArrayNotHasKey(IntradayReadIndex::NAME, $report['existing_indexes']);
    }

    #[DataProvider('conflictingIndexes')]
    public function test_same_name_with_any_conflicting_definition_refuses_before_ddl(string $definition): void
    {
        DB::statement('ALTER TABLE `'.$this->table.'` ADD '.$definition);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicting definition');
        $this->index()->apply('operator-test-reference', self::FREE_BYTES);
    }

    public static function conflictingIndexes(): array
    {
        $name = '`'.IntradayReadIndex::NAME.'`';

        return [
            ['INDEX '.$name.' (`symbol`,`strike_price`,`captured_at`)'],
            ['INDEX '.$name.' (`symbol`(8),`captured_at`,`strike_price`)'],
            ['INDEX '.$name.' (`symbol`,`captured_at`,`strike_price`) INVISIBLE'],
            ['UNIQUE INDEX '.$name.' (`symbol`,`captured_at`,`strike_price`)'],
        ];
    }

    public function test_wrong_column_schema_and_active_transactions_refuse_apply(): void
    {
        DB::beginTransaction();
        try {
            $this->index()->apply('operator-test-reference', self::FREE_BYTES);
            $this->fail('DDL must not implicitly commit an application transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('transaction', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        DB::statement('ALTER TABLE `'.$this->table.'` MODIFY strike_price DECIMAL(12,3) NOT NULL');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reviewed schema');
        $this->index()->inspect();
    }

    public function test_metadata_lock_timeout_is_bounded_and_restored_on_failure(): void
    {
        $original = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
        config()->set('database.connections.intraday_index_holder', config('database.connections.'.DB::getDefaultConnection()));
        $holder = DB::connection('intraday_index_holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM `'.$this->table.'` LIMIT 1');
        $started = hrtime(true);
        try {
            $this->index()->apply('operator-test-reference', self::FREE_BYTES);
            $this->fail('The held metadata lock must cause a bounded DDL failure.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
            $this->assertLessThan(8, (hrtime(true) - $started) / 1_000_000_000);
            $this->assertSame($original, (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds);
        } finally {
            $holder->rollBack();
            DB::purge('intraday_index_holder');
        }
        $this->assertNull($this->index()->inspect()['compatible_index']);
    }

    public function test_100k_rows_preserve_hash_groups_upsert_identity_and_prune_plan_during_online_ingestion(): void
    {
        $this->seedRows(100_000);
        DB::statement('ANALYZE TABLE `'.$this->table.'`');
        $beforeReads = $this->readMeasurements();
        $writePayloads = $this->writePayloads();
        $beforeWrites = $this->writeMeasurements($writePayloads);
        $beforeHash = $this->hashRows();
        $beforeIndexes = $this->index()->inspect()['existing_indexes'];
        $worker = new Process([PHP_BINARY, base_path('tests/Support/IntradayIndexConcurrentWriter.php'), $this->table], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_URL' => '',
            'DB_HOST' => DB::connection()->getConfig('host'), 'DB_PORT' => (string) DB::connection()->getConfig('port'),
            'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $worker->setTimeout(30);
        $worker->start();
        try {
            $this->assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'WRITER_READY')), $worker->getErrorOutput());
            $this->assertTrue($worker->isRunning());
            $originalTimeout = (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
            $applied = $this->index()->apply('local-fixture-no-production-data', self::FREE_BYTES);
            $this->assertSame('applied', $applied['status']);
            $this->assertSame($originalTimeout, (int) DB::selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds);
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->assertSame(2000, DB::table($this->table)->where('symbol', 'CONCURRENT')->count());
        $this->assertSame(1_999_000, (int) DB::table($this->table)->where('symbol', 'CONCURRENT')->sum('volume'));
        $afterHash = $this->hashRows();
        $this->assertSame($beforeHash, $afterHash);
        DB::statement('ANALYZE TABLE `'.$this->table.'`');
        $afterReads = $this->readMeasurements();
        foreach (['SPY', 'AAPL'] as $symbol) {
            $this->assertSame($beforeReads[$symbol]['sha256'], $afterReads[$symbol]['sha256']);
            $this->assertSame(IntradayReadIndex::NAME, $afterReads[$symbol]['explain'][0]['key']);
            $this->assertLessThan($beforeReads[$symbol]['explain'][0]['rows'], $afterReads[$symbol]['explain'][0]['rows']);
        }
        $afterWrites = $this->writeMeasurements($writePayloads);
        $this->assertSame(102_000, DB::table($this->table)->count());
        $this->assertSame(2000, DB::table($this->table)->where('symbol', 'CONCURRENT')->count());
        $this->assertSame(1_999_000, (int) DB::table($this->table)->where('symbol', 'CONCURRENT')->sum('volume'));
        $this->assertSame(500_500, (int) DB::table($this->table)->where('symbol', 'SPY')->where('id', '<=', 1000)->sum('volume'));
        $this->assertSame($beforeHash, $this->hashRows(), 'All timed write samples must leave the original rows unchanged.');
        foreach (array_keys($writePayloads) as $operation) {
            $this->assertSame($beforeWrites[$operation]['sql_and_payload_sha256'], $afterWrites[$operation]['sql_and_payload_sha256']);
        }
        $afterIndexes = $this->index()->inspect()['existing_indexes'];
        foreach ($beforeIndexes as $name => $parts) {
            $this->assertSame($parts, $afterIndexes[$name]);
        }
        $this->assertSame('already_present', $this->index()->apply('local-fixture', self::FREE_BYTES)['status']);
        $prune = DB::select('EXPLAIN SELECT id FROM `'.$this->table.'` WHERE captured_at < ? LIMIT 1000', ['2026-09-08 15:00:00']);
        $this->assertStringContainsString('captured_at_index', $prune[0]->key);
        fwrite(STDOUT, "\nINTRADAY_INDEX_BENCHMARK ".json_encode(['seed_rows' => 100000, 'concurrent_rows' => 2000,
            'data_sha256_before' => $beforeHash, 'data_sha256_after' => $afterHash, 'reads_before' => $beforeReads,
            'reads_after' => $afterReads, 'upserts_before' => $beforeWrites, 'upserts_after' => $afterWrites,
            'ddl_duration_ms' => $applied['ddl_duration_ms'], 'prune_explain' => $prune], JSON_THROW_ON_ERROR)."\n");
    }

    private function index(): IntradayReadIndex
    {
        return new IntradayReadIndex($this->table);
    }

    private function seedRows(int $count): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $symbol = $i <= 80000 ? 'SPY' : ($i <= 90000 ? 'AAPL' : 'QQQ');
            $rows[] = ['symbol' => $symbol, 'contract_symbol' => 'O:IDX'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'contract_type' => $i % 2 ? 'call' : 'put', 'expiration_date' => '2026-09-11', 'strike_price' => 100 + ($i % 200) / 2,
                'captured_at' => $symbol === 'QQQ' || $i % 1000 < 20 ? '2026-09-08 16:00:00' : '2026-09-08 13:00:00',
                'implied_volatility' => $i % 11 === 0 ? null : number_format(0.1 + ($i % 80) / 1000, 6, '.', ''),
                'volume' => $i, 'open_interest' => $i * 2];
            if (count($rows) === 1000) {
                DB::table($this->table)->insert($rows);
                $rows = [];
            }
        }
        if ($rows) {
            DB::table($this->table)->insert($rows);
        }
    }

    private function readMeasurements(): array
    {
        $report = [];
        foreach (['SPY', 'AAPL'] as $symbol) {
            $sql = 'SELECT strike_price AS strike, AVG(implied_volatility) AS iv FROM `'.$this->table.'` WHERE symbol = ? AND captured_at >= ? AND implied_volatility IS NOT NULL GROUP BY strike_price';
            $bindings = [$symbol, '2026-09-08 15:48:00'];
            $times = [];
            for ($i = 0; $i < 7; $i++) {
                $start = hrtime(true);
                $rows = DB::select($sql, $bindings);
                $times[] = (hrtime(true) - $start) / 1_000_000;
            }
            usort($rows, static fn ($a, $b): int => (float) $a->strike <=> (float) $b->strike);
            sort($times);
            $report[$symbol] = ['median_ms' => round($times[3], 3), 'p95_ms' => round($times[6], 3),
                'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
                'explain' => array_map(static fn ($row): array => (array) $row, DB::select('EXPLAIN '.$sql, $bindings))];
        }

        return $report;
    }

    private function writePayloads(): array
    {
        $rows = DB::table($this->table)->where('symbol', 'SPY')->orderBy('id')->limit(1000)->get()
            ->map(static function ($row): array {
                $row = (array) $row;
                unset($row['id']);

                return $row;
            })->all();
        $corrections = $inserts = [];
        foreach ($rows as $i => $row) {
            $corrections[] = array_replace($row, ['volume' => 1_000_000]);
            // Same-width keys which are absent before every rolled-back sample.
            $inserts[] = array_replace($row, ['contract_symbol' => 'O:NEW'.str_pad((string) ($i + 1), 10, '0', STR_PAD_LEFT)]);
        }

        return ['correction_upsert' => $corrections, 'fresh_insert_upsert' => $inserts];
    }

    private function writeMeasurements(array $payloads): array
    {
        $times = $fingerprints = [];
        $capturing = false;
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$capturing, &$statements): void {
            if ($capturing && str_starts_with($query->sql, 'insert into `'.$this->table.'`')) {
                $statements[] = [$query->sql, $query->bindings];
            }
        });
        DB::beginTransaction();
        try {
            // Match baseline cardinality without removing any ingestion proof:
            // this test-owned temporary exclusion is rolled back below.
            DB::table($this->table)->where('symbol', 'CONCURRENT')->delete();
            $this->assertSame(100_000, DB::table($this->table)->count());
            for ($sample = -1; $sample < 7; $sample++) {
                $operations = array_keys($payloads);
                if ($sample % 2 !== 0) {
                    $operations = array_reverse($operations);
                }
                foreach ($operations as $operation) {
                    DB::beginTransaction();
                    try {
                        $statements = [];
                        $capturing = true;
                        $start = hrtime(true);
                        foreach (array_chunk($payloads[$operation], 250) as $chunk) {
                            DB::table($this->table)->upsert($chunk, ['contract_symbol', 'captured_at'], ['volume']);
                        }
                        $milliseconds = (hrtime(true) - $start) / 1_000_000;
                        $capturing = false;
                        $this->assertCount(4, $statements);
                        $fingerprint = hash('sha256', json_encode($statements, JSON_THROW_ON_ERROR));
                        $fingerprints[$operation] ??= $fingerprint;
                        $this->assertSame($fingerprints[$operation], $fingerprint);
                        $this->assertSame($operation === 'fresh_insert_upsert' ? 101_000 : 100_000, DB::table($this->table)->count());
                        if ($operation === 'correction_upsert') {
                            $this->assertSame(1000, DB::table($this->table)->where('symbol', 'SPY')->where('volume', 1_000_000)->count());
                        } else {
                            $this->assertSame(1000, DB::table($this->table)->where('contract_symbol', 'like', 'O:NEW%')->count());
                        }
                        if ($sample >= 0) {
                            $times[$operation][] = $milliseconds;
                        }
                    } finally {
                        $capturing = false;
                        DB::rollBack();
                    }
                }
            }
        } finally {
            DB::rollBack();
        }
        $report = [];
        foreach ($times as $operation => $samples) {
            $ordered = $samples;
            sort($ordered);
            $report[$operation] = [
                'rows_per_sample' => 1000, 'table_rows_before_sample' => 100000,
                'statements_per_sample' => 4, 'sample_count' => count($samples), 'warmup_count' => 1,
                'median_ms' => round($ordered[3], 3), 'p95_ms' => round($ordered[6], 3),
                'p95_method' => 'nearest_rank_seven_samples_maximum',
                'samples_ms' => array_map(static fn (float $ms): float => round($ms, 3), $samples),
                'sql_and_payload_sha256' => $fingerprints[$operation],
                'timing_scope' => 'four_upsert_statements_only_excludes_transaction_commit_rollback_and_verification',
            ];
        }

        return $report;
    }

    private function hashRows(): string
    {
        $hash = hash_init('sha256');
        foreach (DB::table($this->table)->where('symbol', '!=', 'CONCURRENT')->orderBy('id')->cursor() as $row) {
            hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
    }
}
