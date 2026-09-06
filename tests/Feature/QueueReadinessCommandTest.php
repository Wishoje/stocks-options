<?php

namespace Tests\Feature;

use App\Support\QueueReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class QueueReadinessCommandTest extends TestCase
{
    public function test_json_failure_returns_nonzero_and_preserves_safe_evidence(): void
    {
        $report = ['checks_passed' => false, 'activation_verified' => false, 'errors' => ['redis_aof_disabled:default']];
        $readiness = Mockery::mock(QueueReadiness::class);
        $readiness->shouldReceive('inspect')->once()->andReturn($report);
        $this->app->instance(QueueReadiness::class, $readiness);

        $this->assertSame(1, Artisan::call('queue:readiness', ['--json' => true]));
        $this->assertSame($report, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_json_success_does_not_claim_external_activation_proof(): void
    {
        $report = ['checks_passed' => true, 'activation_verified' => false, 'errors' => []];
        $readiness = Mockery::mock(QueueReadiness::class);
        $readiness->shouldReceive('inspect')->once()->andReturn($report);
        $this->app->instance(QueueReadiness::class, $readiness);

        $this->assertSame(0, Artisan::call('queue:readiness', ['--json' => true]));
        $this->assertFalse(json_decode(Artisan::output(), true)['activation_verified']);
    }

    public function test_log_option_records_safe_snapshot_with_failure_severity(): void
    {
        $report = ['checks_passed' => false, 'activation_verified' => false, 'errors' => ['redis_aof_disabled:default']];
        $readiness = Mockery::mock(QueueReadiness::class);
        $readiness->shouldReceive('inspect')->once()->andReturn($report);
        $this->app->instance(QueueReadiness::class, $readiness);
        $logger = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('queue_monitor')->once()->andReturn($logger);
        $logger->shouldReceive('log')->with('warning', 'queue.readiness', $report)->once();

        $this->assertSame(1, Artisan::call('queue:readiness', ['--json' => true, '--log' => true]));
        $this->assertSame($report, json_decode(Artisan::output(), true));
    }

    public function test_log_failure_returns_nonzero_without_exposing_exception_text(): void
    {
        $report = ['checks_passed' => true, 'activation_verified' => false, 'errors' => []];
        $readiness = Mockery::mock(QueueReadiness::class);
        $readiness->shouldReceive('inspect')->once()->andReturn($report);
        $this->app->instance(QueueReadiness::class, $readiness);
        Log::shouldReceive('channel')->with('queue_monitor')->once()
            ->andThrow(new RuntimeException('secret-sentinel'));

        $this->assertSame(1, Artisan::call('queue:readiness', ['--json' => true, '--log' => true]));
        $this->assertContains('queue_readiness_log_write_failed', json_decode(Artisan::output(), true)['errors']);
        $this->assertStringNotContainsString('secret-sentinel', Artisan::output());
    }
}
