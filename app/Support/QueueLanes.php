<?php

namespace App\Support;

use LogicException;

final class QueueLanes
{
    public const PRIORITY_INTERACTIVE = 'interactive';

    public const PRIORITY_BACKGROUND = 'background';

    public static function isolated(): bool
    {
        $enabled = (bool) config('queue_lanes.isolated', false);

        $providerGateEnabled = (bool) config('services.massive.concurrency.enabled', false);
        $providerLimit = (int) config('services.massive.concurrency.limit', 0);

        if ($enabled && ! $providerGateEnabled) {
            throw new LogicException(
                'QUEUE_LANES_ISOLATED requires MASSIVE_CONCURRENCY_ENABLED=true.'
            );
        }
        if ($enabled && $providerLimit < 2) {
            throw new LogicException(
                'QUEUE_LANES_ISOLATED requires an explicit MASSIVE_CONCURRENCY_LIMIT of at least 2.'
            );
        }

        return $enabled;
    }

    public static function bootstrap(): string
    {
        return self::isolated()
            ? self::queue('bootstrap_fast')
            : (string) config('queue_lanes.legacy.bootstrap', 'bootstrap');
    }

    public static function bootstrapChild(): string
    {
        // GEX-004 changes capacity ownership only. The ordered bootstrap graph
        // remains on one lane until GEX-010 introduces durable fast/fill phases.
        return self::bootstrap();
    }

    public static function enrichment(): string
    {
        return self::isolated()
            ? self::queue('enrichment')
            : (string) config('queue_lanes.legacy.prime', 'prime');
    }

    public static function quotes(): string
    {
        self::isolated();

        return self::queue('quotes');
    }

    public static function intraday(string $symbol, bool $interactive = false): string
    {
        $isolated = self::isolated();

        if (self::isIntradayHeavy($symbol)) {
            return self::queue('intraday_heavy');
        }

        if ($interactive && $isolated) {
            return self::queue('intraday_interactive');
        }

        return self::queue('intraday');
    }

    /**
     * Resolve a queue for a job that may still contain more than one symbol.
     * Multi-symbol work stays on the long lane until singleton dispatch is
     * completed under GEX-018.
     *
     * @param  string[]  $symbols
     */
    public static function intradayBatch(array $symbols, bool $interactive = false): string
    {
        self::isolated();

        $canonical = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->values();

        if ($canonical->count() !== 1) {
            return self::queue('intraday_heavy');
        }

        return self::intraday((string) $canonical->first(), $interactive);
    }

    /**
     * Keep every measured-heavy symbol in its own job while retaining the
     * existing normal-symbol batch size. GEX-018 later makes every item a
     * singleton after its data-equivalence and provider-load proof.
     *
     * @param  string[]  $symbols
     * @return array<int, array<int, string>>
     */
    public static function scheduledIntradayBatches(array $symbols, int $normalBatchSize): array
    {
        $normalBatchSize = max(1, $normalBatchSize);
        $canonical = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->values();

        // With isolation disabled, retain one payload exactly as supplied by
        // callers that historically submitted a batch. Producer-specific
        // legacy grouping remains in the scheduler and warmup command.
        if (! self::isolated()) {
            return $canonical->isEmpty() ? [] : [$canonical->all()];
        }

        $heavy = $canonical
            ->filter(static fn (string $symbol): bool => self::isIntradayHeavy($symbol))
            ->map(static fn (string $symbol): array => [$symbol])
            ->values()
            ->all();

        $normal = array_chunk(
            $canonical
                ->reject(static fn (string $symbol): bool => self::isIntradayHeavy($symbol))
                ->values()
                ->all(),
            $normalBatchSize
        );

        return array_merge($heavy, $normal);
    }

    public static function calculator(string $symbol, bool $interactive = false): string
    {
        if (! self::isolated()) {
            return (string) config('queue_lanes.legacy.calculator', 'calculator');
        }

        if ($interactive) {
            return self::queue('calculator_interactive');
        }

        return self::isCalculatorHeavy($symbol)
            ? self::queue('calculator_fill_heavy')
            : self::queue('calculator_fill');
    }

    public static function isIntradayHeavy(string $symbol): bool
    {
        return in_array(
            Symbols::canon($symbol),
            config('queue_lanes.intraday_heavy_symbols', ['SPY', 'QQQ']),
            true
        );
    }

    public static function isCalculatorHeavy(string $symbol): bool
    {
        return in_array(
            Symbols::canon($symbol),
            config('queue_lanes.calculator_heavy_symbols', ['SPY', 'QQQ', 'IWM']),
            true
        );
    }

    public static function providerPriority(?string $queue): string
    {
        self::isolated();

        $interactiveQueues = [
            self::queue('bootstrap_fast'),
            self::queue('intraday_interactive'),
            self::queue('calculator_interactive'),
            self::queue('quotes'),
        ];

        return in_array((string) $queue, $interactiveQueues, true)
            ? self::PRIORITY_INTERACTIVE
            : self::PRIORITY_BACKGROUND;
    }

    private static function queue(string $lane): string
    {
        return (string) config("queue_lanes.queues.{$lane}", $lane);
    }
}
