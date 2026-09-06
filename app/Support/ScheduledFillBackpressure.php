<?php

namespace App\Support;

use App\Exceptions\ProviderDeferred;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Delay recoverable fill intent; never pause the interactive consumers. */
class ScheduledFillBackpressure
{
    public function inspect(bool $admission = true): array
    {
        if (! config('provider_backpressure.enabled', false)) {
            return ['deferred' => false, 'reason' => null, 'queues' => []];
        }
        try {
            $measurements = Cache::remember('provider-backpressure:queue-sample:v1', 2, fn (): array => $this->sample());
        } catch (Throwable) {
            return ['deferred' => true, 'reason' => 'queue_telemetry_unavailable', 'queues' => []];
        }
        $interactiveDepth = 0;
        $fillDepth = 0;
        $oldestInteractive = 0;
        $oldestFill = 0;
        foreach ($measurements as $queue) {
            if ($queue['interactive']) {
                $interactiveDepth += $queue['ready'];
                $oldestInteractive = max($oldestInteractive, $queue['ready_head_age_seconds'] ?? 0, $queue['oldest_due_intent_age_seconds'] ?? 0);
            } else {
                $fillDepth += $queue['ready'];
                $oldestFill = max($oldestFill, $queue['ready_head_age_seconds'] ?? 0, $queue['oldest_due_intent_age_seconds'] ?? 0);
            }
        }
        $reason = match (true) {
            $interactiveDepth >= max(1, (int) config('provider_backpressure.interactive_depth', 6)) => 'interactive_depth',
            $oldestInteractive >= max(1, (int) config('provider_backpressure.interactive_head_age_seconds', 30)) => 'interactive_wait',
            $admission && $fillDepth >= max(1, (int) config('provider_backpressure.fill_depth', 50)) => 'fill_depth',
            $admission && $oldestFill >= max(1, (int) config('provider_backpressure.fill_head_age_seconds', 120)) => 'fill_wait',
            default => null,
        };

        return ['deferred' => $reason !== null, 'reason' => $reason, 'queues' => $measurements];
    }

    public function deferral(bool $admission = true): ?ProviderDeferred
    {
        return $this->inspect($admission)['deferred']
            ? new ProviderDeferred(ProviderDeferred::BACKPRESSURE, CarbonImmutable::now('UTC')->addSeconds(15 + random_int(1, 5)))
            : null;
    }

    protected function sample(): array
    {
        // Read queue transport only; never infer its host from the cache connection.
        $connection = (string) config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') !== 'redis') {
            throw new \LogicException('Fill telemetry requires Redis queues.');
        }
        $redis = Redis::connection((string) config('queue.connections.'.$connection.'.connection'));
        $lanes = [
            'bootstrap_fast' => true, 'intraday_interactive' => true, 'calculator_interactive' => true,
            'calculator_fill' => false, 'calculator_fill_heavy' => false,
        ];
        $result = [];
        $names = array_map(static fn (string $lane): string => (string) config('queue_lanes.queues.'.$lane, str_replace('_', '-', $lane)), array_keys($lanes));
        $oldest = DB::table('work_runs')->whereIn('queue', $names)->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', now('UTC')))
            ->select('queue')->selectRaw('MIN(requested_at) as oldest_requested_at')
            ->groupBy('queue')->pluck('oldest_requested_at', 'queue')->all();
        $samples = $redis->pipeline(function ($pipe) use ($names): void {
            foreach ($names as $name) {
                $key = 'queues:'.$name;
                $pipe->lindex($key, 0);
                $pipe->llen($key);
                $pipe->zcard($key.':reserved');
                $pipe->zcard($key.':delayed');
            }
        });
        $offset = 0;
        foreach ($lanes as $lane => $interactive) {
            $name = (string) config('queue_lanes.queues.'.$lane, str_replace('_', '-', $lane));
            $age = QueueTelemetry::headReadyAge($samples[$offset], time());
            $result[] = [
                'queue' => $name, 'interactive' => $interactive,
                'ready' => (int) $samples[$offset + 1],
                'reserved' => (int) $samples[$offset + 2],
                'delayed' => (int) $samples[$offset + 3],
                'ready_head_age_seconds' => $age['seconds'],
                'ready_head_age_status' => $age['status'],
                'oldest_due_intent_age_seconds' => isset($oldest[$name])
                    ? max(0, (int) CarbonImmutable::parse($oldest[$name], 'UTC')->diffInSeconds(now('UTC'))) : null,
            ];
            $offset += 4;
        }

        return $result;
    }
}
