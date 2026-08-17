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

class SymbolPrimeController extends Controller
{
    public function store(
        Request $request,
        WorkRunCoordinator $runs,
        WorkRunDispatcher $dispatcher
    ): JsonResponse {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:10'],
        ]);
        $symbol = Symbols::canon((string) $validated['symbol']);
        if (! Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }

        $queue = QueueLanes::bootstrap();
        try {
            $claim = $runs->claim(
                'symbol_bootstrap',
                $symbol,
                [],
                $queue,
                $request->user()
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
                        'message' => 'The symbol refresh could not be queued. Please retry.',
                        'retry_after_seconds' => 2,
                    ],
                    $runs->payload($run->fresh())
                ), 503, ['Retry-After' => '2']);
            }
        }

        return response()->json(array_merge([
            'ok' => true,
            'queued' => $queued,
            'coalesced' => ! $claim['created'],
        ], $runs->payload($run)), $claim['created'] ? 202 : 200);
    }
}
