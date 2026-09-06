<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use App\Support\ScheduledFillBackpressure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ScheduledFillBackpressureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('provider_backpressure.enabled', true);
        Cache::flush();
    }

    public function test_disabled_policy_does_not_inspect_queues(): void
    {
        config()->set('provider_backpressure.enabled', false);
        $service = $this->service([], true);
        $this->assertFalse($service->inspect()['deferred']);
    }

    public function test_interactive_depth_pauses_fill_admission_and_execution(): void
    {
        $service = $this->service([$this->queue(true, 6, 0)]);
        $this->assertSame('interactive_depth', $service->inspect()['reason']);
        $this->assertTrue($service->inspect(false)['deferred']);
        $this->assertSame(ProviderDeferred::BACKPRESSURE, $service->deferral()->reason);
        $this->assertGreaterThanOrEqual(15, $service->deferral()->retryAfterSeconds(now('UTC')));
    }

    public function test_interactive_wait_threshold_is_exact(): void
    {
        $service = $this->service([$this->queue(true, 1, 29)]);
        $this->assertFalse($service->inspect()['deferred']);
        Cache::flush();
        $this->assertSame('interactive_wait', $this->service([$this->queue(true, 1, 30)])->inspect()['reason']);
    }

    public function test_fill_backlog_delays_new_intent_but_does_not_deadlock_existing_consumers(): void
    {
        $service = $this->service([$this->queue(false, 50, 300)]);
        $this->assertSame('fill_depth', $service->inspect()['reason']);
        $this->assertFalse($service->inspect(false)['deferred']);
    }

    public function test_old_fill_head_delays_admission_without_claiming_global_oldest_age(): void
    {
        $service = $this->service([$this->queue(false, 1, 120)]);
        $result = $service->inspect();
        $this->assertSame('fill_wait', $result['reason']);
        $this->assertSame(120, $result['queues'][0]['ready_head_age_seconds']);
        $this->assertFalse($service->inspect(false)['deferred']);
    }

    public function test_telemetry_failure_defers_fill_without_throwing_or_touching_provider(): void
    {
        $this->assertSame('queue_telemetry_unavailable', $this->service([], true)->inspect()['reason']);
    }

    public function test_zero_backlog_allows_fill(): void
    {
        $service = $this->service([$this->queue(true, 0, null), $this->queue(false, 0, null)]);
        $this->assertFalse($service->inspect()['deferred']);
        $this->assertNull($service->deferral());
    }

    private function queue(bool $interactive, int $ready, ?int $age): array
    {
        return ['interactive' => $interactive, 'ready' => $ready, 'ready_head_age_seconds' => $age];
    }

    private function service(array $queues, bool $fail = false): ScheduledFillBackpressure
    {
        return new class($queues, $fail) extends ScheduledFillBackpressure
        {
            public function __construct(private array $queues, private bool $fail) {}

            protected function sample(): array
            {
                if ($this->fail) {
                    throw new RuntimeException('Fixture telemetry unavailable.');
                }

                return $this->queues;
            }
        };
    }
}
