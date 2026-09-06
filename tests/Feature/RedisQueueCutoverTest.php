<?php

namespace Tests\Feature;

use App\Models\WorkRun;
use App\Support\WorkRunCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\MySqlTestCase;

/**
 * Opt-in real transport proof. Requires an explicitly marked disposable Redis
 * behind a loopback tunnel; it cannot use the application's Redis settings.
 * Run separately from other MySQL suites because RefreshDatabase owns its DB.
 */
class RedisQueueCutoverTest extends MySqlTestCase
{
    use RefreshDatabase;

    private const DISPOSABLE_MARKER = 'gex:disposable-proof:marker';

    private const DISPOSABLE_VALUE = 'gex017-redis-proof-v1';

    private WorkRunCoordinator $runs;

    private ?Connection $redis = null;

    /** @var array<string, QueueContract> */
    private array $transports = [];

    private string $queueName;

    private string $effectsTable;

    private bool $tableCreated = false;

    protected function setUp(): void
    {
        if ($this->proofEnvironment('GEX_REDIS_PROOF_ENABLED') !== '1') {
            $this->markTestSkipped('Set GEX_REDIS_PROOF_ENABLED=1 with a marked disposable loopback Redis.');
        }

        parent::setUp();

        $port = (string) ($this->proofEnvironment('GEX_REDIS_PROOF_PORT') ?? '16381');
        if (! preg_match('/^1638[1-9]$/', $port)) {
            throw new RuntimeException('The Redis proof only allows loopback ports 16381 through 16389.');
        }
        if (! extension_loaded('redis')) {
            throw new RuntimeException('The opt-in Redis transport proof requires phpredis.');
        }

        // Verify the disposable daemon before creating any keys. No production
        // URL, host, username, password, database, or prefix is inherited.
        $probe = new \Redis;
        try {
            $probe->connect('127.0.0.1', (int) $port, 2.0);
            $probe->setOption(\Redis::OPT_READ_TIMEOUT, 2.0);
            $this->assertSame(self::DISPOSABLE_VALUE, $probe->get(self::DISPOSABLE_MARKER));
        } finally {
            if ($probe->isConnected()) {
                $probe->close();
            }
        }

        $nonce = bin2hex(random_bytes(12));
        $this->queueName = 'cutover-'.$nonce;
        $this->effectsTable = 'queue_proof_effects_'.$nonce;
        $manager = new RedisManager($this->app, 'phpredis', [
            'options' => ['prefix' => 'gex017-proof:'.$nonce.':'],
            'proof' => [
                'host' => '127.0.0.1',
                'port' => (int) $port,
                'database' => 0,
                'password' => null,
                'timeout' => 2.0,
                'read_timeout' => 2.0,
                'persistent' => false,
            ],
        ]);
        $this->redis = $manager->connection('proof');

        $redisQueue = new RedisQueue($manager, $this->queueName, 'proof', 10, null);
        $databaseQueue = new DatabaseQueue(DB::connection(), 'jobs', $this->queueName, 10);
        foreach (['redis' => $redisQueue, 'database' => $databaseQueue] as $name => $transport) {
            $transport->setContainer($this->app);
            $transport->setConnectionName($name);
            $this->transports[$name] = $transport;
        }

        Schema::create($this->effectsTable, function (Blueprint $table): void {
            $table->temporary();
            $table->uuid('run_id')->primary();
            $table->uuid('delivery_token');
            $table->unsignedInteger('attempt');
            $table->bigInteger('result_value');
        });
        $this->tableCreated = true;

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 10);
        config()->set('queue.connections.database.retry_after', 10);
        config()->set('work_runs.running_ttl_seconds.intraday_refresh', 1800);
        config()->set('work_runs.pending_ttl_seconds', 3600);
        config()->set('work_runs.running_recovery_enabled', true);
        config()->set('work_runs.running_recovery_max_dispatches', 3);
        $this->runs = app(WorkRunCoordinator::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-04 16:00:00', 'UTC'));
        $this->assertBothStoresDrained();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->redis !== null) {
                $this->deleteOwnedRedisPayloads();
                $this->redis->disconnect();
            }
            if ($this->tableCreated) {
                // DROP TEMPORARY does not implicitly commit RefreshDatabase's
                // transaction. The name is generated locally from hex only.
                DB::statement('DROP TEMPORARY TABLE `'.$this->effectsTable.'`');
            }
            if (isset($this->app)) {
                $this->travelBack();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_ready_reserved_delayed_and_retried_delivery_produces_one_final_result(): void
    {
        $run = $this->claim('release');
        $token = $this->deliver($run, releaseFirstAttempt: true);
        $this->assertRedisCounts(1, 0, 0);

        $first = $this->pop('redis');
        $this->assertSame(1, $first->attempts());
        $this->assertRedisCounts(0, 1, 0);
        $first->fire();
        $this->assertTrue($first->isReleased());
        $this->assertSame(WorkRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertRedisCounts(0, 0, 1);
        $this->assertNull($this->transports['redis']->pop($this->queueName));
        $this->assertSame(0, DB::table($this->effectsTable)->count());

        $this->travel(11)->seconds();
        $retry = $this->pop('redis');
        $this->assertSame(2, $retry->attempts());
        $retry->fire();
        $this->assertResult($run, $token, 2);

        // A duplicated payload after successful acknowledgement must not run
        // the final logical write a second time.
        $this->pushFixture('redis', $run->id, $token);
        $this->pop('redis')->fire();
        $this->assertResult($run, $token, 2);
        $this->assertBothStoresDrained();
    }

    public function test_an_unacknowledged_reserved_payload_is_retried_by_the_real_transport(): void
    {
        $run = $this->claim('reservation-timeout');
        $token = $this->deliver($run);
        $lostWorker = $this->pop('redis');
        $this->assertSame(1, $lostWorker->attempts());
        $this->assertRedisCounts(0, 1, 0);

        // Simulate a worker exiting before its handler starts. Laravel's real
        // reservation migration, not a queue fake, recovers the same payload.
        $this->travel(11)->seconds();
        $replacement = $this->pop('redis');
        $this->assertSame($lostWorker->getJobId(), $replacement->getJobId());
        $this->assertSame(2, $replacement->attempts());
        $replacement->fire();

        $this->assertResult($run, $token, 2);
        $this->assertSame(1, $run->fresh()->dispatch_attempts);
        $this->assertBothStoresDrained();
    }

    public function test_lost_ready_payload_is_rebuilt_from_mysql_with_a_new_fenced_delivery(): void
    {
        $run = $this->claim('lost-ready');
        $oldToken = $this->deliver($run);
        $this->assertRedisCounts(1, 0, 0);
        $this->deleteOwnedRedisPayloads();
        $this->assertBothStoresDrained();
        $this->assertSame(1, WorkRun::query()->count());
        $this->assertSame(WorkRun::STATUS_PENDING, $run->fresh()->status);
        $this->assertNull($this->runs->reserveDispatch($run->id));

        $this->travel(3601)->seconds();
        $this->assertTrue($this->runs->dispatchable()->contains('id', $run->id));
        $newToken = $this->deliver($run);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertOldTokenFenced($run, $oldToken);
        $this->pop('redis')->fire();
        $this->pushFixture('redis', $run->id, $oldToken);
        $this->pop('redis')->fire();

        $this->assertResult($run, $newToken, 1);
        $this->assertSame($run->generation, $run->fresh()->generation);
        $this->assertSame(2, $run->fresh()->dispatch_attempts);
        $this->assertBothStoresDrained();
    }

    public function test_lost_running_payload_recovers_mysql_intent_and_rejects_old_callbacks(): void
    {
        $run = $this->claim('lost-running');
        $oldToken = $this->deliver($run);
        $lostWorker = $this->pop('redis');
        $this->assertTrue($this->runs->markStarted($run->id, $oldToken, $lostWorker->attempts()));
        $this->assertRedisCounts(0, 1, 0);
        $this->deleteOwnedRedisPayloads();
        $this->assertSame(WorkRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertNull($this->runs->recoverExpiredRunning($run->id));

        $this->travel(1801)->seconds();
        $this->assertSame('recovered', $this->runs->recoverExpiredRunning($run->id));
        $this->assertSame(WorkRun::STATUS_PENDING, $run->fresh()->status);
        $this->assertNull($run->fresh()->delivery_token);
        $newToken = $this->deliver($run);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertOldTokenFenced($run, $oldToken);
        $this->pop('redis')->fire();

        // The original in-memory worker callback can arrive after recovery.
        // Its start is rejected and its acknowledgement cannot delete the new
        // payload because Redis reservation bodies contain their own identity.
        $lostWorker->fire();
        $this->assertResult($run, $newToken, 1);
        $this->assertSame($run->generation, $run->fresh()->generation);
        $this->assertSame(2, $run->fresh()->dispatch_attempts);
        $this->assertBothStoresDrained();
    }

    public function test_database_to_redis_cutover_and_database_rollback_drain_both_stores(): void
    {
        $expectedRuns = [];
        foreach (['database', 'redis', 'database'] as $phase => $connection) {
            // This is the cutover/rollback gate: never switch producers while
            // the old store still owns ready, reserved, or delayed deliveries.
            $this->assertBothStoresDrained();
            config()->set('queue.default', $connection);

            foreach ([0, 1, 2] as $index) {
                $run = $this->claim('phase-'.$phase.'-'.$index);
                $token = $this->deliver($run, delay: $index === 1 ? 10 : 0, releaseFirstAttempt: $index === 2);
                $expectedRuns[] = [$run, $token, $index === 2 ? 2 : 1];
                $this->assertSame($connection, $run->queue_connection);
            }
            $this->assertSame(3, $this->transports[$connection]->size($this->queueName));
            $this->drain($connection);
            $this->assertBothStoresDrained();
        }

        $this->assertSame(9, WorkRun::query()->count());
        $this->assertSame(9, DB::table($this->effectsTable)->count());
        foreach ($expectedRuns as [$run, $token, $attempt]) {
            $this->assertResult($run, $token, $attempt);
        }
        $this->assertSame(0, WorkRun::query()->whereIn('status', WorkRun::ACTIVE_STATUSES)->count());
    }

    private function claim(string $scope): WorkRun
    {
        return $this->runs->claim(
            'intraday_refresh',
            'AAPL',
            ['trade_date' => '2026-09-04', 'proof_scope' => $scope],
            $this->queueName,
            applyAdmissionLimits: false
        )['run'];
    }

    private function deliver(WorkRun $run, int $delay = 0, bool $releaseFirstAttempt = false): string
    {
        $reservation = $this->runs->reserveDispatch($run->id);
        $this->assertNotNull($reservation);
        $token = $reservation['delivery_token'];
        $this->pushFixture($run->queue_connection, $run->id, $token, $delay, $releaseFirstAttempt);
        $this->assertTrue($this->runs->markDispatched($run->id, $token));

        return $token;
    }

    private function pushFixture(string $connection, string $runId, string $token, int $delay = 0, bool $releaseFirstAttempt = false): void
    {
        $job = new RedisCutoverProofJob($runId, $token, $this->effectsTable, $releaseFirstAttempt);
        if ($delay > 0) {
            $this->transports[$connection]->later($delay, $job, '', $this->queueName);
        } else {
            $this->transports[$connection]->push($job, '', $this->queueName);
        }
    }

    private function pop(string $connection): Job
    {
        $job = $this->transports[$connection]->pop($this->queueName);
        $this->assertNotNull($job, 'Expected an actual '.$connection.' delivery.');

        return $job;
    }

    private function drain(string $connection): void
    {
        for ($iteration = 0; $iteration < 20; $iteration++) {
            if ($this->transports[$connection]->size($this->queueName) === 0) {
                return;
            }
            $job = $this->transports[$connection]->pop($this->queueName);
            if ($job === null) {
                $this->travel(11)->seconds();
            } else {
                $job->fire();
            }
        }

        $this->fail('The bounded proof queue did not drain.');
    }

    private function assertOldTokenFenced(WorkRun $run, string $oldToken): void
    {
        $this->assertFalse($this->runs->markStarted($run->id, $oldToken, 2));
        $this->assertFalse($this->runs->markCompleted($run->id, $oldToken, 1));
        $this->assertFalse($this->runs->markFailed($run->id, $oldToken, 1, 'timeout', 'late-proof-callback'));
        $this->assertFalse($this->runs->markDispatched($run->id, $oldToken));
    }

    private function assertResult(WorkRun $run, string $token, int $attempt): void
    {
        $fresh = $run->fresh();
        $this->assertSame(WorkRun::STATUS_COMPLETED, $fresh->status);
        $this->assertSame($token, $fresh->delivery_token);
        $rows = DB::table($this->effectsTable)->where('run_id', $run->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($token, $rows[0]->delivery_token);
        $this->assertSame($attempt, (int) $rows[0]->attempt);
        $this->assertSame(42, (int) $rows[0]->result_value);
    }

    private function assertBothStoresDrained(): void
    {
        $this->assertRedisCounts(0, 0, 0);
        $this->assertSame(0, DB::table('jobs')->where('queue', $this->queueName)->count());
    }

    private function assertRedisCounts(int $ready, int $reserved, int $delayed): void
    {
        $key = 'queues:'.$this->queueName;
        $this->assertSame($ready, (int) $this->redis->llen($key));
        $this->assertSame($reserved, (int) $this->redis->zcard($key.':reserved'));
        $this->assertSame($delayed, (int) $this->redis->zcard($key.':delayed'));
    }

    private function deleteOwnedRedisPayloads(): void
    {
        // The connection adds the random per-test prefix. Enumerate only the
        // four keys this test owns; do not scan/delete any other namespace.
        $key = 'queues:'.$this->queueName;
        foreach ([$key, $key.':reserved', $key.':delayed', $key.':notify'] as $ownedKey) {
            $this->redis->del($ownedKey);
        }
    }

    private function proofEnvironment(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? null : (string) $value;
    }
}

/** A local fixture only: no provider HTTP, market data, mail, or external work. */
class RedisCutoverProofJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $runId,
        public string $deliveryToken,
        public string $effectsTable,
        public bool $releaseFirstAttempt = false
    ) {}

    public function handle(WorkRunCoordinator $runs): void
    {
        if (! preg_match('/^queue_proof_effects_[a-f0-9]{24}$/', $this->effectsTable)) {
            throw new RuntimeException('Invalid proof table.');
        }
        $attempt = $this->attempts();
        if (! $runs->markStarted($this->runId, $this->deliveryToken, $attempt)) {
            return;
        }
        if ($this->releaseFirstAttempt && $attempt === 1) {
            $this->release(10);

            return;
        }

        DB::transaction(function () use ($runs, $attempt): void {
            if ($runs->markCompleted($this->runId, $this->deliveryToken, $attempt)) {
                DB::table($this->effectsTable)->insert([
                    'run_id' => $this->runId,
                    'delivery_token' => $this->deliveryToken,
                    'attempt' => $attempt,
                    'result_value' => 42,
                ]);
            }
        });
    }
}
