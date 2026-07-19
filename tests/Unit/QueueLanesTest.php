<?php

namespace Tests\Unit;

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PrimeSymbolJob;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\QueueLanes;
use LogicException;
use Tests\TestCase;

class QueueLanesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 4);
        config()->set('queue_lanes.intraday_heavy_symbols', ['SPY', 'QQQ']);
        config()->set('queue_lanes.calculator_heavy_symbols', ['SPY', 'QQQ', 'IWM']);
    }

    public function test_legacy_routing_remains_the_default(): void
    {
        config()->set('queue_lanes.isolated', false);

        $this->assertSame('bootstrap', (new BootstrapUserSymbolJob('AAPL'))->queue);
        $this->assertSame('prime', (new PrimeSymbolJob('AAPL'))->queue);
        $this->assertSame('calculator', (new FetchCalculatorChainJob('SPY'))->queue);
        $this->assertSame('intraday', (new FetchPolygonIntradayOptionsJob(['AAPL']))->queue);
        $this->assertSame('intraday-heavy', (new FetchPolygonIntradayOptionsJob(['SPY']))->queue);
        $this->assertSame(
            'intraday-heavy',
            (new FetchPolygonIntradayOptionsJob(['AAPL', 'MSFT']))->queue
        );
    }

    public function test_both_disabled_flags_keep_legacy_routing_and_limiter_passthrough(): void
    {
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('services.massive.concurrency.limit', 0);

        $this->assertSame('bootstrap', QueueLanes::bootstrap());
        $this->assertSame('calculator', QueueLanes::calculator('SPY', interactive: true));
        $this->assertSame('intraday-heavy', QueueLanes::intradayBatch(['SPY', 'QQQ']));
        $this->assertSame(
            'legacy-provider-result',
            app(ProviderConcurrencyLimiter::class)->massive(
                fn (): string => 'legacy-provider-result'
            )
        );
    }

    public function test_isolated_routing_reserves_interactive_and_fill_lanes(): void
    {
        config()->set('queue_lanes.isolated', true);

        $this->assertSame('bootstrap-fast', (new BootstrapUserSymbolJob('AAPL'))->queue);
        $this->assertSame('default', (new PrimeSymbolJob('AAPL'))->queue);
        $this->assertSame('calculator-fill', QueueLanes::calculator('AAPL'));
        $this->assertSame('calculator-fill-heavy', QueueLanes::calculator('SPY'));
        $this->assertSame('calculator-interactive', QueueLanes::calculator('SPY', true));
        $this->assertSame('intraday', QueueLanes::intraday('AAPL'));
        $this->assertSame('intraday-interactive', QueueLanes::intraday('AAPL', true));
        $this->assertSame('intraday-heavy', QueueLanes::intraday('SPY', true));

        $this->assertSame(
            QueueLanes::PRIORITY_INTERACTIVE,
            QueueLanes::providerPriority('calculator-interactive')
        );
        $this->assertSame(
            QueueLanes::PRIORITY_BACKGROUND,
            QueueLanes::providerPriority('calculator-fill-heavy')
        );
    }

    public function test_each_heavy_intraday_symbol_gets_a_separate_job(): void
    {
        config()->set('queue_lanes.isolated', true);

        $batches = QueueLanes::scheduledIntradayBatches(
            ['AAPL', 'SPY', 'QQQ', 'MSFT', 'NVDA'],
            2
        );

        $this->assertSame([
            ['SPY'],
            ['QQQ'],
            ['AAPL', 'MSFT'],
            ['NVDA'],
        ], $batches);
    }

    public function test_disabled_isolation_does_not_increase_batch_concurrency(): void
    {
        config()->set('queue_lanes.isolated', false);

        $this->assertSame(
            [['SPY', 'QQQ', 'AAPL']],
            QueueLanes::scheduledIntradayBatches(['SPY', 'QQQ', 'AAPL'], 15)
        );
    }

    public function test_isolated_routing_fails_closed_without_the_provider_gate(): void
    {
        config()->set('queue_lanes.isolated', true);
        config()->set('services.massive.concurrency.enabled', false);

        $this->expectException(LogicException::class);
        QueueLanes::bootstrap();
    }

    public function test_isolated_routing_fails_closed_without_an_explicit_provider_limit(): void
    {
        config()->set('queue_lanes.isolated', true);
        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 0);

        $this->expectException(LogicException::class);
        QueueLanes::bootstrap();
    }

    public function test_runtime_limiter_rejects_stale_config_for_a_serialized_job_context(): void
    {
        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        $job = unserialize(serialize(new FetchUnderlyingQuotesJob(['AAPL'])));
        $this->assertSame('quotes', $job->queue);

        config()->set('queue_lanes.isolated', true);
        $callbackRan = false;

        try {
            $limiter = app(ProviderConcurrencyLimiter::class);
            $limiter->withPriority(
                QueueLanes::PRIORITY_INTERACTIVE,
                fn () => $limiter->massive(function () use (&$callbackRan): void {
                    $callbackRan = true;
                })
            );
            $this->fail('A stale worker configuration must fail closed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('MASSIVE_CONCURRENCY_ENABLED', $exception->getMessage());
        }

        $this->assertFalse($callbackRan);
    }
}
