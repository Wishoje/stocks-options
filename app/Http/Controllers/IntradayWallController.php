<?php

namespace App\Http\Controllers;

use App\Services\IntradayWallTracker;
use App\Support\WallIntelligence\IntradayWallDemo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntradayWallController extends Controller
{
    public function show(Request $request, IntradayWallTracker $tracker): JsonResponse
    {
        $input = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9.\-]{0,9}$/'],
            'session' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'demo' => ['sometimes', 'boolean'],
            'timeframe' => ['sometimes', Rule::in(IntradayWallTracker::TIMEFRAMES)],
        ]);
        $result = $request->boolean('demo')
            ? app(IntradayWallDemo::class)->build($input['symbol'], $input['timeframe'] ?? '14d')
            : $tracker->history($input['symbol'], $input['session'] ?? null, $input['timeframe'] ?? '14d');

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
