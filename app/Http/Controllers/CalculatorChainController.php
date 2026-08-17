<?php

namespace App\Http\Controllers;

use App\Services\CalculatorChainReadService;
use App\Support\Symbols;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalculatorChainController extends Controller
{
    public function show(Request $request, CalculatorChainReadService $chains): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:32'],
            'expiry' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $symbol = Symbols::canon((string) ($validated['symbol'] ?? 'SPY'));
        if (! Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }

        $result = $chains->read($symbol, $validated['expiry'] ?? null);

        return response()->json($result['payload'], $result['status']);
    }
}
