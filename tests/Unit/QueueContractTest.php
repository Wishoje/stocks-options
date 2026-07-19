<?php

namespace Tests\Unit;

use App\Jobs\BuildAiExportJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\PricesBackfillJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\QueueJob;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

class QueueContractTest extends TestCase
{
    public function test_every_application_job_has_a_complete_machine_readable_contract(): void
    {
        $contracts = config('queue_contracts');
        $this->assertIsArray($contracts);

        $discovered = collect(glob(app_path('Jobs/*.php')) ?: [])
            ->map(fn (string $path): string => 'App\\Jobs\\'.pathinfo($path, PATHINFO_FILENAME))
            ->filter(fn (string $class): bool => class_exists($class))
            ->filter(fn (string $class): bool => is_subclass_of($class, ShouldQueue::class))
            ->reject(fn (string $class): bool => (new ReflectionClass($class))->isAbstract())
            ->sort()
            ->values()
            ->all();

        $declared = array_keys($contracts);
        sort($declared);

        $this->assertSame($discovered, $declared);

        foreach ($contracts as $class => $contract) {
            $reflection = new ReflectionClass($class);
            $defaults = $reflection->getDefaultProperties();

            $this->assertTrue($reflection->isSubclassOf(QueueJob::class), "{$class} must extend QueueJob.");
            $this->assertSame($contract['tries'], $defaults['tries'], "{$class} tries differ from its contract.");
            $this->assertSame($contract['backoff'], $defaults['backoff'], "{$class} backoff differs from its contract.");
            $this->assertFalse($defaults['failOnTimeout'], "{$class} must remain retryable after a timeout.");
            $this->assertLessThanOrEqual($contract['max_timeout'], $defaults['timeout']);
            $this->assertNotEmpty($contract['identity']);
            $this->assertNotEmpty($contract['write_strategy']);
        }
    }

    public function test_job_worker_lease_and_supervisor_windows_are_ordered_safely(): void
    {
        $legacy = json_decode(
            file_get_contents(base_path('ops/forge-workers.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $isolated = json_decode(
            file_get_contents(base_path('ops/forge-workers-isolated.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertWorkerProfile($legacy, 'workers', 'queues', 'queue_timeouts');
        $this->assertWorkerProfile(
            $isolated,
            'steady_workers',
            'isolated_queues',
            'isolated_queue_timeouts'
        );
        $this->assertWorkerProfile(
            $isolated,
            'rollout_workers',
            'queues',
            'queue_timeouts'
        );
        $this->assertWorkerProfile(
            $isolated,
            'rollout_workers',
            'isolated_queues',
            'isolated_queue_timeouts'
        );

        $this->assertSame(
            (int) $legacy['baseline_process_count'],
            collect($legacy['workers'])->sum('processes')
        );
        $this->assertSame(
            (int) $isolated['rollout_process_count'],
            collect($isolated['rollout_workers'])->sum('processes')
        );
        $this->assertSame(
            (int) $isolated['steady_process_count'],
            collect($isolated['steady_workers'])->sum('processes')
        );
        $this->assertSame(
            (int) $legacy['baseline_process_count'],
            (int) $isolated['current_process_count']
        );
        $this->assertSame(
            (int) $isolated['current_process_count'],
            (int) $isolated['rollout_process_count']
        );
        $this->assertSame(
            (int) $isolated['current_process_count'],
            (int) $isolated['steady_process_count']
        );

        $this->assertManifestJobCeilings($legacy, 'workers', [
            ['queues', 'queue_timeouts'],
        ]);
        $this->assertManifestJobCeilings($isolated, 'rollout_workers', [
            ['queues', 'queue_timeouts'],
            ['isolated_queues', 'isolated_queue_timeouts'],
        ]);
        $this->assertManifestJobCeilings($isolated, 'steady_workers', [
            ['isolated_queues', 'isolated_queue_timeouts'],
        ]);
    }

    private function assertWorkerProfile(
        array $manifest,
        string $workersKey,
        string $queuesKey,
        string $timeoutsKey
    ): void {
        $workers = collect($manifest[$workersKey])->keyBy('queue');

        $this->assertCount(count($manifest[$workersKey]), $workers, "{$workersKey} repeats a queue.");

        foreach (config('queue_contracts') as $class => $contract) {
            $retryAfter = (int) config("queue.connections.{$contract['connection']}.retry_after");
            $this->assertGreaterThan(0, $retryAfter, "{$class} has no retry_after.");

            $queues = $contract[$queuesKey] ?? $contract['queues'];
            $timeouts = $contract[$timeoutsKey] ?? $contract['queue_timeouts'] ?? [];

            foreach ($queues as $queue) {
                $worker = $workers->get($queue);
                $this->assertNotNull(
                    $worker,
                    "{$class} queue {$queue} has no {$workersKey} worker definition."
                );
                $this->assertSame($contract['connection'], $worker['connection']);
                $jobTimeout = (int) ($timeouts[$queue] ?? $contract['max_timeout']);

                $this->assertLessThan(
                    (int) $worker['worker_timeout'],
                    $jobTimeout,
                    "{$class} timeout must be below the {$queue} worker fallback."
                );
                $this->assertLessThan(
                    $retryAfter,
                    (int) $worker['worker_timeout'],
                    "{$queue} worker timeout must be below retry_after."
                );
                $this->assertLessThan(
                    (int) $manifest['required_supervisor_stopwaitsecs'],
                    (int) $worker['worker_timeout'],
                    "{$queue} worker timeout must be below Supervisor stopwaitsecs."
                );
            }
        }
    }

    /**
     * @param  array<int, array{0:string, 1:string}>  $profiles
     */
    private function assertManifestJobCeilings(
        array $manifest,
        string $workersKey,
        array $profiles
    ): void {
        $expectedCeilings = [];

        foreach (config('queue_contracts') as $contract) {
            foreach ($profiles as [$queuesKey, $timeoutsKey]) {
                $queues = $contract[$queuesKey] ?? [];
                $timeouts = $contract[$timeoutsKey] ?? [];

                foreach ($queues as $queue) {
                    $jobTimeout = (int) ($timeouts[$queue] ?? $contract['max_timeout']);
                    $expectedCeilings[$queue] = max(
                        $expectedCeilings[$queue] ?? 0,
                        $jobTimeout
                    );
                }
            }
        }

        $workers = collect($manifest[$workersKey])->keyBy('queue');
        $expectedQueues = array_keys($expectedCeilings);
        $actualQueues = $workers->keys()->all();
        sort($expectedQueues);
        sort($actualQueues);
        $this->assertSame($expectedQueues, $actualQueues, "{$workersKey} contains a missing or stale lane.");

        foreach ($expectedCeilings as $queue => $expectedCeiling) {
            $this->assertSame(
                $expectedCeiling,
                (int) $workers->get($queue)['job_timeout_ceiling'],
                "{$queue} manifest ceiling differs from its job contracts."
            );
        }
    }

    public function test_job_identities_are_deterministic_and_input_sensitive(): void
    {
        $first = new FetchOptionChainDataJob(['QQQ', 'SPY'], 90, '2026-03-18');
        $same = new FetchOptionChainDataJob(['SPY', 'QQQ'], 90, '2026-03-18');
        $different = new FetchOptionChainDataJob(['SPY', 'QQQ'], 30, '2026-03-18');

        $this->assertSame($first->idempotencyKey(), $same->idempotencyKey());
        $this->assertNotSame($first->idempotencyKey(), $different->idempotencyKey());

        $beforeBatching = $first->idempotencyKey();
        $first->withBatchId('batch-run-a');
        $this->assertSame($beforeBatching, $first->idempotencyKey());

        $heavy = new FetchPolygonIntradayOptionsJob(['SPY']);
        $normal = new FetchPolygonIntradayOptionsJob(['AAPL']);
        $normalBatch = new FetchPolygonIntradayOptionsJob(['AAPL', 'MSFT']);
        $this->assertSame(540, $heavy->timeout);
        $this->assertSame(105, $normal->timeout);
        $this->assertSame(540, $normalBatch->timeout);
        $this->assertSame('intraday-heavy', $heavy->queue);
        $this->assertSame('intraday', $normal->queue);
        $this->assertSame('intraday-heavy', $normalBatch->queue);

        $bulk = new PricesBackfillJob(array_fill(0, 10, 'AAPL'), 400);
        $this->assertSame(540, $bulk->timeout);
        $this->assertSame(110, $bulk->withJobTimeout(110)->timeout);

        Carbon::setTestNow(Carbon::parse('2026-03-18 17:00:00', 'America/New_York'));
        try {
            $daily = new PricesDailyJob(['AAPL']);
            $intraday = new FetchPolygonIntradayOptionsJob(['AAPL']);

            Carbon::setTestNow(Carbon::parse('2026-03-19 17:00:00', 'America/New_York'));
            $this->assertSame('2026-03-18', $daily->targetDate);
            $this->assertSame('2026-03-18', $intraday->tradeDate);
            $this->assertNotSame($daily->idempotencyKey(), (new PricesDailyJob(['AAPL']))->idempotencyKey());
            $this->assertNotSame(
                $intraday->idempotencyKey(),
                (new FetchPolygonIntradayOptionsJob(['AAPL']))->idempotencyKey()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_exports_use_the_long_connection_and_dedicated_queue(): void
    {
        config()->set('queue.long_connection', 'redis-long');
        config()->set('queue.long_queue', 'exports');

        $job = new BuildAiExportJob(42);

        $this->assertSame('redis-long', $job->connection);
        $this->assertSame('exports', $job->queue);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(2, $job->tries);
    }

    public function test_non_queueing_test_connection_is_not_silently_changed_to_database(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('sync', config('queue.long_connection'));

        $job = new BuildAiExportJob(42);
        $this->assertSame('sync', $job->connection);
    }
}
