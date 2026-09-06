<?php

namespace App\Support;

use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\DatabaseManager;
use Throwable;
use UnexpectedValueException;

class QueueReadiness
{
    public function __construct(
        private readonly Repository $config,
        private readonly Factory $redis,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Read-only application/Redis evidence. Worker coverage, restart recovery,
     * restore tests, and complete producer intent coverage are external gates.
     *
     * @return array<string, mixed>
     */
    public function inspect(?int $observedAt = null): array
    {
        $observedAt ??= time();
        $errors = [];
        $warnings = [];
        $report = [
            'checks_passed' => false,
            'activation_verified' => false,
            'observed_at_unix' => $observedAt,
            'snapshot_is_atomic' => false,
            'metric_limitations' => [
                'ready_head_is_not_global_oldest_after_retry_or_delay_migration',
                'enqueue_age_includes_intentional_delays_and_retries',
                'legacy_payload_enqueue_age_is_unknown',
            ],
            'external_verification_required' => [
                'supervisor_lane_coverage_and_timeouts',
                'scheduler_ownership_and_execution',
                'all_producers_have_durable_intent_or_documented_reconstruction',
                'disposable_restart_data_loss_and_rollback_proof',
                'market_hours_queue_wait_and_host_memory',
            ],
            'queues' => [],
            'redis' => [],
            'database' => [],
            'work_run_recovery' => [],
        ];
        $default = (string) $this->config->get('queue.default');
        $long = (string) $this->config->get('queue.long_connection', $default);
        $isolated = (bool) $this->config->get('queue_lanes.isolated', false);
        if ($this->config->get("queue.connections.{$default}.driver") !== 'redis') {
            $errors[] = 'default_queue_is_not_redis';
        }
        if ($isolated && (! $this->config->get('services.massive.concurrency.enabled')
            || (int) $this->config->get('services.massive.concurrency.limit') < 2)) {
            $errors[] = 'isolated_lanes_require_provider_semaphore';
        }

        $queues = array_merge(
            ['default', 'intraday', 'intraday-heavy', 'quotes'],
            array_values((array) $this->config->get('queue_lanes.legacy', [])),
            $isolated ? array_values((array) $this->config->get('queue_lanes.queues', [])) : [],
        );
        $exports = (string) $this->config->get('queue.long_queue', 'exports');
        $queues = array_values(array_unique(array_merge($queues, [$exports])));
        $monitorTargets = $this->monitorTargets($default);
        if (! $this->config->get('queue.monitor.enabled', true)) {
            $errors[] = 'queue_monitor_disabled';
        }
        $connections = [];
        foreach ($queues as $queue) {
            $connection = $queue === $exports ? $long : $default;
            if (! $this->safeName($queue) || ! $this->safeName($connection)) {
                $errors[] = 'invalid_queue_or_connection_name';

                continue;
            }
            $target = $connection.':'.$queue;
            $settings = (array) $this->config->get("queue.connections.{$connection}", []);
            $redisName = (string) ($settings['connection'] ?? 'default');
            $ceiling = $this->jobCeiling($queue);
            $lease = (int) ($settings['retry_after'] ?? 0);
            $row = [
                'target' => $target,
                'redis_connection' => $this->safeName($redisName) ? $redisName : 'invalid',
                'retry_after_seconds' => $lease,
                'job_timeout_ceiling_seconds' => $ceiling,
                'ready' => null,
                'reserved' => null,
                'delayed' => null,
                'head_ready_enqueue_age_seconds' => null,
                'head_ready_enqueue_age_status' => 'not_read',
                'oldest_reserved_expiry_unix' => null,
                'oldest_reserved_expired_seconds' => null,
                'next_delayed_due_unix' => null,
                'oldest_delayed_due_lag_seconds' => null,
            ];
            if (! in_array($target, $monitorTargets, true)) {
                $errors[] = 'queue_not_monitored:'.$target;
            }
            if ($ceiling === null) {
                $errors[] = 'job_timeout_contract_missing:'.$target;
            } elseif ($lease <= $ceiling) {
                $errors[] = 'queue_lease_not_above_job_timeout:'.$target;
            }
            if (($settings['driver'] ?? null) !== 'redis' || ! $this->safeName($redisName)) {
                $errors[] = 'queue_connection_is_not_valid_redis:'.$target;
            } else {
                try {
                    $redis = $connections[$redisName] ??= $this->redis->connection($redisName);
                    $row['ready'] = (int) $redis->llen('queues:'.$queue);
                    $row['reserved'] = (int) $redis->zcard('queues:'.$queue.':reserved');
                    $row['delayed'] = (int) $redis->zcard('queues:'.$queue.':delayed');
                    $age = $row['ready'] > 0
                        ? QueueTelemetry::headReadyAge($redis->lindex('queues:'.$queue, 0), $observedAt)
                        : ['seconds' => null, 'status' => 'queue_empty_or_changed'];
                    $row['head_ready_enqueue_age_seconds'] = $age['seconds'];
                    $row['head_ready_enqueue_age_status'] = $age['status'];
                    $reservedExpiry = $row['reserved'] > 0 ? $this->firstScore($redis, 'queues:'.$queue.':reserved') : null;
                    $delayedDue = $row['delayed'] > 0 ? $this->firstScore($redis, 'queues:'.$queue.':delayed') : null;
                    $row['oldest_reserved_expiry_unix'] = $reservedExpiry;
                    $row['oldest_reserved_expired_seconds'] = $reservedExpiry === null ? null : max(0, $observedAt - $reservedExpiry);
                    $row['next_delayed_due_unix'] = $delayedDue;
                    $row['oldest_delayed_due_lag_seconds'] = $delayedDue === null ? null : max(0, $observedAt - $delayedDue);
                } catch (Throwable) {
                    // Exception text can contain credentials or URLs.
                    $errors[] = 'queue_read_unavailable:'.$target;
                }
            }
            $report['queues'][] = $row;
        }

        foreach ([
            'intraday_refresh' => FetchPolygonIntradayOptionsJob::class,
            'calculator_refresh' => FetchCalculatorChainJob::class,
        ] as $kind => $jobClass) {
            $hardTimeout = (int) $this->config->get('queue_contracts.'.$jobClass.'.max_timeout', 0);
            $configuredTtl = (int) $this->config->get('work_runs.running_ttl_seconds.'.$kind, 3600);
            $runningTtl = max(300, $configuredTtl);
            $queueLease = (int) $this->config->get("queue.connections.{$default}.retry_after", 0);
            $report['work_run_recovery'][$kind] = [
                'configured_running_ttl_seconds' => $configuredTtl,
                'running_lease_ttl_seconds' => $runningTtl,
                'hard_job_timeout_seconds' => $hardTimeout,
                'queue_retry_after_seconds' => $queueLease,
                'recovery_minimum_age_seconds' => max($runningTtl, $queueLease + 60, ($hardTimeout ?: 960) + 60),
            ];
            if ($hardTimeout <= 0) {
                $errors[] = 'work_run_hard_timeout_contract_missing:'.$kind;
            } elseif ($runningTtl <= $hardTimeout) {
                $errors[] = 'work_run_running_ttl_not_above_hard_timeout:'.$kind;
            }
            if ($runningTtl <= $queueLease) {
                $errors[] = 'work_run_running_ttl_not_above_queue_lease:'.$kind;
            }
        }

        foreach ($connections as $name => $connection) {
            try {
                $info = $this->flattenInfo((array) $connection->info());
                $fsync = $connection->config('GET', 'appendfsync');
                $policy = (string) ($info['maxmemory_policy'] ?? 'unknown');
                $aof = (int) ($info['aof_enabled'] ?? 0);
                $fsync = is_array($fsync) ? ($fsync['appendfsync'] ?? $fsync[1] ?? null) : null;
                $used = isset($info['used_memory']) ? (int) $info['used_memory'] : null;
                $max = isset($info['maxmemory']) ? (int) $info['maxmemory'] : null;
                $report['redis'][$name] = [
                    'version' => preg_match('/^[0-9.]+$/', (string) ($info['redis_version'] ?? ''))
                        ? $info['redis_version'] : 'unknown',
                    'configured_database' => (int) $this->config->get("database.redis.{$name}.database", 0),
                    'used_memory_bytes' => $used,
                    'peak_memory_bytes' => isset($info['used_memory_peak']) ? (int) $info['used_memory_peak'] : null,
                    'maxmemory_bytes' => $max,
                    'maxmemory_policy' => in_array($policy, ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'], true) ? $policy : 'unknown',
                    'evicted_keys' => isset($info['evicted_keys']) ? (int) $info['evicted_keys'] : null,
                    'aof_enabled' => $aof === 1,
                    'appendfsync' => in_array($fsync, ['always', 'everysec', 'no'], true) ? $fsync : 'unknown',
                    'aof_last_write_status' => $this->status($info['aof_last_write_status'] ?? null),
                    'aof_last_bgrewrite_status' => $this->status($info['aof_last_bgrewrite_status'] ?? null),
                    'rdb_last_bgsave_status' => $this->status($info['rdb_last_bgsave_status'] ?? null),
                    'rdb_last_save_time' => isset($info['rdb_last_save_time']) ? (int) $info['rdb_last_save_time'] : null,
                ];
                if ($aof !== 1) {
                    $errors[] = 'redis_aof_disabled:'.$name;
                }
                if (! in_array($fsync, ['always', 'everysec'], true)) {
                    $errors[] = 'redis_durable_fsync_unverified:'.$name;
                }
                if ($policy !== 'noeviction') {
                    $errors[] = 'redis_noeviction_required:'.$name;
                }
                if (! isset($info['evicted_keys']) || (int) $info['evicted_keys'] !== 0) {
                    $errors[] = 'redis_zero_evictions_unverified:'.$name;
                }
                foreach (['aof_last_write_status', 'aof_last_bgrewrite_status', 'rdb_last_bgsave_status'] as $key) {
                    if (isset($info[$key]) && $info[$key] !== 'ok') {
                        $errors[] = 'redis_persistence_error:'.$name.':'.$key;
                    }
                }
                if ($aof === 1 && ! isset($info['aof_last_write_status'])) {
                    $errors[] = 'redis_aof_write_status_unverified:'.$name;
                }
                if ((int) ($info['loading'] ?? 0) !== 0) {
                    $errors[] = 'redis_loading:'.$name;
                }
                if ($used === null || $max === null) {
                    $errors[] = 'redis_memory_unverified:'.$name;
                } elseif ($max === 0) {
                    $warnings[] = 'redis_memory_uncapped_check_host_headroom:'.$name;
                } elseif ($used >= $max * 0.9) {
                    $errors[] = 'redis_memory_at_or_above_90_percent:'.$name;
                }
                $report['redis'][$name]['cache_isolation'] = $this->checkCacheIsolation($name, $info, $errors, $warnings);
            } catch (Throwable) {
                $errors[] = 'redis_health_metadata_unavailable:'.$name;
            }
        }

        try {
            $backlog = 0;
            $checkedStores = [];
            $legacyNames = ['database', 'database-long'];
            foreach ((array) $this->config->get('queue.connections', []) as $name => $settings) {
                if (($settings['driver'] ?? null) !== 'database') {
                    continue;
                }
                $legacyNames[] = $name;
                $legacyConnection = $settings['connection'] ?? null;
                $table = (string) ($settings['table'] ?? 'jobs');
                $identity = (string) $legacyConnection.':'.$table;
                if (isset($checkedStores[$identity])) {
                    continue;
                }
                $checkedStores[$identity] = true;
                $db = $this->database->connection($legacyConnection);
                $backlog += $db->getSchemaBuilder()->hasTable($table) ? $db->table($table)->count() : 0;
            }
            $report['database']['legacy_queue_jobs'] = $backlog;
            $report['database']['legacy_queue_stores_checked'] = count($checkedStores);
            if ($checkedStores === []) {
                $warnings[] = 'former_database_queue_location_requires_external_verification';
            }
            if ($backlog > 0) {
                $errors[] = 'legacy_database_queue_requires_lossless_drain';
            }
            $failedConnection = $this->database->connection($this->config->get('queue.failed.database'));
            $failedTable = (string) $this->config->get('queue.failed.table', 'failed_jobs');
            $failed = $failedConnection->getSchemaBuilder()->hasTable($failedTable)
                ? $failedConnection->table($failedTable)->whereIn('connection', array_values(array_unique($legacyNames)))->count() : 0;
            $report['database']['legacy_database_failed_jobs'] = $failed;
            if ($failed > 0) {
                $errors[] = 'legacy_database_failed_jobs_require_review';
            }
            $schema = $this->database->connection()->getSchemaBuilder();
            $intentSchema = $schema->hasTable('work_runs') && $schema->hasTable('work_run_slots');
            $report['database']['work_run_schema_present'] = $intentSchema;
            if (! $intentSchema) {
                $errors[] = 'durable_work_run_schema_missing';
            }
        } catch (Throwable) {
            $errors[] = 'database_readiness_unavailable';
        }

        $report['errors'] = array_values(array_unique($errors));
        $report['warnings'] = array_values(array_unique($warnings));
        $report['checks_passed'] = $report['errors'] === [];

        return $report;
    }

    private function jobCeiling(string $queue): ?int
    {
        $ceiling = null;
        foreach ((array) $this->config->get('queue_contracts', []) as $contract) {
            foreach (['queues' => 'queue_timeouts', 'isolated_queues' => 'isolated_queue_timeouts'] as $key => $timeouts) {
                if (in_array($queue, $contract[$key] ?? [], true)) {
                    $ceiling = max($ceiling ?? 0, (int) ($contract[$timeouts][$queue] ?? $contract['max_timeout'] ?? 0));
                }
            }
        }

        return $ceiling;
    }

    private function monitorTargets(string $default): array
    {
        $targets = (array) $this->config->get('queue.monitor.targets', []);
        $connection = (string) $this->config->get('queue.monitor.connection', $default);

        // Match routes/console.php exactly. Bare exports is monitored on the
        // monitor connection; it does not automatically become redis-long.
        return array_map(
            static fn (string $target): string => str_contains($target, ':') ? $target : $connection.':'.$target,
            $targets !== [] ? $targets : (array) $this->config->get('queue.monitor.queues', []),
        );
    }

    private function checkCacheIsolation(string $name, array $queueInfo, array &$errors, array &$warnings): array
    {
        $store = $this->config->get('cache.default');
        if ($this->config->get("cache.stores.{$store}.driver") !== 'redis') {
            return ['required' => false, 'verified' => true, 'same_process' => null];
        }
        $cacheName = (string) $this->config->get("cache.stores.{$store}.connection", 'cache');
        $queue = (array) $this->config->get("database.redis.{$name}", []);
        $cache = (array) $this->config->get("database.redis.{$cacheName}", []);
        try {
            $cacheInfo = $cacheName === $name
                ? $queueInfo
                : $this->flattenInfo((array) $this->redis->connection($cacheName)->info('server'));
            $queueId = $queueInfo['run_id'] ?? null;
            $cacheId = $cacheInfo['run_id'] ?? null;
            if (is_string($queueId) && $queueId !== '' && is_string($cacheId) && $cacheId !== '') {
                // Compare process IDs internally. Never print their values,
                // connection URLs, hosts, passwords, or the raw INFO response.
                $sameProcess = hash_equals($queueId, $cacheId);
                if ($sameProcess) {
                    $errors[] = 'cache_and_queue_share_redis_process:'.$name;
                    if ((int) ($queue['database'] ?? 0) === (int) ($cache['database'] ?? 0)) {
                        $errors[] = 'cache_and_queue_share_redis_database:'.$name;
                    }
                }
                if (! empty($queue['url']) || ! empty($cache['url'])) {
                    $warnings[] = 'redis_url_database_override_requires_external_verification:'.$name;
                }

                return ['required' => true, 'verified' => true, 'same_process' => $sameProcess];
            }
        } catch (Throwable) {
            // A restricted INFO permission or unavailable cache is not proof
            // that the queue has its own Redis process.
        }
        $errors[] = 'redis_cache_process_isolation_unverified:'.$name;

        return ['required' => true, 'verified' => false, 'same_process' => null];
    }

    private function firstScore(object $redis, string $key): ?int
    {
        $scores = $redis->zrangebyscore($key, '-inf', '+inf', ['withscores' => true, 'limit' => [0, 1]]);
        if (! is_array($scores) || count($scores) > 1) {
            throw new UnexpectedValueException('Unexpected queue timing response.');
        }
        if ($scores === []) {
            return null;
        }
        // Discard the sorted-set member (serialized payload); report score only.
        $score = array_values($scores)[0];
        if (! is_numeric($score) || ! is_finite((float) $score) || (float) $score < 0 || (float) $score > PHP_INT_MAX) {
            throw new UnexpectedValueException('Unexpected queue timing score.');
        }

        return (int) $score;
    }

    private function flattenInfo(array $info): array
    {
        $flat = [];
        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flattenInfo($value));
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    private function status(mixed $status): ?string
    {
        return $status === null ? null : (in_array($status, ['ok', 'err'], true) ? $status : 'unknown');
    }

    private function safeName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_{}:.\-]{1,128}$/D', $name);
    }
}
