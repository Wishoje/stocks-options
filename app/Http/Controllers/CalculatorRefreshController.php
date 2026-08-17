<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkRunRateLimited;
use App\Support\QueueLanes;
use App\Support\Symbols;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CalculatorRefreshController extends Controller
{
    public function store(
        Request $request,
        WorkRunCoordinator $runs,
        WorkRunDispatcher $dispatcher
    ): JsonResponse {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:32'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
            'force' => ['sometimes', 'boolean'],
            'sync' => ['sometimes', 'boolean'],
        ]);

        $symbol = Symbols::canon((string) $validated['symbol']);
        if (! Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }

        $expiry = isset($validated['expiry']) ? substr((string) $validated['expiry'], 0, 10) : null;
        $queue = QueueLanes::calculator($symbol, interactive: $expiry !== null);
        try {
            $claim = $runs->claim(
                'calculator_refresh',
                $symbol,
                ['expiry' => $expiry],
                $queue,
                $request->user(),
                reuseCompleted: ! $request->boolean('force')
            );
        } catch (WorkRunRateLimited $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Refresh capacity is temporarily full. Please retry later.',
                'code' => 'work_rate_limited',
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ], 429, ['Retry-After' => (string) $exception->retryAfterSeconds]);
        }
        $run = $claim['run'];
        $queued = false;

        if ($claim['created']) {
            try {
                $queued = $dispatcher->dispatch($run);
            } catch (Throwable $exception) {
                return response()->json(array_merge(
                    [
                        'ok' => false,
                        'message' => 'The calculator refresh could not be queued. Please retry.',
                        'retry_after_seconds' => 2,
                    ],
                    $runs->payload($run->fresh())
                ), 503, ['Retry-After' => '2']);
            }
        }

        return response()->json(array_merge([
            'ok' => true,
            'symbol' => $symbol,
            'expiry' => $expiry,
            'mode' => 'queue',
            'sync_disabled' => (bool) $request->boolean('sync'),
            'sync_disabled_by_lane_isolation' => (bool) $request->boolean('sync'),
            'queued' => $queued,
            'coalesced' => ! $claim['created'],
            'force' => (bool) $request->boolean('force'),
            'lock_ttl_seconds' => null,
        ], $runs->payload($run)), $claim['created'] ? 202 : 200);
    }
}
