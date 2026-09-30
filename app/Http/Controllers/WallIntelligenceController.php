<?php

namespace App\Http\Controllers;

use App\Services\WallIntelligenceService;
use App\Support\EodCacheVersion;
use App\Support\Symbols;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class WallIntelligenceController extends Controller
{
    public function show(Request $request)
    {
        $request->validate([
            'symbol' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9.^-]+$/'],
            'timeframe' => ['required', Rule::in(\App\Services\AiExportBuilder::GEX_TIMEFRAMES)],
            'view' => ['nullable', Rule::in(['latest_eod', 'next_session'])],
            'session_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $versions = app(EodCacheVersion::class);
        $symbol = Symbols::canon($request->query('symbol'));
        $version = $versions->current(EodCacheVersion::DOMAIN_GEX, $symbol);
        $response = app(GexController::class)->getGexLevels($request);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        $levels = $response->getData(true);
        $key = 'wall-intelligence:v1:'.hash('sha256', json_encode([$levels, $version]));
        $result = Cache::remember($key, now()->addMinutes(5), fn () => app(WallIntelligenceService::class)->build($levels));
        abort_if($version !== $versions->current(EodCacheVersion::DOMAIN_GEX, $symbol), 409, 'Refresh the dashboard to update wall analysis.');

        return response()->json($result);
    }
}
