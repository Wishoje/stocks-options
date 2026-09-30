<?php

namespace App\Services;

use App\Models\UnderlyingQuote;
use App\Models\WallObservation;
use App\Support\EodSnapshotSelector;
use App\Support\MarketSession;
use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallSnapshotContract;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntradayWallTracker
{
    public function capture(string $symbol, CarbonImmutable $now): array
    {
        if (! in_array($symbol, config('wall_tracking.symbols'), true)) {
            throw new DomainException('Unsupported tracking symbol.');
        }
        $session = MarketSession::describe($now);
        if (! $session['is_rth']) {
            return ['status' => 'outside_session'];
        }
        $quote = UnderlyingQuote::where('symbol', $symbol)->first();
        $at = $quote?->asof ? CarbonImmutable::instance($quote->asof) : null;
        if (! $at || ! $quote->source || str_contains($quote->source, ':ingested-at') || ! $quote->last_price
            || $at->greaterThan($now) || $now->getTimestamp() - $at->getTimestamp() > config('wall_tracking.quote_max_age_seconds')
            || ! MarketSession::describe($at)['is_rth'] || MarketSession::describe($at)['session_date'] !== $session['session_date']) {
            return ['status' => 'waiting_for_current_quote'];
        }

        return Cache::lock('walls:capture:'.$symbol, 120)->get(function () use ($symbol, $now, $at, $quote, $session) {
            $sourceDate = MarketSession::tradingDateOnOrBefore($now->setTimezone('America/New_York')->startOfDay()->subDay());
            $expiries = DB::table('option_expirations')->where('symbol', $symbol)
                ->whereBetween('expiration_date', [$session['session_date'], $now->setTimezone('America/New_York')->addDays(14)->toDateString()])
                ->orderBy('expiration_date')->pluck('expiration_date', 'id')->all();
            if (! $expiries) {
                return ['status' => 'waiting_for_chain'];
            }
            $rows = app(EodSnapshotSelector::class)->selectedRows(array_keys($expiries), ['option_chain_data.*'], $sourceDate);
            // No stale expiry can silently join the previous-session basis.
            if ($rows->isEmpty() || $rows->contains(fn ($r) => (string) $r->data_date !== $sourceDate)
                || $rows->pluck('expiration_id')->unique()->count() !== count($expiries)) {
                return ['status' => 'waiting_for_previous_session_chain'];
            }
            $contracts = $rows->map(fn ($r) => ['expiry' => (string) $expiries[$r->expiration_id], 'type' => $r->option_type,
                'strike' => WallSnapshotContract::number($r->strike), 'oi' => WallSnapshotContract::number($r->open_interest),
                'iv' => WallSnapshotContract::number($r->iv), 'data_date' => (string) $r->data_date,
                'data_timestamp' => $r->data_timestamp ?? null])->all();
            $model = new IntradayWallModel;
            $basis = $model->basis($symbol, $session['session_date'], $sourceDate, array_values($expiries), $contracts);
            $observation = $model->observe($basis, (float) $quote->last_price, $at, $quote->source);
            if ($observation['audit']['oi_input_coverage_pct'] < config('wall_tracking.minimum_oi_coverage_pct')
                || 100 * $observation['audit']['excluded_rows'] / max(1, $observation['audit']['contract_rows']) > config('wall_tracking.maximum_excluded_row_pct')) {
                return ['status' => 'model_inputs_below_capture_threshold'];
            }
            $observation['captured_at'] = $now->utc()->toIso8601String();
            $record = $this->record($basis, $observation);

            return ['status' => $record->wasRecentlyCreated ? 'recorded' : 'already_recorded', 'id' => $record->id];
        }) ?: ['status' => 'capture_in_progress'];
    }

    private function record(array $basis, array $observation): WallObservation
    {
        if (($observation['schema_version'] ?? null) !== IntradayWallModel::SCHEMA
            || ($observation['model_version'] ?? null) !== IntradayWallModel::MODEL
            || $observation['basis_key'] !== $basis['basis_key'] || $observation['scope_key'] !== $basis['scope_key']) {
            throw new DomainException('Observation does not match its versioned inputs.');
        }
        // A source quote is sampled once per five-minute bucket and input basis.
        // Changed input bases append a new record, including corrections at the same time.
        $hash = WallSnapshotContract::hash(['intraday_capture', $basis['basis_key'],
            $observation['provenance']['quote_source'], intdiv(strtotime($observation['observed_at']), 300)]);
        if ($existing = WallObservation::where('content_hash', $hash)->first()) {
            $original = json_decode($existing->payload_json, true, 512, JSON_THROW_ON_ERROR);
            if ($original['observed_at'] === $observation['observed_at'] && $original['spot'] !== $observation['spot']) {
                $observation['revision_of'] = $existing->id;
                $hash = WallSnapshotContract::hash(['quote_revision', $hash, $observation['spot']]);
            }
        }

        return DB::transaction(function () use ($basis, $observation, $hash) {
            $this->insert($basis, $observation, 'intraday_basis', $basis['basis_key'], $basis);

            return $this->insert($basis, $observation, 'intraday_capture', $hash, $observation);
        });
    }

    private function insert(array $basis, array $observation, string $dataset, string $hash, array $payload): WallObservation
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return WallObservation::firstOrCreate(['content_hash' => $hash], [
            'scope_key' => $basis['scope_key'], 'symbol' => $basis['scope']['symbol'], 'dataset' => $dataset,
            'schema_version' => IntradayWallModel::SCHEMA, 'model_version' => IntradayWallModel::MODEL,
            'observation_kind' => $dataset === 'intraday_basis' ? 'model_inputs' : 'model_observation',
            'analysis_session' => $basis['scope']['session'], 'source_date' => $basis['source_date'],
            'observed_at' => $dataset === 'intraday_basis' ? null : CarbonImmutable::parse($observation['observed_at'])->utc(),
            'captured_at' => CarbonImmutable::parse($observation['captured_at'])->utc(), 'recorded_at' => now()->utc(),
            'quality_state' => 'modeled', 'historical_outcome_eligible' => false,
            'payload_bytes' => strlen($json), 'payload_json' => $json,
        ]);
    }

    public function history(string $symbol, ?string $session = null): array
    {
        $base = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_capture')
            ->where('schema_version', IntradayWallModel::SCHEMA)->where('observation_kind', 'model_observation');
        $dates = (clone $base)->select('analysis_session')->distinct()->orderByDesc('analysis_session')->limit(10)->pluck('analysis_session')->all();
        $session ??= $dates[0] ?? null;
        $records = $session ? (clone $base)->where('analysis_session', $session)->orderByDesc('observed_at')->orderByDesc('id')->limit(500)->get() : collect();
        $observations = $records->reverse()->map(function ($row) {
            $payload = json_decode($row->payload_json, true, 512, JSON_THROW_ON_ERROR);
            // Full strike evidence stays in append-only storage. The timeline
            // and its AI-ready JSON need the ranked walls and aggregate only.
            unset($payload['strike_data']);

            return $payload;
        })->values()->all();
        $result = $this->response($symbol, $session, $observations);
        $result['sessions'] = $dates;
        $result['local_demo_available'] = app()->environment('local');
        $result['truncated'] = $records->count() === 500;

        return $result;
    }

    public function response(string $symbol, ?string $session, array $observations): array
    {
        return ['schema_version' => IntradayWallModel::SCHEMA, 'symbol' => $symbol, 'session' => $session,
            'dataset' => 'intraday_capture', 'timeframe' => '14d', 'units' => 'USD_per_1pct_move',
            'model_version' => IntradayWallModel::MODEL, 'generated_at' => now()->toIso8601String(),
            'model_description' => 'Modeled walls using changing price and time, with prior-session OI and IV held fixed. Zero interest and dividends; 100-share contracts; European gamma approximation.',
            'migration_basis' => 'last_strike_minus_first_comparable_strike',
            'segments' => (new IntradayWallModel)->timeline($observations),
            'interpretation' => 'A stable wall is not confirmation of support or resistance. A moving wall is not a break signal.',
            'historical_outcome_eligible' => false];
    }
}
