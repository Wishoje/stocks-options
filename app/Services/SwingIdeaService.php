<?php

namespace App\Services;

use App\Support\PublicSpyPreview;
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
                // A newer EOD refresh does not silently move an existing idea's levels.
                $active = DB::table('swing_ideas')->where('symbol', $input['symbol'])->where('horizon', $horizon)
                    ->where('rule_version', SwingIdeaRules::VERSION)->whereIn('status', ['watching', 'conditions_met', 'in_progress', 'needs_update'])->exists();
                if ($active) {
                    continue;
                }
                $plan = $rules->propose($input, $horizon);
                $market = \App\Support\MarketSession::describe();
                $plan['activation_session'] = substr(\Carbon\CarbonImmutable::parse($market['next_open_at'])->setTimezone('America/New_York')->toDateString(), 0, 10);
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
        $rows = DB::table('swing_ideas')->whereIn('symbol', $symbols)->where('horizon', $horizon)
            ->where('rule_version', SwingIdeaRules::VERSION)->orderByDesc('source_date')->orderByDesc('created_at')->limit(250)->get();
        $items = [];
        $seen = [];
        foreach ($rows as $row) {
            $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
            // Include prior qualified ideas; collapse repeated no-idea/watch plans per symbol.
            if (isset($seen[$row->symbol]) && ! in_array($row->status, ['conditions_met', 'in_progress', 'finished', 'needs_update'], true)) {
                continue;
            }
            $seen[$row->symbol] = true;
            $items[] = [...$state, 'id' => $row->id, 'prepared_at' => $row->created_at,
                'status_group' => $row->status === 'no_idea' ? 'finished' : $row->status,
                'outcome' => $row->status === 'no_idea' ? 'no_setup' : ($state['outcome'] ?? null),
                'is_current' => $row->reviewed_through === PublicSpyPreview::completedSession()];
        }

        return ['items' => $items, 'prepared' => true];
    }
}
