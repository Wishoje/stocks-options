<?php

namespace App\Services;

use App\Models\UnderlyingQuote;
use App\Models\WallObservation;
use App\Support\EodSnapshotSelector;
use App\Support\GexExpirationUniverse;
use App\Support\MarketSession;
use App\Support\Symbols;
use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallSnapshotContract;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IntradayWallTracker
{
    public const TIMEFRAMES = ['0d', '1d', '7d', '14d', '30d', '90d'];

    public function requestedTimeframes(string $symbol): array
    {
        return array_values(array_filter(self::TIMEFRAMES, fn ($tf) => $tf === '14d' || Cache::has('walls:scope-demand:'.$symbol.':'.$tf)));
    }

    public function symbols(CarbonImmutable $now): array
    {
        $date = $now->setTimezone('America/New_York')->toDateString();

        return UnderlyingQuote::query()->where('asof', '>=', $now->setTimezone('America/New_York')->startOfDay()->utc())
            ->where('asof', '<=', $now)->where('last_price', '>', 0)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('option_expirations')
                ->whereColumn('option_expirations.symbol', 'underlying_quotes.symbol')
                ->whereBetween('expiration_date', [$date, $now->setTimezone('America/New_York')->addWeekdays(64)->toDateString()]))
            ->orderBy('symbol')->distinct()->pluck('symbol')->filter(fn ($symbol) => Symbols::isValid($symbol))->values()->all();
    }

    public function capture(string $symbol, CarbonImmutable $now, string $timeframe = '14d'): array
    {
        if (! Symbols::isValid($symbol) || $symbol !== Symbols::canon($symbol)) {
            throw new DomainException('Invalid tracking symbol.');
        }
        if (! in_array($timeframe, self::TIMEFRAMES, true)) {
            throw new DomainException('Invalid expiry scope.');
        }
        $result = $this->captureCurrent($symbol, $now, $timeframe);
        Cache::put('walls:status:'.$symbol.':'.$timeframe, ['status' => $result['status'], 'checked_at' => $now->toIso8601String()], now()->addDay());

        return $result;
    }

    private function captureCurrent(string $symbol, CarbonImmutable $now, string $timeframe): array
    {
        $session = MarketSession::describe($now);
        $delay = (int) config('wall_tracking.quote_delay_seconds', 0);
        $maxAge = (int) config('wall_tracking.quote_max_age_seconds');
        $delayedClose = $delay > 0 && $session['closes_at']
            && $now->greaterThanOrEqualTo(CarbonImmutable::parse($session['closes_at']))
            && $now->lessThan(CarbonImmutable::parse($session['closes_at'])->addSeconds($delay + $maxAge));
        if (! $session['is_rth'] && ! $delayedClose) {
            return ['status' => 'outside_session'];
        }
        $quote = UnderlyingQuote::where('symbol', $symbol)->first();
        $at = $quote?->asof ? CarbonImmutable::instance($quote->asof) : null;
        $received = $quote?->updated_at ? CarbonImmutable::instance($quote->updated_at) : null;
        if (! $at || ! $quote->source || str_contains($quote->source, ':ingested-at') || ! $quote->last_price
            || $at->greaterThan($now) || $now->getTimestamp() - $at->getTimestamp() > $delay + $maxAge
            || ($delay > 0 && (! $received || $received->greaterThan($now) || $received->lessThan($at)
                || $now->getTimestamp() - $received->getTimestamp() > $maxAge))
            || ! MarketSession::describe($at)['is_rth'] || MarketSession::describe($at)['session_date'] !== $session['session_date']) {
            return ['status' => 'waiting_for_current_quote'];
        }

        return Cache::lock('walls:capture:'.$symbol.':'.$timeframe, 120)->get(function () use ($symbol, $now, $at, $quote, $session, $timeframe) {
            $sourceDate = MarketSession::tradingDateOnOrBefore($now->setTimezone('America/New_York')->startOfDay()->subDay());
            $universe = app(GexExpirationUniverse::class)->resolve($symbol, $timeframe, [$timeframe], $now);
            $expiries = DB::table('option_expirations')->whereIn('id', $universe['expiration_ids'])
                ->orderBy('expiration_date')->pluck('expiration_date', 'id')->all();
            if (! $expiries) {
                return ['status' => 'no_expirations'];
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
            $basis = $model->basis($symbol, $session['session_date'], $sourceDate, array_values($expiries), $contracts, $timeframe);
            try {
                $observation = $model->observe($basis, (float) $quote->last_price, $at, $quote->source);
            } catch (DomainException) {
                return ['status' => 'model_inputs_below_capture_threshold'];
            }
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

    public function history(string $symbol, ?string $session = null, string $timeframe = '14d'): array
    {
        if (! in_array($timeframe, self::TIMEFRAMES, true)) {
            throw new DomainException('Invalid expiry scope.');
        }
        Cache::put('walls:scope-demand:'.$symbol.':'.$timeframe, true, now()->addDay());
        $base = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_capture')
            ->where('payload_json->scope->timeframe', $timeframe)
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
        $result = $this->response($symbol, $session, $observations, $timeframe);
        $result['sessions'] = $dates;
        $result['local_demo_available'] = app()->environment('local');
        $result['truncated'] = $records->count() === 500;
        $result['availability'] = $this->availability($symbol, $session, $observations !== [], $timeframe);
        $result['available_scopes'] = $observations === [] ? $this->recordedScopes($symbol) : [];

        return $result;
    }

    private function recordedScopes(string $symbol): array
    {
        $base = WallObservation::where('symbol', $symbol)->where('dataset', 'intraday_capture')
            ->where('schema_version', IntradayWallModel::SCHEMA)->where('observation_kind', 'model_observation');
        // Bound the lookup to recent recorded sessions. Only aggregate metadata
        // is loaded; the stored contracts and timeline payloads stay in the DB.
        $dates = (clone $base)->select('analysis_session')->distinct()->orderByDesc('analysis_session')
            ->limit(10)->pluck('analysis_session')->all();
        if (! $dates) {
            return [];
        }
        $scopes = $base->whereIn('analysis_session', $dates)
            ->whereIn('payload_json->scope->timeframe', self::TIMEFRAMES)
            ->select(['payload_json->scope->timeframe as timeframe', 'analysis_session'])
            ->selectRaw('COUNT(*) as readings')->groupBy('payload_json->scope->timeframe', 'analysis_session')
            ->orderByDesc('analysis_session')->get()->unique('timeframe')->keyBy('timeframe');

        return collect(self::TIMEFRAMES)->filter(fn ($scope) => $scopes->has($scope))->map(fn ($scope) => [
            'timeframe' => $scope, 'session' => (string) $scopes[$scope]->analysis_session,
            'readings' => (int) $scopes[$scope]->readings,
        ])->values()->all();
    }

    private function availability(string $symbol, ?string $session, bool $hasReadings, string $timeframe): array
    {
        if ($hasReadings) {
            return ['state' => 'ready', 'message' => null];
        }
        if ($session) {
            return ['state' => 'no_session_readings', 'message' => 'No wall readings were recorded for this session. Choose another session.'];
        }
        $market = MarketSession::describe();
        if (! $market['is_rth']) {
            return ['state' => 'outside_session', 'message' => 'No wall history has been recorded yet for '.$symbol.'. Tracking builds during market sessions; EOD wall analysis is available now.'];
        }
        $status = Cache::get('walls:status:'.$symbol.':'.$timeframe);
        $code = ($status && CarbonImmutable::parse($status['checked_at'])->greaterThan(now()->subMinutes(10))) ? $status['status'] : null;
        if ($code === 'no_expirations') {
            return ['state' => 'no_expirations', 'message' => 'There are no option expirations in this scope for '.$symbol.'. Choose a wider expiry scope.'];
        }
        if (in_array($code, ['model_inputs_below_capture_threshold', 'waiting_for_previous_session_chain', 'waiting_for_chain'], true)) {
            return ['state' => 'model_not_ready', 'message' => 'Wall tracking is not ready for '.$symbol.'. You can explore its EOD wall analysis while the model is prepared.'];
        }
        if (! UnderlyingQuote::where('symbol', $symbol)->exists() || $code === 'waiting_for_current_quote') {
            return ['state' => 'waiting_for_quotes', 'message' => 'Waiting for the next market update for '.$symbol.'. Wall history starts with the first recorded reading.'];
        }

        return ['state' => 'awaiting_first_reading', 'message' => 'No wall readings have been recorded yet for '.$symbol.'. Eligible symbols are checked every five minutes during market sessions.'];
    }

    public function response(string $symbol, ?string $session, array $observations, string $timeframe = '14d'): array
    {
        $market = MarketSession::describe();

        return ['schema_version' => IntradayWallModel::SCHEMA, 'symbol' => $symbol, 'session' => $session,
            'dataset' => 'intraday_capture', 'timeframe' => $timeframe, 'units' => 'USD_per_1pct_move',
            'model_version' => IntradayWallModel::MODEL, 'generated_at' => now()->toIso8601String(),
            'quote_delay_seconds' => (int) config('wall_tracking.quote_delay_seconds', 0),
            'market_session_date' => $market['session_date'],
            'refresh_until' => $market['refresh_allowed']
                ? CarbonImmutable::parse($market['closes_at'])->addMinutes(15)->toIso8601String() : null,
            'model_description' => 'Modeled walls using changing price and time, with prior-session OI and IV held fixed. Zero interest and dividends; 100-share contracts; European gamma approximation.',
            'migration_basis' => 'last_strike_minus_first_comparable_strike',
            'segments' => (new IntradayWallModel)->timeline($observations),
            'interpretation' => 'A stable wall is not confirmation of support or resistance. A moving wall is not a break signal.',
            'historical_outcome_eligible' => false];
    }
}
