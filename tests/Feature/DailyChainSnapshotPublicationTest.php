<?php

namespace Tests\Feature;

use App\Support\DailyChainSnapshotPublisher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DailyChainSnapshotPublicationTest extends TestCase
{
    private const DATE = '2026-08-14';

    /** @var array<string,int> */
    private array $expirationIds;

    private string $databasePath;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'gex013-');
        if ($database === false) {
            $this->fail('Unable to create the isolated GEX-013 SQLite database.');
        }

        $this->databasePath = $database;
        $this->originalConnection = DB::getDefaultConnection();
        $sqlite = config('database.connections.sqlite');
        $sqlite['database'] = $this->databasePath;
        config()->set('database.connections.daily_chain_snapshot_test', $sqlite);
        DB::setDefaultConnection('daily_chain_snapshot_test');
        DB::purge('daily_chain_snapshot_test');

        config()->set('daily_chain_snapshot.lock_wait_seconds', 0);
        config()->set('daily_chain_snapshot.insert_chunk_size', 1);

        $this->createTables();
        $this->seedSourceRows();
    }

    protected function tearDown(): void
    {
        DB::disconnect('daily_chain_snapshot_test');
        DB::purge('daily_chain_snapshot_test');
        DB::setDefaultConnection($this->originalConnection);
        @unlink($this->databasePath);

        parent::tearDown();
    }

    private function createTables(): void
    {
        Schema::create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('expiration_date');
            $table->timestamps();
        });

        Schema::create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->date('data_date');
            $table->string('option_type', 4);
            $table->decimal('strike', 8, 2);
            $table->bigInteger('open_interest')->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('gamma', 12, 8)->nullable();
            $table->decimal('delta', 12, 8)->nullable();
            $table->float('vega')->nullable();
            $table->decimal('iv', 12, 8)->nullable();
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('daily_chain_snapshot', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('data_date');
            $table->unsignedBigInteger('expiration_id');
            $table->bigInteger('call_oi')->default(0);
            $table->bigInteger('put_oi')->default(0);
            $table->bigInteger('call_vol')->default(0);
            $table->bigInteger('put_vol')->default(0);
            $table->double('sum_gamma')->default(0);
            $table->double('sum_delta')->default(0);
            $table->double('sum_vega')->default(0);
            $table->timestamps();
            $table->unique(['symbol', 'data_date', 'expiration_id']);
        });
    }

    public function test_interruption_at_each_swap_checkpoint_preserves_the_last_complete_snapshot(): void
    {
        foreach (['before_delete', 'after_delete', 'after_insert_chunk', 'after_insert', 'after_verify'] as $checkpoint) {
            $this->seedOldPublication();
            $before = $this->visibleRows();

            try {
                app(DailyChainSnapshotPublisher::class)->publish(
                    self::DATE,
                    static function (string $name) use ($checkpoint): void {
                        if ($name === $checkpoint) {
                            throw new RuntimeException("interrupted at {$checkpoint}");
                        }
                    }
                );
                $this->fail("Expected publication interruption at {$checkpoint}.");
            } catch (RuntimeException $exception) {
                $this->assertSame("interrupted at {$checkpoint}", $exception->getMessage());
            }

            $this->assertSame($before, $this->visibleRows(), $checkpoint);
        }
    }

    public function test_successful_publication_matches_the_legacy_aggregates_and_replay_is_idempotent(): void
    {
        $this->seedOldPublication();
        $publisher = app(DailyChainSnapshotPublisher::class);

        $first = $publisher->publish(self::DATE);
        $afterFirst = $this->visibleRows();

        $this->assertSame(3, $first['row_count']);
        $this->assertSame(['QQQ', 'SPY'], $first['symbols']);
        $this->assertSame($this->expectedRows(), $afterFirst);

        $second = $publisher->publish(self::DATE);

        $this->assertSame($first['checksum'], $second['checksum']);
        $this->assertSame($afterFirst, $this->visibleRows());
    }

    public function test_timeout_reported_after_commit_is_safe_to_replay(): void
    {
        $this->seedOldPublication();
        $publisher = app(DailyChainSnapshotPublisher::class);

        try {
            $publisher->publish(self::DATE, static function (string $name): void {
                if ($name === 'after_commit') {
                    throw new RuntimeException('caller timed out after commit');
                }
            });
            $this->fail('Expected the simulated post-commit timeout.');
        } catch (RuntimeException $exception) {
            $this->assertSame('caller timed out after commit', $exception->getMessage());
        }

        $this->assertSame($this->expectedRows(), $this->visibleRows());

        $replay = $publisher->publish(self::DATE);

        $this->assertSame(3, $replay['row_count']);
        $this->assertSame($this->expectedRows(), $this->visibleRows());
    }

    public function test_empty_candidate_cannot_erase_a_complete_snapshot(): void
    {
        $emptyDate = '2026-08-13';
        DB::table('daily_chain_snapshot')->insert($this->oldRow($emptyDate));
        $before = $this->visibleRows($emptyDate);

        try {
            app(DailyChainSnapshotPublisher::class)->publish($emptyDate);
            $this->fail('Expected an empty publication to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('empty publication', $exception->getMessage());
        }

        $this->assertSame($before, $this->visibleRows($emptyDate));
    }

    public function test_a_second_same_date_publisher_cannot_enter_while_the_shared_lock_is_held(): void
    {
        $publisher = app(DailyChainSnapshotPublisher::class);
        $lock = Cache::lock($publisher->lockName(self::DATE), 60);
        $this->assertTrue($lock->get());

        try {
            $publisher->publish(self::DATE);
            $this->fail('Expected the second publisher to time out on the shared lock.');
        } catch (LockTimeoutException) {
            $this->assertSame([], $this->visibleRows());
        } finally {
            $lock->release();
        }
    }

    public function test_a_separate_reader_keeps_seeing_the_old_complete_rows_until_commit(): void
    {
        $sqlite = config('database.connections.daily_chain_snapshot_test');
        config()->set('database.connections.snapshot_observer', $sqlite);
        DB::purge('snapshot_observer');

        try {
            $this->seedOldPublication();
            $oldRows = $this->visibleRows();
            $observedDuringSwap = null;

            app(DailyChainSnapshotPublisher::class)->publish(
                self::DATE,
                function (string $name) use (&$observedDuringSwap): void {
                    if ($name === 'after_delete') {
                        $observedDuringSwap = $this->visibleRows(self::DATE, 'snapshot_observer');
                    }
                }
            );

            $this->assertSame($oldRows, $observedDuringSwap);
            $this->assertSame($this->expectedRows(), $this->visibleRows());
        } finally {
            DB::disconnect('snapshot_observer');
            DB::purge('snapshot_observer');
        }
    }

    private function seedSourceRows(): void
    {
        $now = '2026-08-14 21:00:00';
        $this->expirationIds = [];

        foreach ([
            ['symbol' => 'SPY', 'expiration_date' => '2026-08-14'],
            ['symbol' => 'SPY', 'expiration_date' => '2026-08-21'],
            ['symbol' => 'QQQ', 'expiration_date' => '2026-08-14'],
        ] as $expiration) {
            $this->expirationIds[$expiration['symbol'].'|'.$expiration['expiration_date']] = (int) DB::table('option_expirations')->insertGetId([
                ...$expiration,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rows = [
            $this->chainRow('SPY', '2026-08-14', 'call', 500.0, 100, 10, 0.010, 0.50, 0.20),
            $this->chainRow('SPY', '2026-08-14', 'put', 500.0, 80, 12, 0.020, -0.40, 0.25),
            $this->chainRow('SPY', '2026-08-21', 'call', 505.0, 60, 8, 0.015, 0.45, 0.30),
            $this->chainRow('SPY', '2026-08-21', 'put', 495.0, 40, 6, 0.017, -0.35, 0.28),
            $this->chainRow('QQQ', '2026-08-14', 'call', 450.0, 30, 7, 0.030, 0.55, 0.18),
            $this->chainRow('QQQ', '2026-08-14', 'put', 450.0, 20, 9, 0.025, -0.45, 0.22),
        ];

        DB::table('option_chain_data')->insert($rows);
    }

    /** @return array<string,mixed> */
    private function chainRow(
        string $symbol,
        string $expirationDate,
        string $type,
        float $strike,
        int $openInterest,
        int $volume,
        float $gamma,
        float $delta,
        float $vega
    ): array {
        return [
            'expiration_id' => $this->expirationIds[$symbol.'|'.$expirationDate],
            'data_date' => self::DATE,
            'option_type' => $type,
            'strike' => $strike,
            'open_interest' => $openInterest,
            'volume' => $volume,
            'gamma' => $gamma,
            'delta' => $delta,
            'vega' => $vega,
            'iv' => 0.20,
            'underlying_price' => $symbol === 'SPY' ? 500 : 450,
            'created_at' => '2026-08-14 21:00:00',
            'updated_at' => '2026-08-14 21:00:00',
        ];
    }

    private function seedOldPublication(): void
    {
        DB::table('daily_chain_snapshot')->whereDate('data_date', self::DATE)->delete();
        DB::table('daily_chain_snapshot')->insert($this->oldRow(self::DATE));
    }

    /** @return array<string,mixed> */
    private function oldRow(string $date): array
    {
        return [
            'symbol' => 'SPY',
            'data_date' => $date,
            'expiration_id' => $this->expirationIds['SPY|2026-08-14'],
            'call_oi' => 1,
            'put_oi' => 2,
            'call_vol' => 3,
            'put_vol' => 4,
            'sum_gamma' => 5,
            'sum_delta' => 6,
            'sum_vega' => 7,
            'created_at' => '2026-08-14 20:00:00',
            'updated_at' => '2026-08-14 20:00:00',
        ];
    }

    /** @return list<array<string,int|float|string>> */
    private function expectedRows(): array
    {
        return [
            [
                'symbol' => 'QQQ',
                'data_date' => self::DATE,
                'expiration_id' => $this->expirationIds['QQQ|2026-08-14'],
                'call_oi' => 30,
                'put_oi' => 20,
                'call_vol' => 7,
                'put_vol' => 9,
                'sum_gamma' => 140.0,
                'sum_delta' => 750.0,
                'sum_vega' => 980.0,
            ],
            [
                'symbol' => 'SPY',
                'data_date' => self::DATE,
                'expiration_id' => $this->expirationIds['SPY|2026-08-14'],
                'call_oi' => 100,
                'put_oi' => 80,
                'call_vol' => 10,
                'put_vol' => 12,
                'sum_gamma' => 260.0,
                'sum_delta' => 1800.0,
                'sum_vega' => 4000.0,
            ],
            [
                'symbol' => 'SPY',
                'data_date' => self::DATE,
                'expiration_id' => $this->expirationIds['SPY|2026-08-21'],
                'call_oi' => 60,
                'put_oi' => 40,
                'call_vol' => 8,
                'put_vol' => 6,
                'sum_gamma' => 158.0,
                'sum_delta' => 1300.0,
                'sum_vega' => 2920.0,
            ],
        ];
    }

    /** @return list<array<string,int|float|string>> */
    private function visibleRows(string $date = self::DATE, ?string $connection = null): array
    {
        return DB::connection($connection)->table('daily_chain_snapshot')
            ->whereDate('data_date', $date)
            ->orderBy('symbol')
            ->orderBy('expiration_id')
            ->get([
                'symbol',
                'data_date',
                'expiration_id',
                'call_oi',
                'put_oi',
                'call_vol',
                'put_vol',
                'sum_gamma',
                'sum_delta',
                'sum_vega',
            ])
            ->map(static fn (object $row): array => [
                'symbol' => (string) $row->symbol,
                'data_date' => (string) $row->data_date,
                'expiration_id' => (int) $row->expiration_id,
                'call_oi' => (int) $row->call_oi,
                'put_oi' => (int) $row->put_oi,
                'call_vol' => (int) $row->call_vol,
                'put_vol' => (int) $row->put_vol,
                'sum_gamma' => (float) $row->sum_gamma,
                'sum_delta' => (float) $row->sum_delta,
                'sum_vega' => (float) $row->sum_vega,
            ])
            ->all();
    }
}
