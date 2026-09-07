<?php

namespace Tests\Unit;

use App\Support\EodLegacyPublicationInventory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Native Redis, Laravel adapters and decoded cache values are all mocked. */
class EodLegacyPublicationInventoryTest extends TestCase
{
    private const PREFIX = 'eod:cache-version:v2:';

    protected function setUp(): void
    {
        parent::setUp();
        if (! class_exists(\Redis::class)) {
            $this->markTestSkipped('The inventory requires the phpredis extension.');
        }
        foreach (['put', 'forever', 'forget', 'flush'] as $method) {
            Cache::shouldReceive($method)->never();
        }
    }

    public function test_scan_escapes_literal_client_and_cache_prefixes_and_decodes_only_logical_keys(): void
    {
        $clientPrefix = 'client[*]?\\:';
        $cachePrefix = 'cache[?]*\\:';
        $key = self::PREFIX.'gex:SPY';
        $client = $this->client($clientPrefix, $cachePrefix);
        $client->shouldReceive('rawCommand')->once()->with(
            'SCAN', '0', 'MATCH',
            'client\[\*\]\?\\\\:cache\[\?\]\*\\\\:eod:cache-version:v2:*',
            'COUNT', '200',
        )->andReturn(['0', [$clientPrefix.$cachePrefix.$key]]);
        Cache::shouldReceive('many')->once()->with([$key])->andReturn([$key => $this->metadata()]);

        $result = (new EodLegacyPublicationInventory)->capture();
        $this->assertSame(1, $result['count']);
        $this->assertSame([[
            'domain' => 'gex', 'symbol' => 'SPY', 'version' => 'fixture-version',
            'issued_at_microseconds' => 1000001,
        ]], $result['heads']);
        $this->assertStringNotContainsString($clientPrefix, json_encode($result));
        $this->assertStringNotContainsString($cachePrefix, json_encode($result));
    }

    public function test_duplicate_scan_keys_and_page_order_do_not_change_inventory_fingerprint(): void
    {
        $spy = self::PREFIX.'gex:SPY';
        $qqq = self::PREFIX.'activity:QQQ';
        $values = [
            $spy => $this->metadata('fixture-spy', 11),
            $qqq => $this->metadata('fixture-qqq', 12),
        ];
        $first = $this->capture([
            ['7', [$spy, $qqq]],
            ['0', [$spy, $qqq, $spy]],
        ], $values);
        $second = $this->capture([
            ['8', []],
            ['0', [$qqq, $spy]],
        ], $values);
        $expected = [
            $qqq => ['domain' => 'activity', 'symbol' => 'QQQ', 'version' => 'fixture-qqq', 'issued_at_microseconds' => 12],
            $spy => ['domain' => 'gex', 'symbol' => 'SPY', 'version' => 'fixture-spy', 'issued_at_microseconds' => 11],
        ];
        $this->assertSame($first, $second);
        $this->assertSame(2, $first['count']);
        $this->assertSame(array_values($expected), $first['heads']);
        $this->assertSame(hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR)), $first['sha256']);
    }

    public function test_metadata_changes_change_fingerprint_but_nonidentity_metadata_does_not(): void
    {
        $key = self::PREFIX.'gex:SPY';
        $first = $this->capture([['0', [$key]]], [$key => $this->metadata()]);
        $extra = $this->metadata() + ['published_at' => 'fixture-time', 'ignored' => 'fixture-diagnostic'];
        $same = $this->capture([['0', [$key]]], [$key => $extra]);
        $new = $this->capture([['0', [$key]]], [$key => $this->metadata('fixture-new', 1000002)]);
        $this->assertSame($first, $same);
        $this->assertNotSame($first['sha256'], $new['sha256']);
        $this->assertStringNotContainsString('fixture-diagnostic', json_encode($same));
    }

    public function test_empty_inventory_has_a_stable_fingerprint_and_does_not_read_values(): void
    {
        $client = $this->client();
        $client->shouldReceive('rawCommand')->once()->andReturn(['0', []]);
        Cache::shouldNotReceive('many');
        $this->assertSame([
            'heads' => [], 'count' => 0, 'sha256' => hash('sha256', '[]'),
        ], (new EodLegacyPublicationInventory)->capture());
    }

    public static function invalidMetadata(): array
    {
        return [
            'disappeared after scan' => [null],
            'false cache response' => [false],
            'version-only legacy string' => ['fixture-version'],
            'empty metadata' => [[]],
            'missing version' => [['issued_at_microseconds' => 1]],
            'nonstring version' => [['version' => 7, 'issued_at_microseconds' => 1]],
            'empty version' => [['version' => '', 'issued_at_microseconds' => 1]],
            'unpublished initial version' => [['version' => 'initial', 'issued_at_microseconds' => 1]],
            'missing issuance' => [['version' => 'fixture-version']],
            'zero issuance' => [['version' => 'fixture-version', 'issued_at_microseconds' => 0]],
            'negative issuance' => [['version' => 'fixture-version', 'issued_at_microseconds' => -1]],
            'numeric string issuance' => [['version' => 'fixture-version', 'issued_at_microseconds' => '123']],
            'float issuance' => [['version' => 'fixture-version', 'issued_at_microseconds' => 123.0]],
            'boolean issuance' => [['version' => 'fixture-version', 'issued_at_microseconds' => true]],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_missing_or_malformed_metadata_is_never_silently_importable(mixed $metadata): void
    {
        $key = self::PREFIX.'gex:SPY';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy publication metadata is missing or malformed');
        $this->capture([['0', [$key]]], [$key => $metadata]);
    }

    public static function invalidLogicalKeys(): array
    {
        return [
            ['eod:cache-version:v2:unknown:SPY'],
            ['eod:cache-version:v2:gex:'],
            ['eod:cache-version:v2:gex:spy'],
            ['eod:cache-version:v2:gex:'.str_repeat('A', 33)],
            ['eod:cache-version:v2:gex'],
        ];
    }

    #[DataProvider('invalidLogicalKeys')]
    public function test_scanned_metadata_requires_a_canonical_supported_domain_and_symbol(string $key): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy publication metadata is missing or malformed');
        $this->capture([['0', [$key]]], [$key => $this->metadata()]);
    }

    public static function invalidPages(): array
    {
        return [
            [false], [null], [[]], [['0']], [['0', 'not-an-array']],
            [['0', [], 'unexpected-extra']],
        ];
    }

    #[DataProvider('invalidPages')]
    public function test_malformed_scan_pages_are_rejected(mixed $page): void
    {
        $client = $this->client();
        $client->shouldReceive('rawCommand')->once()->andReturn($page);
        Cache::shouldNotReceive('many');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy publication scan returned an invalid page.');
        (new EodLegacyPublicationInventory)->capture();
    }

    public static function outsideNamespaceKeys(): array
    {
        return [
            ['other:cache:eod:cache-version:v2:gex:SPY'],
            ['client:cache:eod:cache-version-lock:v2:gex:SPY'],
            ['client:cache:eod:cache-version:v1:gex:SPY'],
            ['client:cache:queues:default'],
            [123],
        ];
    }

    #[DataProvider('outsideNamespaceKeys')]
    public function test_wrong_physical_namespace_never_reaches_cache_decoding(mixed $key): void
    {
        $client = $this->client('client:', 'cache:');
        $client->shouldReceive('rawCommand')->once()->andReturn(['0', [$key]]);
        Cache::shouldNotReceive('many');
        try {
            (new EodLegacyPublicationInventory)->capture();
            $this->fail('An out-of-namespace key must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Legacy publication scan returned a key outside its namespace.', $exception->getMessage());
            $this->assertStringNotContainsString((string) $key, $exception->getMessage());
        }
    }

    public function test_default_nonredis_store_is_rejected_before_any_connection_or_scan(): void
    {
        Cache::shouldReceive('getStore')->once()->andReturn(new ArrayStore);
        Cache::shouldNotReceive('many');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy inventory requires the existing phpredis cache store.');
        (new EodLegacyPublicationInventory)->capture();
    }

    public function test_nonphpredis_connection_is_rejected_without_native_client_access(): void
    {
        $store = Mockery::mock(RedisStore::class);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldNotReceive('client');
        $store->shouldReceive('connection')->once()->andReturn($connection);
        Cache::shouldReceive('getStore')->once()->andReturn($store);
        Cache::shouldNotReceive('many');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy inventory requires the existing phpredis cache store.');
        (new EodLegacyPublicationInventory)->capture();
    }

    public function test_scan_stops_at_its_pass_cap_without_decoding_an_incomplete_inventory(): void
    {
        $client = $this->client();
        $passes = 0;
        $client->shouldReceive('rawCommand')->times(10000)
            ->with('SCAN', Mockery::type('string'), 'MATCH', self::PREFIX.'*', 'COUNT', '200')
            ->andReturnUsing(function ($command, $cursor, $match, $pattern, $count, $size) use (&$passes): array {
                $passes++;

                return ['1', []];
            });
        Cache::shouldNotReceive('many');
        try {
            (new EodLegacyPublicationInventory)->capture();
            $this->fail('An unfinished cursor must not produce an inventory.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Legacy publication scan did not finish within its safety budget.', $exception->getMessage());
            $this->assertSame(10000, $passes);
        }
    }

    public function test_scan_stops_above_its_distinct_key_cap_before_decoding(): void
    {
        $client = $this->client();
        $keys = array_map(static fn (int $index): string => self::PREFIX.'gex:S'.$index, range(0, 100000));
        $client->shouldReceive('rawCommand')->once()->andReturn(['0', $keys]);
        Cache::shouldNotReceive('many');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Legacy publication inventory exceeded its safety budget; no import was made.');
        (new EodLegacyPublicationInventory)->capture();
    }

    public function test_cache_metadata_reads_are_chunked_to_250_unique_keys(): void
    {
        $keys = array_map(static fn (int $index): string => self::PREFIX.'gex:S'.$index, range(1, 251));
        $client = $this->client();
        $client->shouldReceive('rawCommand')->once()->andReturn(['0', $keys]);
        $chunks = [];
        Cache::shouldReceive('many')->twice()->andReturnUsing(function (array $chunk) use (&$chunks): array {
            $chunks[] = count($chunk);

            return array_fill_keys($chunk, $this->metadata());
        });
        $result = (new EodLegacyPublicationInventory)->capture();
        $this->assertSame([250, 1], $chunks);
        $this->assertSame(251, $result['count']);
    }

    private function client(string $clientPrefix = '', string $cachePrefix = ''): \Redis
    {
        $client = Mockery::mock(\Redis::class);
        foreach (['connect', 'pconnect', 'set', 'del', 'flushDB', 'flushAll'] as $method) {
            $client->shouldNotReceive($method);
        }
        $client->shouldReceive('getOption')->once()->with(\Redis::OPT_PREFIX)->andReturn($clientPrefix);
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('client')->once()->andReturn($client);
        $store = Mockery::mock(RedisStore::class);
        $store->shouldReceive('connection')->twice()->andReturn($connection);
        $store->shouldReceive('getPrefix')->once()->andReturn($cachePrefix);
        Cache::shouldReceive('getStore')->once()->andReturn($store);

        return $client;
    }

    private function capture(array $pages, array $values): array
    {
        $client = $this->client();
        $cursor = '0';
        foreach ($pages as $page) {
            $client->shouldReceive('rawCommand')->once()->with(
                'SCAN', $cursor, 'MATCH', self::PREFIX.'*', 'COUNT', '200',
            )->andReturn($page);
            $cursor = (string) $page[0];
        }
        Cache::shouldReceive('many')->once()->andReturnUsing(function (array $keys) use ($values): array {
            $this->assertSame(array_values(array_unique($keys)), $keys);

            return array_combine($keys, array_map(static fn (string $key) => $values[$key] ?? null, $keys));
        });

        return (new EodLegacyPublicationInventory)->capture();
    }

    private function metadata(string $version = 'fixture-version', int $issuance = 1000001): array
    {
        return ['version' => $version, 'issued_at_microseconds' => $issuance];
    }
}
