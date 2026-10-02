<?php

namespace App\Services;

use App\Models\WallObservation;
use App\Support\PolygonClient;
use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallInteractionDetector;
use App\Support\WallIntelligence\WallInteractionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class WallInteractionService
{
    public function cutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now('UTC'))->subSeconds(
            max(900, (int) config('wall_tracking.quote_delay_seconds', 0)) + 30
        );
    }

    /** No HTTP, writes or queue fanout on a user request. */
    public function read(string $symbol, ?string $session, string $timeframe, array $observations): array
    {
        $bundle = $session ? WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_price_bars')
            ->where('analysis_session', $session)->where('schema_version', 'wall-price-bars.v1')
            ->orderByDesc('id')->first(['content_hash', 'payload_json', 'recorded_at']) : null;
        $payload = $bundle ? json_decode($bundle->payload_json, true, 512, JSON_THROW_ON_ERROR) : [];
        $result = (new WallInteractionDetector)->analyze($symbol, $session, $timeframe, $observations, $payload['bars'] ?? [], $this->cutoff());
        $result['price_evidence_hash'] = $bundle?->content_hash;
        $result['price_recorded_at'] = $bundle?->recorded_at;
        $result['price_delay_seconds'] = 900;

        return $result;
    }

    public function collect(string $symbol, string $session, CarbonImmutable $now): array
    {
        if (Cache::has('walls:bars:provider-backoff')) {
            return ['status' => 'provider_backoff'];
        }
        $lock = Cache::lock('walls:bars:lock:'.$symbol, 120);
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }
        try {
            if (Cache::has('walls:bars:checked:'.$symbol.':'.$session)) {
                return ['status' => 'recently_checked'];
            }
            $base = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_capture')
                ->where('analysis_session', $session)->where('schema_version', IntradayWallModel::SCHEMA)
                ->where('observation_kind', 'model_observation');
            $scopes = (clone $base)->select('payload_json->scope->timeframe as timeframe')->distinct()->pluck('timeframe')->all();
            if (! $scopes) {
                return ['status' => 'waiting_for_walls'];
            }
            $response = app(PolygonClient::class)->wallPriceBars($symbol, $session);
            Cache::put('walls:bars:checked:'.$symbol.':'.$session, true, $now->addMinutes(5));
            if ($response['status'] !== 'ready') {
                if (in_array($response['status'], ['access_not_available', 'rate_limited'], true)) {
                    Cache::put('walls:bars:provider-backoff', true, $now->addMinutes(15));
                }

                return ['status' => $response['status']];
            }
            $cutoff = $this->cutoff($now);
            // The detector also validates OHLC, session hours and interval completion.
            $bars = (new WallInteractionDetector)->analyze($symbol, $session, '14d', [], $response['bars'], $cutoff)['bars'];
            $bars = array_map(function ($bar) {
                unset($bar['id']);

                return $bar;
            }, $bars);
            if (! $bars) {
                return ['status' => 'waiting_for_completed_bars'];
            }
            $bundle = ['schema_version' => 'wall-price-bars.v1', 'symbol' => $symbol, 'session' => $session,
                'source' => 'massive_5m_unadjusted', 'collected_at' => $now->toIso8601String(),
                'provider_request_id' => $response['request_id'] ?? null, 'bars' => $bars];
            $hash = $this->record($symbol, $session, 'intraday_price_bars', $bundle, $now);
            $events = 0;
            foreach ($scopes as $scope) {
                if (! in_array($scope, IntradayWallTracker::TIMEFRAMES, true)) {
                    continue;
                }
                $rows = (clone $base)->where('payload_json->scope->timeframe', $scope)
                    ->orderByDesc('observed_at')->orderByDesc('id')->limit(500)->get(['content_hash', 'payload_json']);
                $observations = $rows->reverse()->map(function ($row) {
                    $payload = json_decode($row->payload_json, true, 512, JSON_THROW_ON_ERROR);
                    unset($payload['strike_data']);
                    $payload['evidence_hash'] = $row->content_hash;

                    return $payload;
                })->values()->all();
                $analysis = (new WallInteractionDetector)->analyze($symbol, $session, $scope, $observations, $bars, $cutoff);
                $analysis['price_evidence_hash'] = $hash;
                $this->record($symbol, $session, 'wall_interactions', $analysis, $now);
                $events += count($analysis['events']);
            }

            return ['status' => 'recorded', 'bars' => count($bars), 'scopes' => count($scopes), 'events' => $events];
        } finally {
            $lock->release();
        }
    }

    private function record(string $symbol, string $session, string $dataset, array $payload, CarbonImmutable $now): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json);
        $scope = hash('sha256', json_encode([$symbol, $session, $dataset, $payload['timeframe'] ?? null, WallInteractionRules::VERSION]));
        WallObservation::query()->insertOrIgnore([
            'content_hash' => $hash, 'scope_key' => $scope, 'symbol' => $symbol, 'dataset' => $dataset,
            'schema_version' => $payload['schema_version'], 'model_version' => WallInteractionRules::VERSION,
            'observation_kind' => $dataset === 'intraday_price_bars' ? 'completed_price_bars' : 'interaction_assessment',
            'analysis_session' => $session, 'source_date' => $session,
            'observed_at' => isset($payload['as_of']) ? CarbonImmutable::parse($payload['as_of'])->utc()->toDateTimeString() : null,
            'captured_at' => $now->toDateTimeString(), 'recorded_at' => $now->toDateTimeString(),
            'quality_state' => 'descriptive', 'historical_outcome_eligible' => false,
            'payload_bytes' => strlen($json), 'payload_json' => $json,
        ]);

        return $hash;
    }
}
