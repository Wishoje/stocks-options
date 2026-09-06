<?php

namespace App\Support;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded, disposable HTTP checkpoints. Durable requirements live elsewhere.
 * A miss can repeat provider reads; it must never change publication rules.
 */
class ProviderRequestReplay
{
    /** @var list<object> */
    private array $contexts = [];

    private bool $cacheFailureLogged = false;

    private int $replaySuppressionDepth = 0;

    public function __construct(
        private readonly Repository $config,
        private readonly Factory $redis
    ) {}

    public static function fingerprint(string $url, array $params = []): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('Provider request URL is invalid.');
        }
        // Preserve duplicate query ordering: the last value can be significant.
        $query = array_filter(explode('&', (string) ($parts['query'] ?? '')), static function (string $pair): bool {
            $name = rawurldecode(explode('=', $pair, 2)[0]);

            return $pair !== '' && ! self::credentialName($name);
        });
        $identity = [
            'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => implode('&', $query),
            'params' => self::canonicalParams($params),
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function withExecution(string $scope, callable $callback): mixed
    {
        if ($scope === '' || strlen($scope) > 4096) {
            throw new InvalidArgumentException('Provider execution scope is invalid.');
        }
        $this->contexts[] = (object) [
            'hash' => hash('sha256', $scope),
            'physical' => 0,
            'failures' => 0,
        ];

        try {
            return $callback();
        } finally {
            array_pop($this->contexts);
        }
    }

    /** Volatile snapshots must be fetched again when a partial response retries. */
    public function withoutReplay(callable $callback): mixed
    {
        $this->replaySuppressionDepth++;
        try {
            return $callback();
        } finally {
            $this->replaySuppressionDepth--;
        }
    }

    public function executedRequestCount(): int
    {
        return $this->context()?->physical ?? 0;
    }

    public function recordPhysicalRequest(): void
    {
        // A nested execution belongs to every active parent as well.
        foreach ($this->contexts as $context) {
            $context->physical++;
        }
    }

    public function recordFailure(): int
    {
        $context = $this->context();
        if (! $context) {
            return 1;
        }
        $context->failures++;
        if (! $this->enabled()) {
            return $context->failures;
        }
        try {
            return (int) $this->connection()->eval(
                <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    redis.call('HSET', KEYS[1], '__bytes', 0, '__pages', 0)
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return redis.call('HINCRBY', KEYS[1], '__failures', 1)
LUA,
                1,
                $this->executionKey($context->hash),
                $this->ttl()
            );
        } catch (Throwable) {
            $this->cacheFailure();

            return $context->failures;
        }
    }

    public function lookup(string $requestKey): ?Response
    {
        $context = $this->context();
        if ($this->replaySuppressionDepth > 0 || ! $context || ! $this->enabled() || ! $this->validKey($requestKey)) {
            return null;
        }
        try {
            $connection = $this->connection();
            $key = $this->executionKey($context->hash);
            // Refuse oversized stored values before transferring them to PHP.
            $bytes = (int) $connection->hstrlen($key, $requestKey);
            if ($bytes === 0) {
                $this->metric('miss');

                return null;
            }
            if ($bytes > $this->maxEncodedPageBytes()) {
                $this->metric('miss_invalid');

                return null;
            }
            $raw = $connection->hget($key, $requestKey);
            $value = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
            if (! is_array($value) || ($value['version'] ?? null) !== 1
                || ! is_int($value['bytes'] ?? null) || $value['bytes'] < 0
                || $value['bytes'] > $this->maxPageBytes()
                || ! is_string($value['body'] ?? null) || ! is_string($value['hash'] ?? null)) {
                $this->metric('miss_invalid');

                return null;
            }
            $compressed = base64_decode($value['body'], true);
            $body = is_string($compressed) ? @gzdecode($compressed, $this->maxPageBytes() + 1) : false;
            if (! is_string($body) || strlen($body) !== $value['bytes']
                || ! hash_equals($value['hash'], hash('sha256', $body))
                || ! $this->safeBody($body)) {
                $this->metric('miss_invalid');

                return null;
            }
            $this->metric('hit');

            return new Response(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
        } catch (Throwable) {
            $this->cacheFailure();

            return null;
        }
    }

    public function remember(string $requestKey, Response $response): void
    {
        $context = $this->context();
        if ($this->replaySuppressionDepth > 0 || ! $context || ! $this->enabled() || ! $this->validKey($requestKey)) {
            return;
        }
        if ($response->status() !== 200) {
            $this->metric('not_success');

            return;
        }
        try {
            $stream = $response->toPsrResponse()->getBody();
            $bytes = $stream->getSize();
            if ($bytes === null || $bytes > $this->maxPageBytes()) {
                $this->metric('not_stored_oversize_or_unknown');

                return;
            }
            $body = $response->body();
            if (strlen($body) > $this->maxPageBytes() || ! $this->safeBody($body)) {
                $this->metric('not_stored_body');

                return;
            }
            $compressed = gzencode($body, 1);
            if (! is_string($compressed)) {
                return;
            }
            $encoded = json_encode([
                'version' => 1,
                'bytes' => strlen($body),
                'hash' => hash('sha256', $body),
                'body' => base64_encode($compressed),
            ], JSON_THROW_ON_ERROR);
            $stored = (int) $this->connection()->eval(
                <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then
    redis.call('HSET', KEYS[1], '__bytes', 0, '__pages', 0)
    redis.call('EXPIRE', KEYS[1], ARGV[3])
end
if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 1 then return 2 end
local bytes = tonumber(redis.call('HGET', KEYS[1], '__bytes') or '0')
local pages = tonumber(redis.call('HGET', KEYS[1], '__pages') or '0')
local added = string.len(ARGV[1]) + string.len(ARGV[2])
if bytes + added > tonumber(ARGV[4]) then return -1 end
if pages + 1 > tonumber(ARGV[5]) then return -2 end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
redis.call('HINCRBY', KEYS[1], '__bytes', added)
redis.call('HINCRBY', KEYS[1], '__pages', 1)
return 1
LUA,
                1,
                $this->executionKey($context->hash),
                $requestKey,
                $encoded,
                $this->ttl(),
                max(1, min(16 * 1024 * 1024, (int) $this->config->get('provider_backpressure.replay.max_execution_bytes', 16 * 1024 * 1024))),
                max(1, min(1024, (int) $this->config->get('provider_backpressure.replay.max_pages', 1024)))
            );
            $this->metric(match ($stored) {
                1 => 'stored', 2 => 'existing_preserved',
                -1 => 'not_stored_byte_cap', -2 => 'not_stored_page_cap',
                default => 'not_stored',
            });
        } catch (Throwable) {
            $this->cacheFailure();
        }
    }

    public function forgetExecution(string $scope): void
    {
        if ($scope === '' || strlen($scope) > 4096 || ! $this->enabled()) {
            return;
        }
        try {
            $this->connection()->del($this->executionKey(hash('sha256', $scope)));
        } catch (Throwable) {
            $this->cacheFailure();
        }
    }

    private function safeBody(string $body): bool
    {
        $secret = (string) $this->config->get('services.massive.key', '');
        if ($secret !== '' && (str_contains($body, $secret) || str_contains($body, rawurlencode($secret)))) {
            return false;
        }
        // Provider cursors can echo query credentials; do not retain them.
        if (preg_match('/[?&](?:api[_-]?key|access[_-]?token|token|password)=/i', $body)
            || preg_match('/"(?:api[_-]?key|authorization|access[_-]?token|password)"\s*:/i', $body)) {
            return false;
        }
        $value = json_decode($body, true, 64);

        return is_array($value) && json_last_error() === JSON_ERROR_NONE
            && (! array_key_exists('status', $value)
                || (is_string($value['status']) && in_array(strtoupper($value['status']), ['OK', 'DELAYED'], true)));
    }

    private static function canonicalParams(array $params): array
    {
        $list = array_is_list($params);
        if (! $list) {
            $params = array_filter($params, static fn ($key): bool => ! self::credentialName((string) $key), ARRAY_FILTER_USE_KEY);
            ksort($params, SORT_STRING);
        }
        foreach ($params as &$value) {
            if (is_array($value)) {
                $value = self::canonicalParams($value);
            }
        }

        return $params;
    }

    private static function credentialName(string $name): bool
    {
        return in_array(strtolower(str_replace(['_', '-'], '', $name)), [
            'apikey', 'token', 'accesstoken', 'authorization', 'password', 'secret',
        ], true);
    }

    private function context(): ?object
    {
        return $this->contexts === [] ? null : $this->contexts[array_key_last($this->contexts)];
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('provider_backpressure.enabled', false)
            && (bool) $this->config->get('provider_backpressure.replay.enabled', true);
    }

    private function connection(): mixed
    {
        return $this->redis->connection((string) $this->config->get('services.massive.concurrency.connection', 'default'));
    }

    private function executionKey(string $scopeHash): string
    {
        return $this->prefix().':execution:'.$scopeHash;
    }

    private function prefix(): string
    {
        return (string) $this->config->get('provider_backpressure.replay.prefix', 'provider-replay:massive');
    }

    private function ttl(): int
    {
        return max(1, min(1200, (int) $this->config->get('provider_backpressure.replay.ttl_seconds', 1200)));
    }

    private function maxPageBytes(): int
    {
        return max(1, min(2 * 1024 * 1024, (int) $this->config->get('provider_backpressure.replay.max_page_bytes', 2 * 1024 * 1024)));
    }

    private function maxEncodedPageBytes(): int
    {
        return (int) ceil(($this->maxPageBytes() + 1024) * 4 / 3) + 1024;
    }

    private function validKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }

    private function metric(string $event): void
    {
        try {
            $key = $this->prefix().':metrics:'.gmdate('Y-m-d');
            $connection = $this->connection();
            $connection->hincrby($key, $event, 1);
            $connection->expire($key, 172800);
        } catch (Throwable) {
            $this->cacheFailure();
        }
    }

    private function cacheFailure(): void
    {
        if ($this->cacheFailureLogged) {
            return;
        }
        $this->cacheFailureLogged = true;
        try {
            Log::channel('queue_monitor')->warning('provider_replay.cache_unavailable', [
                'provider' => 'massive',
                'effect' => 'successful_pages_may_be_requested_again',
            ]);
        } catch (Throwable) {
            // Logging failure cannot replace a successfully fetched response.
        }
    }
}
