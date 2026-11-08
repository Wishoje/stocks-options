<?php

namespace App\Services;

use App\Support\PublicSpyPreview;
use App\Support\Swing\PausedSwingPlan;
use App\Support\Swing\SwingIdeaRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SwingIdeaService
{
    /** Command-only publication. Original plans and daily reviews are never rewritten. */
    public function refresh(array $input): array
    {
        $date = $input['data_date'];
        if ($date > PublicSpyPreview::completedSession()) {
            throw new \DomainException('A completed EOD date is required.');
        }
        $rules = new SwingIdeaRules;
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($input, $date, $rules, $hash) {
            $published = [];
            foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                $existing = DB::table('swing_ideas')->where('symbol', $input['symbol'])->where('horizon', $horizon)
                    ->where('rule_version', SwingIdeaRules::VERSION)->whereIn('status', ['watching', 'conditions_met', 'in_progress', 'needs_update'])
                    ->orderByDesc('source_date')->limit(10)->lockForUpdate()->get();
                foreach ($existing as $row) {
                    // Preserve paused evidence byte-for-byte while considering a later plan separately.
                    if ($row->status === 'needs_update') {
                        continue;
                    }
                    $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
                    if ($state['source_date'] > $date) {
                        continue;
                    }
                    if ($row->source_date === $date && $row->source_hash !== $hash) {
                        $state = [...$state, 'status' => 'needs_update', 'reason' => 'The published EOD evidence changed. This frozen plan needs review.'];
                    }
                    $recorded = DB::table('swing_idea_reviews')->where('idea_id', $row->id)
                        ->whereIn('data_date', array_column($input['prices'], 'date'))->pluck('evidence_hash', 'data_date');
                    foreach ($input['prices'] as $bar) {
                        if (isset($recorded[$bar['date']]) && $recorded[$bar['date']] !== hash('sha256', json_encode($bar))) {
                            $state = [...$state, 'status' => 'needs_update', 'reason' => 'A previously reviewed daily bar changed. The original plan and reviews are preserved for review.'];
                            break;
                        }
                    }
                    foreach ($input['prices'] as $bar) {
                        if ($bar['date'] <= $row->reviewed_through || $bar['date'] > $date) {
                            continue;
                        }
                        $next = $rules->advance($state, $bar);
                        if ($next === $state) {
                            continue;
                        }
                        $state = $next;
                        DB::table('swing_idea_reviews')->insertOrIgnore(['idea_id' => $row->id, 'data_date' => $bar['date'],
                            'evidence_hash' => hash('sha256', json_encode($bar)), 'assessment' => json_encode($state, JSON_THROW_ON_ERROR), 'recorded_at' => now()]);
                    }
                    DB::table('swing_ideas')->where('id', $row->id)->update(['status' => $state['status'],
                        'reviewed_through' => $state['reviewed_through'] ?? $row->reviewed_through,
                        'current_state' => json_encode($state, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                }
                // A later plan has its own identity; frozen levels and qualified plans remain protected.
                $active = DB::table('swing_ideas')->where('symbol', $input['symbol'])->where('horizon', $horizon)
                    ->where('rule_version', SwingIdeaRules::VERSION)->whereIn('status', ['watching', 'conditions_met', 'in_progress', 'needs_update'])
                    ->get(['status', 'current_state'])->contains(function ($row) use ($date) {
                        if ($row->status !== 'needs_update') {
                            return true;
                        }
                        $state = json_decode($row->current_state, true);

                        return ! is_array($state) || ! PausedSwingPlan::replaceable($state, $date);
                    });
                if ($active) {
                    continue;
                }
                $plan = $rules->propose($input, $horizon);
                $market = \App\Support\MarketSession::describe();
                $plan['activation_session'] = substr(\Carbon\CarbonImmutable::parse($market['next_open_at'])->setTimezone('America/New_York')->toDateString(), 0, 10);
                if ($plan['status'] === 'watching' && $plan['activation_session'] > $plan['entry_deadline']) {
                    $plan['status'] = 'no_idea';
                    $plan['reason'] = 'The entry window ended before this plan could become active. A newer EOD reading is needed.';
                }
                $plan['first_recorded_at'] = now('UTC')->toIso8601String();
                $plan['source_version'] = $input['source_version'] ?? null;
                $plan['volatility_version'] = $input['volatility_version'] ?? null;
                $plan['reviewed_through'] = $date;
                $id = hash('sha256', json_encode([$input['symbol'], $horizon, SwingIdeaRules::VERSION, $date]));
                $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $inserted = DB::table('swing_ideas')->insertOrIgnore(['id' => $id, 'symbol' => $input['symbol'], 'horizon' => $horizon,
                    'rule_version' => SwingIdeaRules::VERSION, 'source_date' => $date, 'source_hash' => $hash,
                    'status' => $plan['status'], 'reviewed_through' => $date, 'original_plan' => $json, 'current_state' => $json,
                    'created_at' => now(), 'updated_at' => now()]);
                if ($inserted) {
                    $published[] = $id;
                }
            }

            return $published;
        });
    }

    /** Personal watchlist read only; no calculations, writes, jobs or provider traffic. */
    public function read(int $userId, string $horizon): array
    {
        if (! Schema::hasTable('swing_ideas')) {
            return ['items' => [], 'prepared' => false];
        }
        $symbols = DB::table('watchlists')->where('user_id', $userId)->select('symbol')->distinct();
        $query = DB::table('swing_ideas')->whereIn('symbol', $symbols)->where('horizon', $horizon)
            ->where('rule_version', SwingIdeaRules::VERSION);
        $activeStatuses = ['watching', 'conditions_met', 'in_progress', 'needs_update'];
        // Bound historical rows without hiding older open plans as daily no-idea records accumulate.
        $active = (clone $query)->whereIn('status', $activeStatuses)->get();
        $rows = (clone $query)->orderByDesc('source_date')->orderByDesc('created_at')->limit(250)->get()
            ->concat($active)->unique('id')->sort(fn ($a, $b) => [$b->source_date, $b->created_at, $b->id] <=> [$a->source_date, $a->created_at, $a->id]);
        $completedSession = PublicSpyPreview::completedSession();
        $prices = collect();
        if ($active->isNotEmpty() && Schema::hasTable('prices_daily')) {
            $latestDates = DB::table('prices_daily')->whereIn('symbol', $active->pluck('symbol')->unique())
                ->where('trade_date', '<=', $completedSession)->groupBy('symbol')->select('symbol')->selectRaw('MAX(trade_date) as latest_date');
            $prices = DB::table('prices_daily as prices')->joinSub($latestDates, 'latest', fn ($join) => $join
                ->on('latest.symbol', '=', 'prices.symbol')->on('latest.latest_date', '=', 'prices.trade_date'))
                ->get(['prices.symbol', 'prices.trade_date', 'prices.close'])->keyBy('symbol');
        }
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
            // Always retain open plans; only collapse repeated no-idea records per symbol.
            if (isset($seen[$row->symbol]) && ! in_array($row->status, [...$activeStatuses, 'finished'], true)) {
                continue;
            }
            $seen[$row->symbol] = true;
            $items[] = [...$state, 'id' => $row->id, 'prepared_at' => $row->created_at,
                'status_group' => $row->status === 'no_idea' ? 'finished' : $row->status,
                'outcome' => $row->status === 'no_idea' ? 'no_setup' : ($state['outcome'] ?? null),
                'is_current' => $row->reviewed_through === $completedSession,
                'latest_price' => in_array($row->status, $activeStatuses, true) ? $this->latestPrice($prices->get($row->symbol), $state['levels'] ?? null) : null];
        }

        return ['items' => $items, 'prepared' => true];
    }

    /** Read the newest dated idea for each saved symbol without publishing private outcome research. */
    public function readCustomerIdeas(int $userId, string $horizon): array
    {
        if (! Schema::hasTable('swing_ideas')) {
            return ['items' => [], 'prepared' => false];
        }
        $profile = SwingIdeaRules::HORIZONS[$horizon];
        $completedSession = PublicSpyPreview::completedSession();
        $availabilitySession = now('America/New_York')->toDateString();
        $cutoff = \Carbon\CarbonImmutable::parse($availabilitySession, 'America/New_York');
        $remaining = $profile['max_sessions'] + 5;
        while ($remaining > 0) {
            $cutoff = $cutoff->subDay();
            if (\App\Support\MarketSession::isTradingDay($cutoff)) {
                $remaining--;
            }
        }
        $symbols = DB::table('watchlists')->where('user_id', $userId)->select('symbol')->distinct();
        $newest = DB::table('swing_ideas')->whereIn('symbol', $symbols)->where('horizon', $horizon)
            ->where('rule_version', SwingIdeaRules::VERSION)->where('original_plan->status', 'watching')
            ->whereBetween('source_date', [$cutoff->toDateString(), $completedSession])
            ->groupBy('symbol')->select('symbol')->selectRaw('MAX(source_date) as newest_date');
        $rows = DB::table('swing_ideas as ideas')->joinSub($newest, 'newest', fn ($join) => $join
            ->on('ideas.symbol', '=', 'newest.symbol')->on('ideas.source_date', '=', 'newest.newest_date'))
            ->where('ideas.horizon', $horizon)->where('ideas.rule_version', SwingIdeaRules::VERSION)
            ->orderBy('ideas.symbol')->limit(1000)
            ->get(['ideas.id', 'ideas.symbol', 'ideas.original_plan', 'ideas.current_state', 'ideas.created_at', 'ideas.reviewed_through']);
        $prices = collect();
        if ($rows->isNotEmpty() && Schema::hasTable('prices_daily')) {
            $latestDates = DB::table('prices_daily')->whereIn('symbol', $rows->pluck('symbol')->unique())
                ->where('trade_date', '<=', $completedSession)->groupBy('symbol')->select('symbol')->selectRaw('MAX(trade_date) as latest_date');
            $prices = DB::table('prices_daily as prices')->joinSub($latestDates, 'latest', fn ($join) => $join
                ->on('latest.symbol', '=', 'prices.symbol')->on('latest.latest_date', '=', 'prices.trade_date'))
                ->get(['prices.symbol', 'prices.trade_date', 'prices.close'])->keyBy('symbol');
        }
        $items = [];
        foreach ($rows as $row) {
            $original = json_decode($row->original_plan, true, flags: JSON_THROW_ON_ERROR);
            $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($original['levels'] ?? null)) {
                continue;
            }
            $idea = \App\Support\CustomerIdeaPresentation::swing($original, $state, [
                'id' => $row->id, 'prepared_at' => $row->created_at, 'reviewed_through' => $row->reviewed_through,
                'is_current' => true, 'latest_price' => $this->latestPrice($prices->get($row->symbol), $original['levels']),
            ]);
            if ($idea['available_through'] >= $availabilitySession) {
                $items[] = $idea;
            }
        }

        return ['items' => $items, 'prepared' => true, 'presentation' => \App\Support\CustomerIdeaPresentation::VERSION];
    }

    /** Read-time price context; original plan evidence and entry conditions stay frozen. */
    private function latestPrice(?object $price, ?array $levels): ?array
    {
        $close = $price?->close;
        $low = $levels['entry_low'] ?? null;
        $high = $levels['entry_high'] ?? null;
        foreach ([$close, $low, $high] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value <= 0) {
                return null;
            }
        }
        if ($low > $high) {
            return null;
        }
        $close = (float) $close;

        return ['date' => $price->trade_date, 'close' => $close,
            'entry_position' => $close < $low ? 'below' : ($close > $high ? 'above' : 'inside'),
            'distance_to_entry_pct' => 100 * max($low - $close, $close - $high, 0) / $close];
    }
}
