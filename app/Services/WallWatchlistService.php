<?php

namespace App\Services;

use App\Models\Watchlist;
use App\Support\MarketSession;
use App\Support\Symbols;
use App\Support\WallIntelligence\IntradayWallDemo;
use App\Support\WallIntelligence\IntradayWallModel;
use App\Support\WallIntelligence\WallInteractionDetector;
use App\Support\WallIntelligence\WallInteractionRules;
use App\Support\WallIntelligence\WallSetupNextCondition;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WallWatchlistService
{
    public function read(int $userId): array
    {
        $now = CarbonImmutable::now('UTC');
        $market = MarketSession::describe($now);
        $symbols = Watchlist::where('user_id', $userId)->orderBy('symbol')->distinct()->pluck('symbol')
            ->filter(fn ($symbol) => Symbols::isValid($symbol) && $symbol === Symbols::canon($symbol))->values()->all();
        $result = ['schema_version' => 'wall-watchlist.v1', 'session' => $market['session_date'],
            'timeframe' => IntradayWallTracker::LIVE_TIMEFRAME, 'generated_at' => $now->toIso8601String(),
            'quote_delay_seconds' => max(900, (int) config('wall_tracking.quote_delay_seconds')),
            'watchlist_count' => count($symbols), 'assessed_count' => 0,
            'state' => $market['is_rth'] ? 'ready' : 'outside_session', 'items' => [], 'lifecycle_items' => [], 'lifecycle_truncated' => false,
            'refresh_until' => $market['is_rth'] ? $market['closes_at'] : null,
            'next_refresh_at' => CarbonImmutable::parse($market['next_open_at'])->addMinutes(15)->toIso8601String(),
            'local_demo_available' => app()->environment('local')];
        if (! $symbols || ! $market['is_trading_day'] || $now->lessThan(CarbonImmutable::parse($market['opens_at']))) {
            return $result;
        }
        $cutoff = app(WallInteractionService::class)->cutoff($now);
        $key = 'wall-watchlist:projection:v2:'.hash('sha256', json_encode([$userId, $symbols, $result['session']]));
        [$models, $assessments] = Cache::remember($key, 30, function () use ($userId, $symbols, $result, $now, $cutoff) {
            $models = $this->latest($userId, $symbols, $result['session'], 'intraday_capture', IntradayWallModel::SCHEMA,
                IntradayWallModel::MODEL, 'model_observation', 'scope->timeframe', $now)
                ->select(['w.symbol', 'w.observed_at', 'w.payload_json->scope as scope', 'w.payload_json->scope_key as scope_key',
                    'w.payload_json->basis_key as basis_key', 'w.payload_json->provenance->quote_source as quote_source',
                    'w.payload_json->revision_of as revision_of', 'w.payload_json->walls as walls'])->get()->keyBy('symbol');
            $assessments = $this->latest($userId, $symbols, $result['session'], 'wall_interactions', WallInteractionDetector::SCHEMA,
                WallInteractionRules::VERSION, 'interaction_assessment', 'timeframe', $cutoff)
                ->select(['w.symbol', 'w.observed_at', 'w.payload_json->current as current', 'w.payload_json->state as state',
                    'w.payload_json->setups->current as setups', 'w.payload_json->setups->rule_version as setup_rules',
                    'w.payload_json->setup_lifecycle as lifecycle'])->get()->keyBy('symbol');

            return [$models, $assessments];
        });
        $rules = WallInteractionRules::profile();
        $detector = new WallInteractionDetector;
        foreach ($symbols as $symbol) {
            $model = $models->get($symbol);
            $assessment = $assessments->get($symbol);
            $lifecycle = $this->array($assessment?->lifecycle);
            if (($lifecycle['schema_version'] ?? null) === \App\Support\WallIntelligence\WallSetupLifecycle::VERSION
                && ($lifecycle['rule_version'] ?? null) === WallSetupRules::VERSION
                && ($lifecycle['review_rule_version'] ?? null) === \App\Support\WallIntelligence\WallSetupOutcomeEvaluator::VERSION
                && ($lifecycle['symbol'] ?? null) === $symbol && ($lifecycle['session'] ?? null) === $result['session']
                && ($lifecycle['timeframe'] ?? null) === IntradayWallTracker::LIVE_TIMEFRAME) {
                $result['lifecycle_truncated'] = $result['lifecycle_truncated'] || ($lifecycle['truncated'] ?? false);
                foreach (array_slice($lifecycle['items'] ?? [], 0, 100) as $row) {
                    if (($row['symbol'] ?? null) === $symbol && ($row['session'] ?? null) === $result['session']
                        && preg_match('/^[a-f0-9]{64}$/', $row['id'] ?? '')
                        && (($row['alert_id'] ?? null) === null || $row['alert_id'] === hash('sha256', json_encode([$symbol, $result['session'], 'setup', $row['id']])))
                        && in_array($row['side'] ?? null, ['put', 'call'], true)
                        && in_array($row['status_group'] ?? null, ['watching', 'conditions_met', 'in_progress', 'finished', 'needs_update'], true)
                        && $this->recent($row['evaluated_through'] ?? null, $cutoff, 86400)
                        && $this->recent($row['observed_at'] ?? null, $cutoff, 86400)
                        && is_numeric($row['strike'] ?? null) && is_finite((float) $row['strike']) && $row['strike'] > 0) {
                        $result['lifecycle_items'][] = array_intersect_key($row, array_flip(['id', 'symbol', 'side', 'strike',
                            'alert_id', 'assessment_id', 'session', 'timeframe', 'status_group', 'outcome', 'qualified',
                            'observed_at', 'qualified_at', 'entry_expires_at', 'evaluated_through', 'outcome_at', 'close', 'reason', 'setup']));
                    }
                }
            }
            if (! $market['is_rth']) {
                continue;
            }
            if (! $model || ! $assessment || $assessment->state !== 'ready'
                || ! $this->recent($model->observed_at, $now, $result['quote_delay_seconds'] + (int) config('wall_tracking.quote_max_age_seconds'))
                || ! $this->recent($assessment->observed_at, $cutoff, $rules['maximum_bar_age_seconds'])) {
                continue;
            }
            $observation = ['scope' => $this->array($model->scope), 'scope_key' => $model->scope_key,
                'basis_key' => $model->basis_key, 'walls' => $this->array($model->walls),
                'revision_of' => is_numeric($model->revision_of) ? (int) $model->revision_of : null,
                'provenance' => ['quote_source' => $model->quote_source]];
            if (($observation['scope']['symbol'] ?? null) !== $symbol || ($observation['scope']['session'] ?? null) !== $result['session']
                || ($observation['scope']['timeframe'] ?? null) !== IntradayWallTracker::LIVE_TIMEFRAME) {
                continue;
            }
            $current = $this->array($assessment->current);
            $setups = $assessment->setup_rules === WallSetupRules::VERSION ? $this->array($assessment->setups) : [];
            $comparable = false;
            foreach (['put', 'call'] as $side) {
                $reaction = $current[$side] ?? [];
                $identity = $detector->identity($observation, $side);
                if (! $identity || ($reaction['wall_identity'] ?? null) !== $identity
                    || ($reaction['strike'] ?? null) != ($observation['walls'][$side][0]['strike'] ?? null)
                    || ! is_numeric($reaction['close'] ?? null) || $reaction['close'] <= 0
                    || ! $this->recent($reaction['observed_at'] ?? null, $cutoff, $rules['maximum_bar_age_seconds'])) {
                    continue;
                }
                $comparable = true;
                $setup = $setups[$side] ?? [];
                $qualified = $this->qualified($setup, $reaction, $cutoff);
                $status = $reaction['status'] ?? 'unknown';
                if (! $qualified && ! in_array($status, ['approaching', 'testing', 'touch', 'bounce', 'rejection', 'break',
                    'acceptance_above', 'acceptance_below', 'reclaim', 'failed_reclaim', 'confirmed_breakout', 'confirmed_breakdown'], true)) {
                    continue;
                }
                $result['items'][] = ['symbol' => $symbol, 'side' => $side, 'strike' => $reaction['strike'],
                    'observed_at' => $reaction['observed_at'], 'close' => $reaction['close'], 'reaction' => $status,
                    'status' => $qualified ? 'conditions_met' : $status,
                    'assessment_id' => WallInteractionDetector::assessmentId($reaction),
                    'next_condition' => $this->nextCondition($setup, $reaction, $qualified, $cutoff),
                    'alert_keys' => $this->alertKeys($symbol, $result['session'], $reaction, $qualified ? $setup : []),
                    'setup' => $qualified ? array_intersect_key($setup, array_flip(['pattern', 'direction', 'entry_zone',
                        'invalidation', 'target', 'expires_at', 'evidence_checks', 'evidence_grade'])) : null];
                if (! $lifecycle) {
                    $last = $result['items'][array_key_last($result['items'])];
                    $result['lifecycle_items'][] = [...$last, 'id' => $last['assessment_id'],
                        'alert_id' => $last['alert_keys']['setup'] ?? $last['alert_keys']['testing'] ?? null,
                        'session' => $result['session'], 'timeframe' => IntradayWallTracker::LIVE_TIMEFRAME,
                        'status_group' => $qualified ? 'conditions_met' : 'watching', 'qualified' => $qualified,
                        'qualified_at' => $qualified ? $setup['confirmed_at'] : null,
                        'evaluated_through' => $reaction['observed_at'], 'outcome' => $qualified ? 'in_progress' : 'watching',
                        'reason' => $last['next_condition']['text'] ?? 'Wait for a completed price sequence.'];
                }
            }
            $result['assessed_count'] += (int) $comparable;
        }
        usort($result['items'], fn ($a, $b) => $this->priority($a['status']) <=> $this->priority($b['status'])
            ?: strcmp($b['observed_at'], $a['observed_at']) ?: strcmp($a['symbol'], $b['symbol']) ?: strcmp($a['side'], $b['side']));
        usort($result['lifecycle_items'], fn ($a, $b) => strcmp($b['observed_at'], $a['observed_at']) ?: strcmp($a['id'], $b['id']));
        $result['lifecycle_truncated'] = $result['lifecycle_truncated'] || count($result['lifecycle_items']) > 1000;
        $result['lifecycle_items'] = array_slice($result['lifecycle_items'], 0, 1000);

        return $result;
    }

    /** Two bounded projection queries, independent of the number of symbols. */
    private function latest(int $userId, array $symbols, string $session, string $dataset, string $schema, string $version,
        string $kind, string $scopePath, CarbonImmutable $cutoff): Builder
    {
        $base = DB::table('wall_observations')->whereColumn('wall_observations.symbol', 'saved.symbol')->where('dataset', $dataset)
            ->where('analysis_session', $session)->where('schema_version', $schema)->where('model_version', $version)
            ->where('observation_kind', $kind)
            ->where('observed_at', '<=', $cutoff->toDateTimeString());
        if ($dataset === 'wall_interactions') {
            // Match the indexed scope key written by WallInteractionService.
            // Do not parse every historical price-bar bundle just to find its scope.
            $base->whereIn('scope_key', array_map(fn ($symbol) => hash('sha256', json_encode([
                $symbol, $session, $dataset, IntradayWallTracker::LIVE_TIMEFRAME, WallInteractionRules::VERSION,
            ])), $symbols));
        } else {
            $base->where('payload_json->'.$scopePath, IntradayWallTracker::LIVE_TIMEFRAME);
        }
        // Seek the newest indexed record for each saved symbol. Aggregating
        // every assessment in the session repeatedly parses large JSON bundles.
        $newest = $base->select('wall_observations.id')->orderByDesc('observed_at')->orderByDesc('id')->limit(1);
        $ids = DB::table('watchlists as saved')->where('saved.user_id', $userId)->whereIn('saved.symbol', $symbols)
            ->selectSub($newest, 'latest_id')->distinct();

        return DB::table('wall_observations as w')->whereIn('w.id', $ids);
    }

    private function qualified(array $setup, array $reaction, CarbonImmutable $cutoff): bool
    {
        if (($setup['status'] ?? null) !== 'conditions_met' || ($setup['wall_identity'] ?? null) !== $reaction['wall_identity']
            || ! ($setup['entry_eligible_at_observation'] ?? false) || ! in_array($setup['direction'] ?? null, ['bullish', 'bearish'], true)
            || empty($setup['confirmed_at']) || CarbonImmutable::parse($setup['confirmed_at'])->greaterThan(CarbonImmutable::parse($reaction['observed_at']))
            || ($setup['observed_at'] ?? null) !== $reaction['observed_at']
            || ! isset($setup['expires_at']) || CarbonImmutable::parse($setup['expires_at'])->lessThan($cutoff)) {
            return false;
        }
        $levels = [$setup['entry_zone']['low'] ?? null, $setup['entry_zone']['high'] ?? null,
            $setup['invalidation']['price'] ?? null, $setup['target']['strike'] ?? null, $reaction['close'] ?? null];
        foreach ($levels as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value <= 0) {
                return false;
            }
        }

        return $levels[0] < $levels[1] && ($setup['direction'] === 'bullish'
            ? $levels[4] > $levels[0] && $levels[4] <= $levels[1]
            : $levels[4] >= $levels[0] && $levels[4] < $levels[1]);
    }

    private function nextCondition(array $setup, array $reaction, bool $qualified, CarbonImmutable $cutoff): ?array
    {
        $next = $setup['next_condition'] ?? null;
        if (($setup['wall_identity'] ?? null) !== $reaction['wall_identity']
            || ($setup['observed_at'] ?? null) !== $reaction['observed_at']
            || ($next['version'] ?? null) !== 'wall-next-condition.v1'
            || ($next['rule_version'] ?? null) !== WallSetupRules::VERSION
            || ($next['setup_status'] ?? null) !== ($setup['status'] ?? null)
            || ! is_string($next['text'] ?? null)
            || (($setup['status'] ?? null) === 'conditions_met' && ! $qualified)) {
            return null;
        }
        if (($setup['status'] ?? null) === 'watch' && ! empty($setup['expires_at'])
            && CarbonImmutable::parse($setup['expires_at'])->lessThan($cutoff)) {
            $next = WallSetupNextCondition::describe([...$setup, 'status' => 'expired']);
        }

        return array_intersect_key($next, array_flip(['version', 'rule_version', 'setup_status', 'code', 'text',
            'completed_closes', 'required_closes']));
    }

    /** Identities stay fixed across repeated completed-bar assessments. */
    private function alertKeys(string $symbol, string $session, array $reaction, array $setup): array
    {
        $setupId = $setup['id'] ?? null;
        $episode = $reaction['episode_started_at'] ?? null;
        $identity = $reaction['wall_identity'] ?? null;

        return [
            'setup' => is_string($setupId) && preg_match('/^[a-f0-9]{64}$/', $setupId)
                ? hash('sha256', json_encode([$symbol, $session, 'setup', $setupId])) : null,
            'testing' => ($reaction['status'] ?? null) === 'testing' && $identity && $episode
                ? hash('sha256', json_encode([$symbol, $session, 'testing', $identity, $episode, WallInteractionRules::VERSION])) : null,
        ];
    }

    private function recent(?string $at, CarbonImmutable $cutoff, int $seconds): bool
    {
        if (! $at) {
            return false;
        }
        try {
            $time = CarbonImmutable::parse($at);
        } catch (\Throwable) {
            return false;
        }

        return $time->lessThanOrEqualTo($cutoff) && $cutoff->getTimestamp() - $time->getTimestamp() <= $seconds;
    }

    private function array(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);
    }

    private function priority(string $status): int
    {
        return $status === 'conditions_met' ? 0 : ($status === 'approaching' ? 2 : 1);
    }

    public function demonstration(): array
    {
        abort_unless(app()->environment('local'), 404);
        $items = [];
        foreach (['SPY', 'QQQ', 'TSLA'] as $symbol) {
            $data = app(IntradayWallDemo::class)->build($symbol, IntradayWallTracker::LIVE_TIMEFRAME);
            $interaction = $data['wall_interaction'];
            $events = collect($interaction['events']);
            $setup = $symbol === 'SPY' ? collect($interaction['setups']['readings'])->first(fn ($setup) => $setup['status'] === 'conditions_met'
                && $events->contains(fn ($event) => $event['side'] === $setup['side'] && $event['observed_at'] === $setup['observed_at'])) : null;
            $event = $setup ? collect($interaction['events'])->first(fn ($event) => $event['side'] === $setup['side'] && $event['observed_at'] === $setup['observed_at'])
                : ($events->firstWhere('status', $symbol === 'QQQ' ? 'touch' : 'testing') ?? $events->first());
            if (! $event) {
                continue;
            }
            $assessment = collect($interaction['setups']['readings'])->first(fn ($row) => $row['side'] === $event['side']
                && $row['observed_at'] === $event['observed_at']);
            $items[] = ['symbol' => $symbol, 'side' => $event['side'], 'strike' => $event['strike'],
                'observed_at' => $event['observed_at'], 'close' => $event['close'], 'reaction' => $event['status'],
                'status' => $setup ? 'conditions_met' : $event['status'], 'setup' => $setup,
                'event_id' => $event['id'], 'next_condition' => $assessment['next_condition'] ?? null];
        }

        return ['schema_version' => 'wall-watchlist.v1', 'session' => '2026-09-29', 'timeframe' => IntradayWallTracker::LIVE_TIMEFRAME,
            'generated_at' => now()->toIso8601String(), 'quote_delay_seconds' => 900, 'watchlist_count' => 3,
            'assessed_count' => 3, 'state' => 'demonstration', 'items' => $items,
            'refresh_until' => null, 'next_refresh_at' => null, 'local_demo_available' => true];
    }
}
