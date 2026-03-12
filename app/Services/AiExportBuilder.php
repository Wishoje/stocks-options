<?php

namespace App\Services;

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ExpiryController;
use App\Http\Controllers\GexController;
use App\Http\Controllers\PositioningController;
use App\Http\Controllers\QScoreController;
use App\Http\Controllers\SeasonalityController;
use App\Http\Controllers\VolController;
use App\Models\SymbolWallSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AiExportBuilder
{
    public const EXPORTABLE_INDICATORS = [
        'wall_snapshots',
        'gex_levels',
        'qscore',
        'dealer_positioning',
        'expiry_pressure',
        'iv_skew',
        'term_structure',
        'vrp',
        'seasonality',
        'unusual_activity',
    ];

    public const GEX_TIMEFRAMES = ['0d', '1d', '7d', '14d', '21d', '30d', '45d', '60d', '90d', 'monthly'];
    private const WALL_TIMEFRAMES = ['1d', '7d', '14d', '30d'];

    public function build(array $symbols, array $indicators, string $timeframe): array
    {
        $items = collect($symbols)->map(function (string $symbol) use ($indicators, $timeframe) {
            $item = ['symbol' => $symbol];

            foreach ($indicators as $indicator) {
                $item[$indicator] = $this->buildIndicatorPayload($indicator, $symbol, $timeframe);
            }

            return $item;
        })->values();

        return [
            'generated_at' => now('America/Chicago')->toIso8601String(),
            'source' => 'watchlist_eod_ai_export',
            'symbol_count' => $items->count(),
            'symbols' => array_values($symbols),
            'indicators' => array_values($indicators),
            'options' => [
                'gex_timeframe' => $timeframe,
                'format' => 'json',
            ],
            'items' => $items,
        ];
    }

    private function buildIndicatorPayload(string $indicator, string $symbol, string $timeframe): array
    {
        return match ($indicator) {
            'wall_snapshots' => $this->latestWallSnapshots($symbol),
            'gex_levels' => $this->invokeController(GexController::class, 'getGexLevels', [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
            ]),
            'qscore' => $this->invokeController(QScoreController::class, 'show', ['symbol' => $symbol]),
            'dealer_positioning' => $this->invokeController(PositioningController::class, 'dex', ['symbol' => $symbol]),
            'expiry_pressure' => $this->invokeController(ExpiryController::class, 'pressure', [
                'symbol' => $symbol,
                'days' => 3,
            ]),
            'iv_skew' => $this->invokeController(VolController::class, 'skew', ['symbol' => $symbol]),
            'term_structure' => $this->invokeController(VolController::class, 'term', ['symbol' => $symbol]),
            'vrp' => $this->invokeController(VolController::class, 'vrp', ['symbol' => $symbol]),
            'seasonality' => $this->invokeController(SeasonalityController::class, 'fiveDay', ['symbol' => $symbol]),
            'unusual_activity' => $this->invokeController(ActivityController::class, 'index', [
                'symbol' => $symbol,
                'per_expiry' => 5,
                'limit' => 25,
                'sort' => 'z_score',
                'with_premium' => true,
            ]),
            default => [
                'ok' => false,
                'status' => 422,
                'error' => 'Unsupported indicator.',
            ],
        };
    }

    private function latestWallSnapshots(string $symbol): array
    {
        $latest = SymbolWallSnapshot::query()
            ->select('timeframe', DB::raw('MAX(trade_date) as max_trade_date'))
            ->where('symbol', $symbol)
            ->whereIn('timeframe', self::WALL_TIMEFRAMES)
            ->groupBy('timeframe');

        $rows = SymbolWallSnapshot::query()
            ->joinSub($latest, 'latest', function ($join) {
                $join->on('symbol_wall_snapshots.timeframe', '=', 'latest.timeframe')
                    ->on('symbol_wall_snapshots.trade_date', '=', 'latest.max_trade_date');
            })
            ->where('symbol_wall_snapshots.symbol', $symbol)
            ->orderBy('symbol_wall_snapshots.timeframe')
            ->get([
                'symbol_wall_snapshots.symbol',
                'symbol_wall_snapshots.trade_date',
                'symbol_wall_snapshots.timeframe',
                'symbol_wall_snapshots.spot',
                'symbol_wall_snapshots.eod_put_wall',
                'symbol_wall_snapshots.eod_call_wall',
                'symbol_wall_snapshots.eod_put_dist_pct',
                'symbol_wall_snapshots.eod_call_dist_pct',
                'symbol_wall_snapshots.intraday_put_wall',
                'symbol_wall_snapshots.intraday_call_wall',
                'symbol_wall_snapshots.intraday_put_dist_pct',
                'symbol_wall_snapshots.intraday_call_dist_pct',
            ]);

        return [
            'ok' => true,
            'status' => 200,
            'data' => [
                'symbol' => $symbol,
                'items' => $rows->map(fn ($row) => [
                    'symbol' => $row->symbol,
                    'trade_date' => $row->trade_date,
                    'timeframe' => $row->timeframe,
                    'spot' => $row->spot !== null ? (float) $row->spot : null,
                    'eod_put_wall' => $row->eod_put_wall !== null ? (float) $row->eod_put_wall : null,
                    'eod_call_wall' => $row->eod_call_wall !== null ? (float) $row->eod_call_wall : null,
                    'eod_put_dist_pct' => $row->eod_put_dist_pct !== null ? (float) $row->eod_put_dist_pct : null,
                    'eod_call_dist_pct' => $row->eod_call_dist_pct !== null ? (float) $row->eod_call_dist_pct : null,
                    'intraday_put_wall' => $row->intraday_put_wall !== null ? (float) $row->intraday_put_wall : null,
                    'intraday_call_wall' => $row->intraday_call_wall !== null ? (float) $row->intraday_call_wall : null,
                    'intraday_put_dist_pct' => $row->intraday_put_dist_pct !== null ? (float) $row->intraday_put_dist_pct : null,
                    'intraday_call_dist_pct' => $row->intraday_call_dist_pct !== null ? (float) $row->intraday_call_dist_pct : null,
                ])->values()->all(),
            ],
        ];
    }

    private function invokeController(string $controllerClass, string $method, array $params): array
    {
        try {
            $request = Request::create('/', 'GET', $params);
            $response = app($controllerClass)->{$method}($request);

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizeResponse(mixed $response): array
    {
        if ($response instanceof Response) {
            $raw = $response->getContent();
            $data = json_decode($raw, true);

            return [
                'ok' => $response->isSuccessful(),
                'status' => $response->getStatusCode(),
                'data' => $data,
            ];
        }

        if (is_array($response)) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => $response,
            ];
        }

        return [
            'ok' => false,
            'status' => 500,
            'error' => 'Unexpected response type.',
        ];
    }
}
