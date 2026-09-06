<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/** Operator-only, bounded reads. Never requests or publishes a quote. */
class QuoteRefreshStatus
{
    public function inspect(array $symbols): array
    {
        if (count($symbols) > 20) {
            throw new \InvalidArgumentException('Quote status accepts at most 20 symbols.');
        }
        $policy = app(QuoteRefreshPolicy::class);
        $window = $policy->window();
        if (! QuoteRefreshPolicy::enabled()) {
            return ['enabled' => false, 'window' => $window];
        }
        $queue = QueueLanes::quotes();
        $transport = (string) config('queue.default');
        $queueStatus = ['queue' => $queue, 'available' => false];
        try {
            if (config('queue.connections.'.$transport.'.driver') !== 'redis') {
                throw new \LogicException('Redis queue transport required.');
            }
            $samples = Redis::connection((string) config('queue.connections.'.$transport.'.connection'))
                ->pipeline(function ($pipe) use ($queue): void {
                    $key = 'queues:'.$queue;
                    $pipe->lindex($key, 0);
                    $pipe->llen($key);
                    $pipe->zcard($key.':reserved');
                    $pipe->zcard($key.':delayed');
                });
            $age = QueueTelemetry::headReadyAge($samples[0], time());
            $queueStatus += [
                'ready' => (int) $samples[1], 'reserved' => (int) $samples[2], 'delayed' => (int) $samples[3],
                'ready_head_age_seconds' => $age['seconds'], 'ready_head_age_status' => $age['status'],
            ];
            $queueStatus['available'] = true;
        } catch (\Throwable) {
            $queueStatus['error'] = 'quote_queue_telemetry_unavailable';
        }
        $runs = DB::table('work_runs')->where('kind', 'quote_refresh')->whereIn('status', ['pending', 'running'])
            ->select('status')->selectRaw('COUNT(*) AS total, MIN(requested_at) AS oldest_requested_at')
            ->groupBy('status')->get()->keyBy('status');
        $oldest = $runs->get('pending')?->oldest_requested_at;
        $states = DB::table('quote_refresh_states')->where('session_date', $window['session_date'])
            ->whereIn('symbol', $symbols)->get()->keyBy('symbol');
        $quotes = DB::table('underlying_quotes')->whereIn('symbol', $symbols)
            ->get(['symbol', 'last_price', 'prev_close', 'asof', 'source'])->keyBy('symbol');
        $items = [];
        foreach ($symbols as $symbol) {
            $state = $states->get($symbol);
            $items[] = [
                'symbol' => $symbol, 'published_quote' => $quotes->get($symbol),
                'scheduled_refresh_due' => $policy->isDue($state, $window),
                'session_refresh_state' => $state,
            ];
        }

        return [
            'enabled' => true, 'window' => $window, 'queue' => $queueStatus,
            'pending_intents' => (int) ($runs->get('pending')?->total ?? 0),
            'running_intents' => (int) ($runs->get('running')?->total ?? 0),
            'oldest_pending_intent_age_seconds' => $oldest
                ? max(0, (int) CarbonImmutable::parse($oldest, 'UTC')->diffInSeconds(now('UTC'))) : null,
            'symbols' => $items,
        ];
    }
}
