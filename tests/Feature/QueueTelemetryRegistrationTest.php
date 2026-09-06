<?php

namespace Tests\Feature;

use App\Support\QueueTelemetry;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionMethod;
use stdClass;
use Tests\TestCase;

class QueueTelemetryRegistrationTest extends TestCase
{
    public function test_provider_adds_enqueue_metadata_without_changing_serialized_job(): void
    {
        $job = new stdClass;
        $before = time();
        $queue = Queue::connection('sync');
        $payload = json_decode((new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, 'default'), true);

        $this->assertGreaterThanOrEqual($before, $payload[QueueTelemetry::STAMP]);
        $this->assertLessThanOrEqual(time(), $payload[QueueTelemetry::STAMP]);
        $this->assertSame(serialize($job), $payload['data']['command']);
    }

    public function test_provider_routes_processing_event_to_telemetry(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        $event = new JobProcessing('redis', $job);
        $telemetry = Mockery::mock(QueueTelemetry::class);
        $telemetry->shouldReceive('processing')->with($event)->once();
        $this->app->instance(QueueTelemetry::class, $telemetry);

        Event::dispatch($event);
        $this->addToAssertionCount(1);
    }
}
