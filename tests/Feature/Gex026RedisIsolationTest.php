<?php

namespace Tests\Feature;

use App\Support\EodCacheVersion;
use App\Support\EodLegacyPublicationInventory;
use App\Support\EodPublicationRepository;
use App\Support\WorkRunCoordinator;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\MySqlTestCase;

/** Explicit opt-in: creates and removes only its own disposable Redis containers. */
class Gex026RedisIsolationTest extends MySqlTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        if (getenv('GEX026_REDIS_DRILL') !== '1') {
            $this->markTestSkipped('Set GEX026_REDIS_DRILL=1 to run the isolated Docker cache-loss drill.');
        }
        parent::setUp();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_cache_restart_eviction_inventory_and_rollback_leave_coordination_and_queue_intact(): void
    {
        $owned = [];
        $label = 'gex026-'.bin2hex(random_bytes(8));
        try {
            $ports = [];
            foreach (['payload', 'coordination', 'queue'] as $role) {
                $socket = stream_socket_server('tcp://127.0.0.1:0');
                $this->assertIsResource($socket);
                $address = stream_socket_get_name($socket, false);
                $port = (int) substr($address, strrpos($address, ':') + 1);
                fclose($socket);
                $id = trim($this->docker(['run', '-d', '--label', 'gex026-test='.$label,
                    '--name', $label.'-'.$role, '--memory', '128m', '-p', '127.0.0.1:'.$port.':6379',
                    'redis:8.4-alpine', 'redis-server', '--appendonly', 'yes', '--appendfsync', 'everysec',
                    '--maxmemory', '8mb', '--maxmemory-policy', 'noeviction']));
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $id);
                $owned[$role] = $id;
                $binding = trim($this->docker(['port', $id, '6379/tcp']));
                $this->assertMatchesRegularExpression('/^127\.0\.0\.1:\d+$/D', $binding);
                $ports[$role] = (int) substr($binding, strrpos($binding, ':') + 1);
                $this->awaitRedis($ports[$role]);
            }
            foreach ($ports as $role => $port) {
                config()->set('database.redis.gex026_'.$role, [
                    'host' => '127.0.0.1', 'port' => $port, 'password' => null,
                    'database' => 0, 'timeout' => 2, 'read_timeout' => 2,
                ]);
            }
            // Exercise literal SCAN pattern escaping as well as both prefixes.
            config()->set('database.redis.options.prefix', $label.':[*?]:');
            config()->set('cache.stores.gex026', ['driver' => 'redis',
                'connection' => 'gex026_payload', 'lock_connection' => 'gex026_coordination',
                'prefix' => 'cache:[?]:']);
            config()->set('cache.default', 'gex026');
            config()->set('eod_snapshot_health.enabled', false);
            config()->set('eod_publications.write_enabled', false);
            config()->set('eod_publications.read_enabled', false);
            foreach (array_keys($ports) as $role) {
                Redis::purge('gex026_'.$role);
            }
            Cache::purge('gex026');
            $versions = app(EodCacheVersion::class);
            $versions->publish(['SPY'], ['activity'], 'legacy', 100);
            $inventory = app(EodLegacyPublicationInventory::class)->capture();
            $this->assertSame(1, $inventory['count']);
            $this->assertSame('legacy', $inventory['heads'][0]['version']);
            app(EodPublicationRepository::class)->prepare($inventory['heads'], 150);
            config()->set('eod_publications.write_enabled', true);
            config()->set('eod_publications.read_enabled', true);
            $versions->publish(['SPY'], ['activity'], 'newer', 300);
            $lock = Cache::lock('owned-coordination', 180);
            $this->assertTrue($lock->get());
            $queue = Redis::connection('gex026_queue');
            $queue->rpush('owned-queue', 'synthetic-job');
            $coordination = Redis::connection('gex026_coordination');
            $coordination->set('owned-derived-state', 'preserve');
            $claim = app(WorkRunCoordinator::class)->claim('calculator_refresh', 'SPY',
                ['drill' => $label], 'calculator', applyAdmissionLimits: false);
            $this->assertTrue($claim['created']);
            $queueIdentity = $queue->info('server')['run_id'];
            $coordinationIdentity = $coordination->info('server')['run_id'];
            $payloadIdentity = Redis::connection('gex026_payload')->info('server')['run_id'];

            // This restarts only the container created above, never a service
            // named by external application configuration.
            $this->docker(['restart', $owned['payload']]);
            $this->awaitRedis($ports['payload']);
            Redis::purge('gex026_payload');
            Cache::purge('gex026');
            $payload = Redis::connection('gex026_payload');
            $this->assertNotSame($payloadIdentity, $payload->info('server')['run_id']);
            $this->assertSame($queueIdentity, $queue->info('server')['run_id']);
            $this->assertSame($coordinationIdentity, $coordination->info('server')['run_id']);
            $this->assertFalse(Cache::lock('owned-coordination', 180)->get());
            $versions->publish(['SPY'], ['activity'], 'delayed', 200);
            $this->assertSame('newer', $versions->current('activity', 'SPY'));

            // Eviction is tested on the isolated disposable payload process.
            // The production provisioning default remains noeviction.
            $payload->client()->rawCommand('CONFIG', 'SET', 'maxmemory-policy', 'allkeys-lru');
            for ($i = 0; $i < 256; $i++) {
                Cache::put('owned-pressure:'.$i, str_repeat('x', 65536), 60);
            }
            $this->assertGreaterThan(0, $payload->info('stats')['evicted_keys']);
            Cache::forget($versions->publicationKey('activity', 'SPY'));
            $versions->publish(['SPY'], ['activity'], 'delayed-after-loss', 200);
            $this->assertSame('newer', $versions->current('activity', 'SPY'));
            $this->assertFalse(Cache::lock('owned-coordination', 180)->get());
            $this->assertSame('preserve', $coordination->get('owned-derived-state'));
            $this->assertSame(['synthetic-job'], $queue->lrange('owned-queue', 0, -1));
            $again = app(WorkRunCoordinator::class)->claim('calculator_refresh', 'SPY',
                ['drill' => $label], 'calculator', applyAdmissionLimits: false);
            $this->assertFalse($again['created']);
            $this->assertSame($claim['run']->id, $again['run']->id);

            app(EodPublicationRepository::class)->mirror(['SPY']);
            config()->set('eod_publications.read_enabled', false);
            $this->assertSame('newer', $versions->current('activity', 'SPY'));
            $this->assertSame(['synthetic-job'], $queue->lrange('owned-queue', 0, -1));
            $lock->release();
        } finally {
            foreach ($owned as $id) {
                $actual = trim($this->docker(['inspect', '--format', '{{index .Config.Labels "gex026-test"}}', $id]));
                $this->assertSame($label, $actual, 'Only this test may remove its own Redis containers.');
                $this->docker(['rm', '-f', $id]);
            }
        }
    }

    private function awaitRedis(int $port): void
    {
        $end = microtime(true) + 10;
        do {
            try {
                $client = new \Redis;
                $client->connect('127.0.0.1', $port, 1);
                if ($client->ping()) {
                    $client->close();

                    return;
                }
            } catch (\RedisException) {
                // Docker's published port may not be ready when start returns.
            }
            usleep(100000);
        } while (microtime(true) < $end);
        $this->fail('The owned disposable Redis container did not become ready.');
    }

    private function docker(array $arguments): string
    {
        $process = new Process(array_merge(['docker'], $arguments), base_path(), timeout: 60);
        $process->mustRun();

        return $process->getOutput();
    }
}
