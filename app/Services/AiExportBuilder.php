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

            $item['summary'] = $this->buildSummary($item, $timeframe);

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
                'summary_version' => 1,
            ],
            'items' => $items,
        ];
    }

    private function buildSummary(array $item, string $timeframe): array
    {
        $wallData = $this->payloadData($item['wall_snapshots'] ?? null);
        $gexData = $this->payloadData($item['gex_levels'] ?? null);
        $qscoreData = $this->payloadData($item['qscore'] ?? null);
        $dexData = $this->payloadData($item['dealer_positioning'] ?? null);
        $pressureData = $this->payloadData($item['expiry_pressure'] ?? null);
        $skewData = $this->payloadData($item['iv_skew'] ?? null);
        $termData = $this->payloadData($item['term_structure'] ?? null);
        $vrpData = $this->payloadData($item['vrp'] ?? null);
        $seasonalityData = $this->payloadData($item['seasonality'] ?? null);
        $uaData = $this->payloadData($item['unusual_activity'] ?? null);

        return [
            'symbol' => $item['symbol'],
            'requested_gex_timeframe' => $timeframe,
            'data_dates' => array_filter([
                'wall_snapshots' => data_get($this->pickWallSnapshot($wallData, $timeframe), 'trade_date'),
                'gex_levels' => data_get($gexData, 'data_date'),
                'qscore' => data_get($qscoreData, 'date'),
                'dealer_positioning' => data_get($dexData, 'data_date'),
                'expiry_pressure' => data_get($pressureData, 'data_date'),
                'iv_skew' => data_get($skewData, 'date'),
                'term_structure' => data_get($termData, 'date'),
                'vrp' => data_get($vrpData, 'date'),
                'seasonality' => data_get($seasonalityData, 'variant.date'),
                'unusual_activity' => data_get($uaData, 'data_date'),
            ], fn ($value) => $value !== null && $value !== ''),
            'wall' => $this->summarizeWall($wallData, $timeframe),
            'qscore' => $this->summarizeQscore($qscoreData),
            'gex' => $this->summarizeGex($gexData),
            'dealer_positioning' => $this->summarizeDex($dexData),
            'expiry_pressure' => $this->summarizePressure($pressureData),
            'iv_skew' => $this->summarizeSkew($skewData),
            'term_structure' => $this->summarizeTerm($termData),
            'vrp' => $this->summarizeVrp($vrpData),
            'seasonality' => $this->summarizeSeasonality($seasonalityData),
            'unusual_activity' => $this->summarizeUa($uaData),
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

    private function payloadData(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    private function pickWallSnapshot(?array $wallData, string $timeframe): ?array
    {
        $items = collect(data_get($wallData, 'items', []));
        if ($items->isEmpty()) {
            return null;
        }

        return $items->firstWhere('timeframe', $timeframe)
            ?: $items->firstWhere('timeframe', '30d')
            ?: $items->first();
    }

    private function summarizeWall(?array $wallData, string $timeframe): ?array
    {
        $row = $this->pickWallSnapshot($wallData, $timeframe);
        if (!$row) {
            return null;
        }

        $spot = $this->toFloat($row['spot'] ?? null);
        $putWall = $this->toFloat($row['eod_put_wall'] ?? null);
        $callWall = $this->toFloat($row['eod_call_wall'] ?? null);

        return [
            'timeframe' => $row['timeframe'] ?? $timeframe,
            'trade_date' => $row['trade_date'] ?? null,
            'spot' => $spot,
            'put_wall' => $putWall,
            'call_wall' => $callWall,
            'put_dist_pct' => $this->toFloat($row['eod_put_dist_pct'] ?? null),
            'call_dist_pct' => $this->toFloat($row['eod_call_dist_pct'] ?? null),
            'nearest_side' => $this->nearestWallSide(
                $this->toFloat($row['eod_put_dist_pct'] ?? null),
                $this->toFloat($row['eod_call_dist_pct'] ?? null)
            ),
        ];
    }

    private function summarizeQscore(?array $qscoreData): ?array
    {
        $scores = data_get($qscoreData, 'scores');
        if (!is_array($scores)) {
            return null;
        }

        $option = $this->toFloat(data_get($scores, 'option.score'));
        $vol = $this->toFloat(data_get($scores, 'vol.score'));
        $momo = $this->toFloat(data_get($scores, 'momo.score'));
        $season = $this->toFloat(data_get($scores, 'season.score'));

        $overall = null;
        if ($option !== null && $vol !== null && $momo !== null && $season !== null) {
            $overall = round((0.35 * $option) + (0.25 * $vol) + (0.30 * $momo) + (0.10 * $season), 2);
        }

        return [
            'date' => data_get($qscoreData, 'date'),
            'overall' => $overall,
            'overall_label' => $this->qscoreLabel($overall),
            'option' => $option,
            'volatility' => $vol,
            'momentum' => $momo,
            'seasonality' => $season,
        ];
    }

    private function summarizeGex(?array $gexData): ?array
    {
        if (!$gexData) {
            return null;
        }

        return [
            'timeframe' => data_get($gexData, 'timeframe'),
            'data_date' => data_get($gexData, 'data_date'),
            'hvl' => $this->toFloat(data_get($gexData, 'hvl')),
            'call_wall' => $this->toFloat(data_get($gexData, 'call_resistance')),
            'put_wall' => $this->toFloat(data_get($gexData, 'put_support')),
            'call_oi_pct' => $this->toFloat(data_get($gexData, 'call_interest_percentage')),
            'put_oi_pct' => $this->toFloat(data_get($gexData, 'put_interest_percentage')),
            'total_call_oi' => $this->toFloat(data_get($gexData, 'call_open_interest_total')),
            'total_put_oi' => $this->toFloat(data_get($gexData, 'put_open_interest_total')),
            'pcr_volume' => $this->toFloat(data_get($gexData, 'pcr_volume')),
            'total_oi_delta' => $this->toFloat(data_get($gexData, 'total_oi_delta')),
            'total_volume_delta' => $this->toFloat(data_get($gexData, 'total_volume_delta')),
        ];
    }

    private function summarizeDex(?array $dexData): ?array
    {
        if (!$dexData) {
            return null;
        }

        $rows = collect(data_get($dexData, 'by_expiry', []))
            ->map(function ($row) {
                return [
                    'exp_date' => $row['exp_date'] ?? null,
                    'dex_total' => $this->toFloat($row['dex_total'] ?? null),
                ];
            })
            ->filter(fn ($row) => $row['exp_date'] !== null && $row['dex_total'] !== null)
            ->values();

        $topPositive = $rows->sortByDesc('dex_total')->first();
        $topNegative = $rows->sortBy('dex_total')->first();

        return [
            'data_date' => data_get($dexData, 'data_date'),
            'total' => $this->toFloat(data_get($dexData, 'total')),
            'top_positive_expiry' => $topPositive,
            'top_negative_expiry' => $topNegative,
        ];
    }

    private function summarizePressure(?array $pressureData): ?array
    {
        if (!$pressureData) {
            return null;
        }

        $nearest = collect(data_get($pressureData, 'entries', []))->first();

        return [
            'data_date' => data_get($pressureData, 'data_date'),
            'headline_pin' => data_get($pressureData, 'headline_pin'),
            'nearest_expiry' => $nearest ? [
                'exp_date' => $nearest['exp_date'] ?? null,
                'pin_score' => $nearest['pin_score'] ?? null,
                'max_pain' => $this->toFloat($nearest['max_pain'] ?? null),
                'top_cluster_strike' => $this->toFloat(data_get($nearest, 'clusters.0.strike')),
            ] : null,
        ];
    }

    private function summarizeSkew(?array $skewData): ?array
    {
        if (!$skewData) {
            return null;
        }

        $row = collect(data_get($skewData, 'items', []))
            ->first(fn ($item) => isset($item['skew_pc']) || isset($item['curvature']));

        if (!$row) {
            return [
                'date' => data_get($skewData, 'date'),
                'nearest_expiry' => null,
            ];
        }

        return [
            'date' => data_get($skewData, 'date'),
            'nearest_expiry' => [
                'exp' => $row['exp'] ?? null,
                'skew_pc' => $this->toFloat($row['skew_pc'] ?? null),
                'curvature' => $this->toFloat($row['curvature'] ?? null),
                'iv_put_25d' => $this->toFloat($row['iv_put_25d'] ?? null),
                'iv_call_25d' => $this->toFloat($row['iv_call_25d'] ?? null),
            ],
        ];
    }

    private function summarizeTerm(?array $termData): ?array
    {
        if (!$termData) {
            return null;
        }

        $items = collect(data_get($termData, 'items', []))->values();
        $front = $items->first();
        $back = $items->last();

        return [
            'date' => data_get($termData, 'date'),
            'front' => $front ? [
                'exp' => $front['exp'] ?? null,
                'iv' => $this->toFloat($front['iv'] ?? null),
            ] : null,
            'back' => $back ? [
                'exp' => $back['exp'] ?? null,
                'iv' => $this->toFloat($back['iv'] ?? null),
            ] : null,
            'slope' => ($front && $back)
                ? $this->roundOrNull($this->toFloat($back['iv'] ?? null) - $this->toFloat($front['iv'] ?? null), 6)
                : null,
        ];
    }

    private function summarizeVrp(?array $vrpData): ?array
    {
        if (!$vrpData) {
            return null;
        }

        return [
            'date' => data_get($vrpData, 'date'),
            'iv1m' => $this->toFloat(data_get($vrpData, 'iv1m')),
            'rv20' => $this->toFloat(data_get($vrpData, 'rv20')),
            'vrp' => $this->toFloat(data_get($vrpData, 'vrp')),
            'z' => $this->toFloat(data_get($vrpData, 'z')),
        ];
    }

    private function summarizeSeasonality(?array $seasonalityData): ?array
    {
        $variant = data_get($seasonalityData, 'variant');
        if (!is_array($variant)) {
            return null;
        }

        return [
            'date' => $variant['date'] ?? null,
            'cum5' => $this->toFloat($variant['cum5'] ?? null),
            'z' => $this->toFloat($variant['z'] ?? null),
            'd1' => $this->toFloat($variant['d1'] ?? null),
            'd5' => $this->toFloat($variant['d5'] ?? null),
            'note' => data_get($seasonalityData, 'note'),
        ];
    }

    private function summarizeUa(?array $uaData): ?array
    {
        if (!$uaData) {
            return null;
        }

        $items = collect(data_get($uaData, 'items', []))->values();
        $top = $items->first();

        return [
            'data_date' => data_get($uaData, 'data_date'),
            'count' => $items->count(),
            'top' => $top ? [
                'exp_date' => $top['exp_date'] ?? null,
                'strike' => $this->toFloat($top['strike'] ?? null),
                'z_score' => $this->toFloat($top['z_score'] ?? null),
                'vol_oi' => $this->toFloat($top['vol_oi'] ?? null),
                'premium_usd' => $this->toFloat(data_get($top, 'meta.premium_usd')),
                'dominant_side' => $this->uaDominantSide($top),
            ] : null,
        ];
    }

    private function qscoreLabel(?float $overall): ?string
    {
        if ($overall === null) {
            return null;
        }

        return match (true) {
            $overall >= 3.2 => 'bullish',
            $overall >= 2.4 => 'constructive',
            $overall >= 1.6 => 'mixed',
            $overall >= 0.8 => 'cautious',
            default => 'defensive',
        };
    }

    private function nearestWallSide(?float $putPct, ?float $callPct): ?string
    {
        if ($putPct === null && $callPct === null) {
            return null;
        }

        if ($putPct === null) {
            return 'call';
        }

        if ($callPct === null) {
            return 'put';
        }

        return $putPct <= $callPct ? 'put' : 'call';
    }

    private function uaDominantSide(array $row): ?string
    {
        $callVol = $this->toFloat(data_get($row, 'meta.call_vol'));
        $putVol = $this->toFloat(data_get($row, 'meta.put_vol'));

        if ($callVol === null && $putVol === null) {
            return null;
        }

        if (($callVol ?? 0.0) === ($putVol ?? 0.0)) {
            return 'balanced';
        }

        return ($callVol ?? 0.0) > ($putVol ?? 0.0) ? 'call' : 'put';
    }

    private function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function roundOrNull(?float $value, int $precision = 2): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
