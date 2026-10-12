<?php

namespace App\Http\Controllers;

use App\Services\IntradayVolatilityService;
use App\Support\Symbols;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IntradayVolatilityController extends Controller
{
    public function show(Request $request)
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9.^-]+$/'],
            'session' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $symbol = Symbols::canon($validated['symbol']);
        $session = $validated['session'] ?? null;
        $expiry = $validated['expiry'] ?? null;
        $key = 'intraday-volatility:v1:'.hash('sha256', json_encode([$symbol, $session, $expiry]));
        $payload = Cache::remember($key, 120, fn () => app(IntradayVolatilityService::class)->build($symbol, $session, $expiry));

        return response()->json($payload);
    }
}
