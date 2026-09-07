<?php

namespace App\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Bounded, read-only inventory of the exact legacy publication namespace. */
class EodLegacyPublicationInventory
{
    public function capture(): array
    {
        $store = Cache::getStore();
        if (! $store instanceof RedisStore || ! $store->connection() instanceof PhpRedisConnection) {
            throw new RuntimeException('Legacy inventory requires the existing phpredis cache store.');
        }
        $client = $store->connection()->client();
        $prefix = (string) $client->getOption(\Redis::OPT_PREFIX).$store->getPrefix();
        $logicalPrefix = 'eod:cache-version:v2:';
        $physicalPrefix = $prefix.$logicalPrefix;
        // rawCommand avoids client prefix rewriting of SCAN and its results.
        $pattern = strtr($physicalPrefix, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?', '[' => '\\[', ']' => '\\]']).'*';
        $cursor = '0';
        $keys = [];
        $started = microtime(true);
        for ($pass = 0; $pass < 10000; $pass++) {
            $page = $client->rawCommand('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200');
            if (! is_array($page) || count($page) !== 2 || ! is_array($page[1])) {
                throw new RuntimeException('Legacy publication scan returned an invalid page.');
            }
            $cursor = (string) $page[0];
            foreach ($page[1] as $key) {
                if (! is_string($key) || ! str_starts_with($key, $physicalPrefix)) {
                    throw new RuntimeException('Legacy publication scan returned a key outside its namespace.');
                }
                $keys[substr($key, strlen($prefix))] = true;
            }
            if (count($keys) > 100000 || microtime(true) - $started > 30) {
                throw new RuntimeException('Legacy publication inventory exceeded its safety budget; no import was made.');
            }
            if ($cursor === '0') {
                $heads = [];
                $versions = app(EodCacheVersion::class);
                foreach (array_chunk(array_keys($keys), 250) as $chunk) {
                    if (microtime(true) - $started > 30) {
                        throw new RuntimeException('Legacy publication decoding exceeded its safety budget.');
                    }
                    foreach (Cache::many($chunk) as $key => $value) {
                        $suffix = substr($key, strlen($logicalPrefix));
                        [$domain, $symbol] = array_pad(explode(':', $suffix, 2), 2, null);
                        if (! in_array($domain, EodCacheVersion::ALL_DOMAINS, true)
                            || ! is_string($symbol) || $symbol === '' || strlen($symbol) > 32
                            || $key !== $versions->publicationKey($domain, $symbol)
                            || ! is_array($value) || ! is_string($value['version'] ?? null)
                            || $value['version'] === '' || $value['version'] === 'initial'
                            || ! is_int($value['issued_at_microseconds'] ?? null)
                            || $value['issued_at_microseconds'] < 1) {
                            throw new RuntimeException('Legacy publication metadata is missing or malformed; drain writers and investigate before importing.');
                        }
                        $heads[$key] = ['domain' => $domain, 'symbol' => $symbol,
                            'version' => $value['version'], 'issued_at_microseconds' => $value['issued_at_microseconds']];
                    }
                    if (microtime(true) - $started > 30) {
                        throw new RuntimeException('Legacy publication decoding exceeded its safety budget.');
                    }
                }
                ksort($heads, SORT_STRING);

                return ['heads' => array_values($heads), 'count' => count($heads),
                    'sha256' => hash('sha256', json_encode($heads, JSON_THROW_ON_ERROR))];
            }
        }

        throw new RuntimeException('Legacy publication scan did not finish within its safety budget.');
    }
}
