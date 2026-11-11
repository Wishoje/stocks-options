<?php

namespace App\Services;

use App\Support\ProductUniverse;
use App\Support\PublicSpyPreview;
use App\Support\Swing\SwingReactionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Shared publications. Customer requests only read; collection owns all state changes. */
class SwingReactionService
{
    public const ACTIVE = ['watching', 'ready', 'entry_touched'];

    public function prepared(): bool
    {
        return Schema::hasTable('swing_reaction_ideas');
    }

    public function seed(array $input, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        if (($input['data_date'] ?? null) !== PublicSpyPreview::completedSession()) {
            throw new \DomainException('Current completed-session structure is required.');
        }
        $rules = app(SwingReactionRules::class);
        $proposals = [];
        foreach (array_keys(SwingReactionRules::HORIZONS) as $horizon) {
            array_push($proposals, ...$rules->propose($input, $horizon, $now));
        }

        return DB::transaction(function () use ($input, $now, $proposals) {
            $open = DB::table('swing_reaction_ideas')->where('symbol', $input['symbol'])
                ->where('rule_version', SwingReactionRules::VERSION)->whereIn('status', self::ACTIVE)
                ->lockForUpdate()->get();
            $keys = array_fill_keys(array_map($this->key(...), $proposals), true);
            foreach ($open as $row) {
                $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
                if ($state['status'] !== 'entry_touched' && $row->source_date < $input['data_date'] && ! isset($keys[$this->key($state)])) {
                    $next = [...$state, 'status' => 'cancelled', 'outcome' => 'structure_changed',
                        'outcome_at' => $now->toIso8601String(), 'reason' => 'The latest daily wall review no longer supports this unentered idea.'];
                    $this->save($row, $state, $next, $now);
                }
            }
            $created = 0;
            foreach ($proposals as $plan) {
                $same = $open->contains(function ($row) use ($plan) {
                    $state = json_decode($row->current_state, true);

                    return ($state['status'] === 'entry_touched' && $state['horizon'] === $plan['horizon'])
                        || $this->key($state) === $this->key($plan);
                });
                if ($same) {
                    continue;
                }
                $id = hash('sha256', json_encode([SwingReactionRules::VERSION, $input['symbol'], $input['data_date'], $this->key($plan)]));
                $plan = [...$plan, 'id' => $id, 'first_recorded_at' => $now->toIso8601String(),
                    'source_version' => $input['source_version'] ?? null, 'volatility_version' => $input['volatility_version'] ?? null];
                $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $created += DB::table('swing_reaction_ideas')->insertOrIgnore([
                    'id' => $id, 'symbol' => $input['symbol'], 'horizon' => $plan['horizon'],
                    'rule_version' => SwingReactionRules::VERSION, 'source_date' => $input['data_date'],
                    'status' => $plan['status'], 'original_plan' => $json, 'current_state' => $json,
                    'created_at' => $now->toDateTimeString(), 'updated_at' => $now->toDateTimeString(),
                ]);
            }

            return $created;
        });
    }

    public function follow(string $symbol, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        if (! DB::table('swing_reaction_ideas')->where('symbol', $symbol)
            ->where('rule_version', SwingReactionRules::VERSION)->whereIn('status', self::ACTIVE)->exists()) {
            return ['pinned' => 0, 'conflicts' => [], 'changed' => 0];
        }
        $prices = app(SwingReactionPriceSource::class);
        $capture = $prices->capture($symbol, $now);
        $rules = app(SwingReactionRules::class);
        $changed = 0;
        DB::transaction(function () use ($symbol, $now, $prices, $capture, $rules, &$changed) {
            $rows = DB::table('swing_reaction_ideas')->where('symbol', $symbol)
                ->where('rule_version', SwingReactionRules::VERSION)->whereIn('status', self::ACTIVE)->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return;
            }
            $states = $rows->mapWithKeys(fn ($row) => [$row->id => json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR)]);
            $from = $states->map(fn ($s) => $s['last_bar_at'] ?? $s['available_at'])->min();
            $bars = $prices->bars($symbol, CarbonImmutable::parse($from), $now);
            foreach ($rows as $row) {
                $old = $state = $states[$row->id];
                foreach ($capture['conflicts'] as $at) {
                    if (strtotime($at.' UTC') >= strtotime($state['available_at']) && strtotime($at.' UTC') <= strtotime($state['last_bar_at'] ?? $state['available_at'])) {
                        $state['evidence_gap'] = true;
                    }
                }
                foreach ($bars as $bar) {
                    if ($bar['at'] <= ($state['last_bar_at'] ?? $state['available_at'])) {
                        continue;
                    }
                    $next = $rules->advance($state, $bar, $now);
                    if ($this->transition($state) !== $this->transition($next)) {
                        $this->review($row->id, $next, $now, $bar['at']);
                    }
                    $state = $next;
                    if (! in_array($state['status'], self::ACTIVE, true)) {
                        break;
                    }
                }
                $next = $rules->expire($state, $now->subSeconds(930));
                if ($this->transition($state) !== $this->transition($next)) {
                    $this->review($row->id, $next, $now, $now->toIso8601String());
                }
                if ($next !== $old) {
                    DB::table('swing_reaction_ideas')->where('id', $row->id)->update([
                        'status' => $next['status'], 'current_state' => json_encode($next, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                        'updated_at' => $now->toDateTimeString(),
                    ]);
                    $changed++;
                }
            }
        });

        return [...$capture, 'changed' => $changed];
    }

    public function read(int $userId, string $horizon, string $scope = 'universe'): array
    {
        $symbols = ProductUniverse::symbols($scope === 'watchlist' ? $userId : null);
        if (! $this->prepared()) {
            return ['prepared' => false, 'items' => [], 'symbolCount' => count($symbols), 'evaluatedAt' => null];
        }
        $rows = DB::table('swing_reaction_ideas')->whereIn('symbol', $symbols)->where('horizon', $horizon)
            ->where('rule_version', SwingReactionRules::VERSION)->whereIn('status', self::ACTIVE)
            ->orderByRaw("CASE status WHEN 'ready' THEN 0 WHEN 'entry_touched' THEN 1 ELSE 2 END")
            ->orderBy('symbol')->orderByDesc('created_at')->limit(2000)->get();
        $items = [];
        $evaluated = null;
        foreach ($rows as $row) {
            $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
            $deadline = $state['status'] === 'entry_touched' ? ($state['holding_deadline'] ?? null) : ($state['entry_deadline'] ?? null);
            if (! $deadline || CarbonImmutable::parse($deadline)->lt(CarbonImmutable::now('UTC')->subSeconds(930))) {
                continue;
            }
            $evaluated = max($evaluated ?? '', $row->updated_at);
            $public = array_intersect_key($state, array_flip(['symbol', 'horizon', 'source_date', 'pattern', 'direction', 'side',
                'levels', 'evidence', 'status', 'available_at', 'qualified_at', 'entry_at', 'entry_price', 'entry_deadline',
                'holding_deadline', 'reason', 'next_condition', 'why', 'phase', 'last_at', 'last_price', 'rule_version']));
            $public['id'] = $row->id;
            $public['status_group'] = $state['status'];
            $public['next_condition'] = ['text' => $state['next_condition'] ?? $state['reason'] ?? 'Watch the price sequence at this wall.'];
            $public['latest_price'] = isset($state['last_price'], $state['last_at']) ? ['value' => $state['last_price'], 'at' => $state['last_at']] : null;
            $public['scope_label'] = SwingReactionRules::HORIZONS[$horizon]['scope_label'];
            $items[] = $public;
        }

        return ['prepared' => true, 'items' => $items, 'symbolCount' => count($symbols),
            'evaluatedAt' => $evaluated ? CarbonImmutable::parse($evaluated, 'UTC')->toIso8601String() : null,
            'summary' => array_count_values(array_column($items, 'status')), 'strategyVersion' => SwingReactionRules::VERSION];
    }

    private function key(array $plan): string
    {
        return implode(':', [$plan['horizon'], $plan['side'], $plan['pattern'], (float) $plan['levels']['wall']]);
    }

    private function transition(array $state): array
    {
        return array_intersect_key($state, array_flip(['status', 'phase', 'qualified_at', 'entry_at', 'outcome', 'evidence_gap']));
    }

    private function review(string $id, array $state, CarbonImmutable $recorded, string $observed): void
    {
        DB::table('swing_reaction_reviews')->insert(['idea_id' => $id,
            'observed_at' => CarbonImmutable::parse($observed)->utc()->toDateTimeString(), 'recorded_at' => $recorded->toDateTimeString(),
            'state_json' => json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]);
    }

    private function save(object $row, array $old, array $next, CarbonImmutable $now): void
    {
        if ($old === $next) {
            return;
        }
        $this->review($row->id, $next, $now, $next['outcome_at'] ?? $now->toIso8601String());
        DB::table('swing_reaction_ideas')->where('id', $row->id)->update(['status' => $next['status'],
            'current_state' => json_encode($next, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'updated_at' => $now->toDateTimeString()]);
    }
}
