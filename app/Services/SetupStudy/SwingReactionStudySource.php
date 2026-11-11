<?php

namespace App\Services\SetupStudy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reads retained touch-based swing plans and their dated state. No provider calls or writes. */
final class SwingReactionStudySource
{
    public function read(array $symbols, string $from, string $through): array
    {
        $result = ['samples' => [], 'truncated' => false, 'warnings' => []];
        if (! Schema::hasTable('swing_reaction_ideas')) {
            return $result;
        }
        $start = $this->date($from);
        $end = $this->date($through);
        if (! $start || ! $end || $start > $end) {
            return [...$result, 'warnings' => ['The swing reaction study date window is invalid.']];
        }
        $now = CarbonImmutable::now('UTC');
        $cutoff = min($now, $end->endOfDay()->utc());
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($symbol) => is_string($symbol) ? strtoupper(trim($symbol)) : '', $symbols
        ), static fn ($symbol) => preg_match('/^[A-Z0-9.^-]{1,16}$/D', $symbol))));
        if (count($symbols) > 50) {
            $symbols = array_slice($symbols, 0, 50);
            $result['truncated'] = true;
        }
        if (! $symbols) {
            return $result;
        }
        // Publication dates keep both unentered and entered scenarios in the funnel.
        $rows = DB::table('swing_reaction_ideas')->whereIn('symbol', $symbols)
            ->where('created_at', '>=', $start->startOfDay()->utc()->format('Y-m-d H:i:s'))
            ->where('created_at', '<=', $cutoff->format('Y-m-d H:i:s'))
            ->orderBy('created_at')->orderBy('id')->limit(501)->get();
        if ($rows->count() > 500) {
            $rows = $rows->take(500);
            $result['truncated'] = true;
        }
        $reviews = collect();
        if ($rows->isNotEmpty() && Schema::hasTable('swing_reaction_reviews')) {
            $latest = DB::table('swing_reaction_reviews')->whereIn('idea_id', $rows->pluck('id'))
                ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s'))
                ->groupBy('idea_id')->selectRaw('MAX(id) as id');
            $reviews = DB::table('swing_reaction_reviews')->whereIn('id', $latest)
                ->get(['idea_id', 'recorded_at', 'state_json'])->keyBy('idea_id');
        }
        foreach ($rows as $row) {
            $original = $this->decode($row->original_plan);
            $published = $this->instant($original['available_at'] ?? $row->created_at);
            if (! $original || ! $published || $published > $cutoff || ! in_array($row->horizon, ['short', 'position'], true)) {
                continue;
            }
            $updated = $this->instant($row->updated_at);
            $review = $reviews->get($row->id);
            $retainedState = $this->decode($row->current_state);
            $state = $updated && $updated <= $cutoff ? $retainedState
                : ($review ? $this->decode($review->state_json) : $original);
            if (! $state) {
                $result['warnings'][] = 'A swing reaction plan contains unreadable retained state.';

                continue;
            }
            $levels = is_array($original['levels'] ?? null) ? $original['levels'] : [];
            $entry = $this->instant($state['entry_at'] ?? null);
            $entryPrice = $this->number($state['entry_price'] ?? null);
            $ready = $this->instant($state['qualified_at'] ?? null);
            $readyRecorded = $this->instant($state['ready_recorded_at'] ?? null);
            $entryValid = $entry && $entry <= $cutoff && $entry >= $published && $ready && $entry > $ready
                && $readyRecorded && $readyRecorded >= $published && $entry->subMinutes(5) >= $readyRecorded
                && $entryPrice !== null && $entryPrice > 0;
            $invalidEntry = ! empty($state['entry_at']) && ! $entryValid;
            $result['samples'][] = [
                'id' => (string) $row->id, 'mode' => 'swing', 'metric_basis' => 'price_touch',
                'horizon' => $row->horizon, 'symbol' => $row->symbol, 'session' => $row->source_date,
                'source_date' => $row->source_date, 'rule_version' => $row->rule_version,
                'tier' => $this->tier($original, $retainedState, $cutoff),
                'timeframe' => $row->horizon === 'short' ? '30d' : '90d',
                'pattern' => $original['pattern'] ?? null, 'direction' => $original['direction'] ?? null,
                'side' => $original['side'] ?? null,
                'signal_at' => $entryValid ? $entry->toIso8601String() : null,
                'signal_price' => $entryValid ? $entryPrice : null,
                'available_at' => $published->toIso8601String(), 'first_recorded_at' => $published->toIso8601String(),
                'qualified_at' => $ready?->toIso8601String(),
                'ready_recorded_at' => $readyRecorded?->toIso8601String(),
                'entry_low' => $this->number($levels['entry_low'] ?? null),
                'entry_high' => $this->number($levels['entry_high'] ?? null),
                'invalidation' => $this->number($levels['invalidation'] ?? null),
                'target' => $this->number($levels['target'] ?? null),
                'review_until' => $state['holding_deadline'] ?? $state['entry_deadline'] ?? null,
                'review_label' => $entryValid ? 'Touch-based holding deadline' : 'Entry deadline',
                'native_status' => $state['status'] ?? $original['status'] ?? 'watching',
                'native_outcome' => $state['outcome'] ?? null,
                'outcome_at' => $state['outcome_at'] ?? null,
                'cancellation_reason' => $state['cancellation_reason'] ?? null,
                'evidence_gap' => (bool) ($state['evidence_gap'] ?? false),
                'ambiguous' => (bool) ($state['ambiguous'] ?? false) || $invalidEntry,
                'native_metrics' => is_array($state['metrics'] ?? null) ? $state['metrics'] : [],
                'observations' => (int) ($state['observations'] ?? 0), 'last_at' => $state['last_at'] ?? null,
                'confirmations' => $ready ? [$ready->toIso8601String()] : [],
                'source' => ['table' => 'swing_reaction_ideas', 'id' => (string) $row->id,
                    'original_plan' => $original, 'state_recorded_at' => $updated && $updated <= $cutoff
                        ? $updated->toIso8601String() : ($review?->recorded_at ?? $published->toIso8601String()),
                    'price_basis' => 'observed_high_low', 'execution_assumed' => false],
                'evidence' => is_array($original['evidence'] ?? null) ? $original['evidence'] : [],
            ];
        }
        $result['warnings'] = array_values(array_unique($result['warnings']));

        return $result;
    }

    /** Tier inputs are frozen original evidence, but assignment must already be known at the report cutoff. */
    private function tier(array $original, array $retainedState, CarbonImmutable $cutoff): ?array
    {
        foreach ([$original['tier'] ?? null, $retainedState['tier'] ?? null] as $tier) {
            if (! is_array($tier) || ! in_array($tier['key'] ?? null, ['gold', 'silver', 'bronze'], true)
                || ! is_string($tier['version'] ?? null) || $tier['version'] === '') {
                continue;
            }
            $assigned = $this->instant($tier['assigned_at'] ?? null);
            if ($assigned && $assigned <= $cutoff) {
                return $tier;
            }
        }

        return null;
    }

    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) ? $value : [];
    }

    private function date(string $date): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && strtotime($date) !== false
            ? CarbonImmutable::parse($date, 'America/New_York')->startOfDay() : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' && strtotime($value) !== false
            ? CarbonImmutable::parse($value, 'UTC')->utc() : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
