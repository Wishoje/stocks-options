<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Throwable;

/** Read-only process evidence; it neither provisions Redis nor verifies a restart drill. */
class RedisCacheTopology
{
    public function __construct(
        private readonly Repository $config,
        private readonly Factory $redis,
    ) {}

    public function inspect(): array
    {
        $errors = [];
        $warnings = [];
        $roles = $this->roles($errors);
        $connections = [];
        $identities = [];
        $samples = [];
        foreach (array_unique(array_filter($roles)) as $name) {
            try {
                $connections[$name] = $this->redis->connection($name);
                $info = $this->flatten($connections[$name]->info());
                $identities[$name] = $this->identity($info);
                $samples[$name] = $info;
                if ($identities[$name] === null) {
                    $errors[] = 'redis_process_identity_unavailable:'.$name;
                }
            } catch (Throwable) {
                // Redis exceptions may contain endpoint URLs and credentials.
                $errors[] = 'redis_read_unavailable:'.$name;
                $identities[$name] = null;
            }
        }

        // A process restart between aliases must not look like isolation.
        foreach ($connections as $name => $connection) {
            try {
                $after = $this->identity($this->flatten($connection->info('server')));
                if ($after === null || $after !== ($identities[$name] ?? null)) {
                    $errors[] = 'redis_process_changed_or_unverified:'.$name;
                    $identities[$name] = null;
                }
            } catch (Throwable) {
                $errors[] = 'redis_process_recheck_unavailable:'.$name;
                $identities[$name] = null;
            }
        }

        $isolation = [];
        $cacheId = $identities[$roles['cache'] ?? ''] ?? null;
        foreach (['locks', 'provider', 'queue', 'long_queue', 'coordination'] as $role) {
            $name = $roles[$role] ?? null;
            $id = $identities[$name ?? ''] ?? null;
            $verified = $cacheId !== null && $id !== null;
            $same = $verified ? hash_equals($cacheId, $id) : null;
            $isolation[$role] = ['verified' => $verified, 'same_process' => $same];
            if (! $verified) {
                $errors[] = 'cache_process_isolation_unverified:'.$role;
            } elseif ($same) {
                $errors[] = 'cache_shares_redis_process:'.$role;
            }
            if (($samples[$name ?? '']['maxmemory_policy'] ?? null) !== 'noeviction') {
                $errors[] = 'redis_noeviction_required:'.$role;
            }
            if ($this->nonnegativeInteger($samples[$name ?? '']['evicted_keys'] ?? null) !== 0) {
                $errors[] = 'redis_zero_evictions_unverified:'.$role;
            }
        }

        $originalId = $identities[$roles['original'] ?? ''] ?? null;
        $coordinationId = $identities[$roles['coordination'] ?? ''] ?? null;
        $coordinationVerified = $originalId !== null && $coordinationId !== null;
        $coordinationSame = $coordinationVerified ? hash_equals($originalId, $coordinationId) : null;
        if (! $coordinationVerified || ! $coordinationSame) {
            $errors[] = 'coordination_original_process_unverified';
        }

        $safeConnections = [];
        foreach (array_unique(array_filter($roles)) as $name) {
            $info = $samples[$name] ?? [];
            $policy = $info['maxmemory_policy'] ?? null;
            $safeConnections[$name] = [
                'process_identity_verified' => ($identities[$name] ?? null) !== null,
                'maxmemory_policy' => in_array($policy, [
                    'noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random',
                    'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl',
                ], true) ? $policy : null,
                'maxmemory_bytes' => $this->nonnegativeInteger($info['maxmemory'] ?? null),
                'used_memory_bytes' => $this->nonnegativeInteger($info['used_memory'] ?? null),
                'evicted_keys' => $this->nonnegativeInteger($info['evicted_keys'] ?? null),
            ];
            if ($safeConnections[$name]['maxmemory_bytes'] === 0) {
                $warnings[] = 'redis_memory_uncapped_check_host_headroom:'.$name;
            }
        }

        return [
            'checks_passed' => $errors === [],
            'activation_verified' => false,
            'snapshot_is_atomic' => false,
            'roles' => $roles,
            'cache_isolation' => $isolation,
            'coordination_enabled' => (bool) $this->config->get('cache.coordination_enabled', false),
            'coordination_original_process' => [
                'verified' => $coordinationVerified, 'same_process' => $coordinationSame,
            ],
            'redis' => $safeConnections,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'external_verification_required' => [
                'both_application_nodes_have_matching_effective_topology',
                'queue_readiness_persistence_and_worker_coverage',
                'durable_publication_reads_and_completed_head_backfill',
                'disposable_cache_restart_and_owned_lock_survival_drill',
                'legacy_cache_claims_drained_and_supervised_workers_restarted',
                'cache_only_derived_values_preserved_or_recomputed',
                'cache_capacity_nonpayload_state_and_independent_rollback_review',
            ],
        ];
    }

    private function roles(array &$errors): array
    {
        $cacheStore = $this->config->get('cache.default');
        $cache = $this->safeName($cacheStore)
            ? (array) $this->config->get('cache.stores.'.$cacheStore, []) : [];
        if (($cache['driver'] ?? null) !== 'redis') {
            $errors[] = 'cache_store_is_not_redis';
            $cache = [];
        }
        $roles = [
            'cache' => $cache['connection'] ?? null,
            'locks' => $cache['lock_connection'] ?? $cache['connection'] ?? null,
            'provider' => $this->config->get('services.massive.concurrency.connection', 'default'),
            'original' => 'default',
        ];
        $coordination = (array) $this->config->get('cache.stores.coordination', []);
        if (! $this->config->get('cache.coordination_enabled', false)) {
            $errors[] = 'coordination_cache_selector_disabled';
            $roles['coordination'] = $roles['cache'];
        } elseif (($coordination['driver'] ?? null) !== 'redis') {
            $errors[] = 'coordination_cache_store_is_not_redis';
            $roles['coordination'] = null;
        } else {
            $roles['coordination'] = $coordination['connection'] ?? null;
        }
        if ($this->config->get('cache.coordination_enabled', false)
            && $this->config->get('cache.limiter') !== CoordinationCache::STORE) {
            $errors[] = 'rate_limiter_coordination_store_required';
        }
        foreach (['queue' => 'queue.default', 'long_queue' => 'queue.long_connection'] as $role => $key) {
            $queue = $this->config->get($key, $this->config->get('queue.default'));
            $settings = $this->safeName($queue)
                ? (array) $this->config->get('queue.connections.'.$queue, []) : [];
            if (($settings['driver'] ?? null) !== 'redis') {
                $errors[] = 'queue_driver_is_not_redis:'.$role;
                $roles[$role] = null;
            } else {
                $roles[$role] = $settings['connection'] ?? 'default';
            }
        }
        foreach ($roles as $role => $name) {
            if (! $this->safeName($name) || ! is_array($this->config->get('database.redis.'.$name))) {
                $errors[] = 'redis_connection_invalid:'.$role;
                $roles[$role] = null;
            }
        }

        return $roles;
    }

    private function safeName(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $value) === 1;
    }

    private function identity(array $info): ?string
    {
        $id = $info['run_id'] ?? null;

        return is_string($id) && preg_match('/\A[a-f0-9]{40}\z/D', $id) === 1 ? $id : null;
    }

    private function nonnegativeInteger(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false
            ? (int) $value : null;
    }

    private function flatten(mixed $info): array
    {
        if (is_string($info)) {
            $parsed = [];
            foreach (preg_split('/\r?\n/', $info) as $line) {
                if ($line !== '' && $line[0] !== '#' && str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $parsed[$key] = $value;
                }
            }

            return $parsed;
        }
        $flat = [];
        foreach ((array) $info as $key => $value) {
            if (is_array($value)) {
                $flat = array_replace($flat, $this->flatten($value));
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }
}
