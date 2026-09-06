<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use App\Jobs\QueueJob;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** No database, real Redis, or HTTP requests are used by these queue-path tests. */
class ProviderSynchronousDeferralTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'));
        config()->set([
            'provider_backpressure.enabled' => true,
            'provider_backpressure.replay.enabled' => false,
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        ProviderSynchronousFixture::$allowSuccess = false;
        ProviderSynchronousFixture::$transportClass = null;
        ProviderSynchronousSuccessor::$handled = 0;
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_sync_dispatch_propagates_deferral_without_claiming_success_or_running_the_successor(): void
    {
        $command = (new ProviderSynchronousFixture)->chain([new ProviderSynchronousSuccessor]);

        try {
            Bus::dispatchSync($command);
            $this->fail('A synchronous release cannot store a retry and must not report success.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame(ProviderDeferred::COOLDOWN, $exception->reason);
            $this->assertSame(300, $exception->retryAfterSeconds(now('UTC')));
        }

        // Queueable is required: otherwise dispatchSync bypasses SyncQueue and
        // this test could pass without exercising any job middleware.
        $this->assertSame(SyncJob::class, ProviderSynchronousFixture::$transportClass);
        $this->assertSame(0, ProviderSynchronousSuccessor::$handled);
    }

    public function test_queued_release_preserves_the_original_chain_until_a_successful_retry(): void
    {
        Queue::fake();
        $command = (new ProviderSynchronousFixture)->chain([new ProviderSynchronousSuccessor]);
        $data = ['commandName' => $command::class, 'command' => serialize($command)];
        $raw = json_encode(['uuid' => 'provider-deferral-test-delivery', 'data' => $data]);
        $first = new ProviderMemoryDelivery($this->app, $raw);

        app(CallQueuedHandler::class)->call($first, $data);

        $this->assertSame(ProviderMemoryDelivery::class, ProviderSynchronousFixture::$transportClass);
        $this->assertTrue($first->isReleased());
        $this->assertFalse($first->isDeleted());
        $this->assertFalse($first->hasFailed());
        $this->assertSame(300, $first->delay);
        $this->assertSame($raw, $first->getRawBody());
        Queue::assertNothingPushed();

        ProviderSynchronousFixture::$allowSuccess = true;
        $this->travel(300)->seconds();
        $retry = new ProviderMemoryDelivery($this->app, $raw);
        app(CallQueuedHandler::class)->call($retry, $data);

        $this->assertFalse($retry->isReleased());
        $this->assertTrue($retry->isDeleted());
        $this->assertFalse($retry->hasFailed());
        Queue::assertPushed(ProviderSynchronousSuccessor::class, 1);
    }
}

class ProviderSynchronousFixture extends QueueJob
{
    use InteractsWithQueue, Queueable;

    public static bool $allowSuccess = false;

    public static ?string $transportClass = null;

    public function handle(): void
    {
        self::$transportClass = $this->job?->getConnectionName() !== null ? $this->job::class : null;
        if (! self::$allowSuccess) {
            throw new ProviderDeferred(ProviderDeferred::COOLDOWN, now('UTC')->toImmutable()->addMinutes(5));
        }
    }
}

class ProviderSynchronousSuccessor extends QueueJob
{
    use InteractsWithQueue, Queueable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}

/** Only the transport is in memory; CallQueuedHandler and middleware are real. */
class ProviderMemoryDelivery extends Job implements JobContract
{
    public ?int $delay = null;

    public function __construct($container, private readonly string $raw)
    {
        $this->container = $container;
        $this->connectionName = 'redis';
        $this->queue = 'default';
    }

    public function getJobId(): string
    {
        return 'provider-deferral-test-delivery';
    }

    public function getRawBody(): string
    {
        return $this->raw;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function release($delay = 0): void
    {
        $this->delay = (int) $delay;
        parent::release($delay);
    }
}
