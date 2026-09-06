<?php

namespace Tests\Unit;

use App\Support\QueueTelemetry;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class QueueTelemetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_payload_metadata_adds_only_the_enqueue_stamp(): void
    {
        [$telemetry] = $this->telemetry();

        $this->assertSame(['gex_enqueued_at' => 1700], $telemetry->payloadMetadata(1700));
    }

    public function test_head_age_reads_only_json_metadata_without_returning_job_data(): void
    {
        $payload = json_encode([
            'gex_enqueued_at' => 1700,
            'data' => ['command' => 'serialized-secret-payload', 'password' => 'secret-sentinel'],
        ]);
        $age = QueueTelemetry::headReadyAge($payload, 2000);

        $this->assertSame(['seconds' => 300, 'status' => 'recorded'], $age);
        $this->assertStringNotContainsString('secret-sentinel', json_encode($age));
    }

    public function test_legacy_malformed_oversized_and_future_payloads_report_unknown_age(): void
    {
        $cases = [
            [null, 'queue_empty_or_changed'],
            [false, 'queue_empty_or_changed'],
            ['{"data":{"command":"serialized-secret-payload"}}', 'enqueue_timestamp_not_recorded'],
            ['{"gex_enqueued_at":', 'invalid_payload'],
            ['null', 'invalid_payload'],
            ['{"gex_enqueued_at":2001}', 'future_enqueue_timestamp'],
            ['{"gex_enqueued_at":-1}', 'invalid_enqueue_timestamp'],
            ['{"gex_enqueued_at":[]}', 'invalid_enqueue_timestamp'],
            [str_repeat('x', QueueTelemetry::MAX_INSPECTED_PAYLOAD_BYTES + 1), 'payload_exceeds_inspection_limit'],
        ];
        foreach ($cases as [$payload, $status]) {
            $this->assertSame(['seconds' => null, 'status' => $status], QueueTelemetry::headReadyAge($payload, 2000));
        }
    }

    public function test_processing_records_allowlisted_metadata_and_age_including_delays_and_retries(): void
    {
        [$telemetry, $logs] = $this->telemetry();
        $logger = Mockery::mock(LoggerInterface::class);
        $logs->shouldReceive('channel')->with('queue_monitor')->once()->andReturn($logger);
        $logger->shouldReceive('log')->with('info', 'queue.job.processing', [
            'connection' => 'redis',
            'queue' => 'intraday-heavy',
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'attempt' => 2,
            'age_since_enqueue_seconds' => 600,
            'enqueue_age_status' => 'recorded',
            'age_includes_intentional_delay_and_retries' => true,
            'processing_sample_rate' => 1.0,
        ])->once();

        $telemetry->processing($this->event(['gex_enqueued_at' => 1000, 'password' => 'secret-sentinel']), 1600);
        $this->addToAssertionCount(1);
    }

    public function test_processing_legacy_job_keeps_age_unknown(): void
    {
        [$telemetry, $logs] = $this->telemetry();
        $logger = Mockery::mock(LoggerInterface::class);
        $logs->shouldReceive('channel')->with('queue_monitor')->once()->andReturn($logger);
        $logger->shouldReceive('log')->once()->withArgs(
            static fn (string $level, string $event, array $context): bool => $event === 'queue.job.processing'
                && $context['age_since_enqueue_seconds'] === null
                && $context['enqueue_age_status'] === 'enqueue_timestamp_not_recorded',
        );

        $telemetry->processing($this->event(['data' => ['command' => 'secret-sentinel']]), 1600);
        $this->addToAssertionCount(1);
    }

    public function test_disabled_or_zero_sample_processing_does_not_write_logs(): void
    {
        [$disabled] = $this->telemetry(['enabled' => false]);
        [$zero] = $this->telemetry(['processing_sample_rate' => 0]);
        $disabled->processing($this->event([]), 1600);
        $zero->processing($this->event([]), 1600);

        $this->addToAssertionCount(1);
    }

    public function test_unsafe_context_values_and_log_failure_cannot_leak_secrets_or_fail_job(): void
    {
        [$telemetry, $logs] = $this->telemetry(['log_level' => 'debug']);
        $logger = Mockery::mock(LoggerInterface::class);
        $logs->shouldReceive('channel')->with('queue_monitor')->once()->andReturn($logger);
        $logger->shouldReceive('log')->once()->withArgs(
            static fn (string $level, string $event, array $context): bool => $level === 'debug'
                && $context['connection'] === null && $context['queue'] === null
                && ! str_contains(json_encode($context), 'secret-sentinel'),
        )->andThrow(new RuntimeException('Log unavailable with secret-sentinel'));

        $event = $this->event(['gex_enqueued_at' => 1000], 'redis://secret-sentinel', 'bad/secret-sentinel');
        $telemetry->processing($event, 1600);
        $this->addToAssertionCount(1);
    }

    private function telemetry(array $overrides = []): array
    {
        $config = new Repository(['queue' => ['telemetry' => array_replace([
            'enabled' => true, 'processing_sample_rate' => 1, 'log_level' => 'info',
        ], $overrides)]]);
        $logs = Mockery::mock(LogManager::class);

        return [new QueueTelemetry($config, $logs), $logs];
    }

    private function event(array $payload, string $connection = 'redis', string $queue = 'intraday-heavy'): JobProcessing
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('uuid')->andReturn('00000000-0000-4000-8000-000000000001');
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('attempts')->andReturn(2);

        return new JobProcessing($connection, $job);
    }
}
