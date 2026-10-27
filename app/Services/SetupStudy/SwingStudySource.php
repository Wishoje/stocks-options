<?php

namespace App\Services\SetupStudy;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reads frozen published plans without refreshing rules, evidence, or providers. */
final class SwingStudySource
{
    private const MAX_DAYS = 60;

    private const MAX_SYMBOLS = 50;

    private const MAX_IDEAS = 500;

    private const MAX_REVIEWS = 25000;

    private const MAX_BARS = 50;

    public function read(array $symbols, string $from, string $through): array
    {
        $result = ['samples' => [], 'truncated' => false, 'warnings' => []];
        $start = $this->date($from);
        $end = $this->date($through);
        if (! $start || ! $end || $start > $end) {
            return [...$result, 'warnings' => ['The swing study date window is invalid.']];
        }
        $now = CarbonImmutable::now('UTC');
        $cutoff = min($now, $end->endOfDay()->utc());
        $end = min($end, $now->setTimezone('America/New_York')->startOfDay());
        $earliest = $end->subDays(self::MAX_DAYS - 1);
        if ($start < $earliest) {
            $start = $earliest;
            $result['truncated'] = true;
            $result['warnings'][] = 'Swing source reads are limited to 60 calendar days.';
        }
        if ($start > $end) {
            return $result;
        }
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($symbol) => is_string($symbol) ? strtoupper(trim($symbol)) : '', $symbols
        ), static fn ($symbol) => preg_match('/^[A-Z0-9.^-]{1,16}$/D', $symbol))));
        if (count($symbols) > self::MAX_SYMBOLS) {
            $symbols = array_slice($symbols, 0, self::MAX_SYMBOLS);
            $result['truncated'] = true;
            $result['warnings'][] = 'Swing source reads are limited to 50 symbols.';
        }
        if (! $symbols) {
            return $result;
        }
        if (! Schema::hasTable('swing_ideas')) {
            return [...$result, 'warnings' => [...$result['warnings'], 'Published swing plans are not available.']];
        }
        $rows = DB::table('swing_ideas')->whereIn('symbol', $symbols)
            ->whereBetween('source_date', [$start->toDateString(), $end->toDateString()])
            ->where('created_at', '<=', $cutoff->format('Y-m-d H:i:s'))
            ->where('status', '<>', 'no_idea')->orderBy('source_date')->orderBy('id')
            ->limit(self::MAX_IDEAS + 1)->get();
        if ($rows->count() > self::MAX_IDEAS) {
            $rows = $rows->take(self::MAX_IDEAS);
            $result['truncated'] = true;
            $result['warnings'][] = 'Swing source reads are limited to 500 published plans.';
        }
        if ($rows->isEmpty()) {
            return $result;
        }

        $reviews = collect();
        if (Schema::hasTable('swing_idea_reviews')) {
            $reviews = DB::table('swing_idea_reviews')->whereIn('idea_id', $rows->pluck('id')->all())
                ->where('data_date', '<=', $end->toDateString())
                ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s'))
                ->orderBy('recorded_at')->orderBy('id')->limit(self::MAX_REVIEWS + 1)->get();
            if ($reviews->count() > self::MAX_REVIEWS) {
                $reviews = $reviews->take(self::MAX_REVIEWS);
                $result['truncated'] = true;
                $result['warnings'][] = 'The swing review limit was reached; some qualifications may be unavailable.';
            }
        } else {
            $result['warnings'][] = 'Swing qualification reviews are not available.';
        }
        $reviews = $reviews->groupBy('idea_id');
        $bars = $this->bars($rows->pluck('symbol')->unique()->all(), $start, $end, $cutoff, $result);
        foreach ($rows as $row) {
            $original = $this->json($row->original_plan);
            if (! $original || ($original['status'] ?? null) === 'no_idea') {
                if (! $original) {
                    $result['warnings'][] = 'A swing plan contains unreadable original evidence.';
                }

                continue;
            }
            $published = $this->instant($original['first_recorded_at'] ?? null) ?? $this->instant($row->created_at);
            if (! $published || $published > $cutoff || ! in_array($row->horizon, ['short', 'position'], true)) {
                continue;
            }
            $state = $original;
            $qualified = null;
            $qualificationSource = null;
            $available = $published;
            foreach ($reviews->get($row->id, collect()) as $review) {
                $assessment = $this->json($review->assessment);
                $recorded = $this->instant($review->recorded_at);
                if (! $assessment || ! $recorded || $recorded < $published) {
                    continue;
                }
                $state = $assessment;
                if (! $qualified && $this->qualification($assessment, $original, $published, $cutoff, $review->data_date)) {
                    $qualified = $assessment;
                    $available = max($published, $recorded, $this->close($assessment['qualified_at']));
                    $qualificationSource = ['table' => 'swing_idea_reviews', 'id' => $review->id,
                        'data_date' => $review->data_date, 'recorded_at' => $recorded->toIso8601String(),
                        'evidence_hash' => $review->evidence_hash];
                }
            }
            $updated = $this->instant($row->updated_at);
            if ($updated && $updated <= $cutoff) {
                $current = $this->json($row->current_state);
                if ($current) {
                    $state = $current;
                    if (! $qualified && $this->qualification($current, $original, $published, $cutoff)) {
                        $qualified = $current;
                        $available = max($published, $updated, $this->close($current['qualified_at']));
                        $qualificationSource = ['table' => 'swing_ideas', 'field' => 'current_state',
                            'recorded_at' => $updated->toIso8601String(), 'availability_estimated' => true];
                        $result['warnings'][] = 'A swing qualification has no recorded review; availability uses its last saved time.';
                    }
                }
            }
            $signal = $qualified ? $this->close($qualified['qualified_at']) : null;
            if (! $signal && ! empty($state['qualified_at'])) {
                $result['warnings'][] = 'A saved swing qualification could not be verified after publication and activation.';
            }
            $deadline = $qualified ? $this->close($qualified['deadline'] ?? null) : null;
            $levels = is_array($original['levels'] ?? null) ? $original['levels'] : [];
            $direction = in_array($original['direction'] ?? null, ['bullish', 'bearish'], true) ? $original['direction'] : null;
            $result['samples'][] = [
                'id' => (string) $row->id, 'mode' => 'swing', 'horizon' => $row->horizon,
                'symbol' => $row->symbol, 'session' => $row->source_date,
                'timeframe' => $row->horizon === 'short' ? '30d' : '90d', 'direction' => $direction,
                'side' => $direction === 'bullish' ? 'put' : ($direction === 'bearish' ? 'call' : null),
                'pattern' => $original['pattern'] ?? null, 'rule_version' => $row->rule_version,
                'signal_at' => $signal?->toIso8601String(), 'available_at' => $available->toIso8601String(),
                'first_recorded_at' => $published->toIso8601String(), 'source_date' => $row->source_date,
                'activation_session' => $original['activation_session'] ?? $original['plan_session'] ?? null,
                'signal_price' => $qualified ? $this->number($qualified['qualified_price'] ?? null) : null,
                'entry_low' => $this->number($levels['entry_low'] ?? null), 'entry_high' => $this->number($levels['entry_high'] ?? null),
                'invalidation' => $this->number($levels['invalidation'] ?? null), 'invalidation_side' => $levels['invalidation_side'] ?? null,
                'target' => $this->number($levels['target'] ?? null), 'review_until' => $deadline?->toIso8601String(),
                'review_label' => $deadline ? 'Frozen holding deadline' : 'Holding deadline starts after qualification',
                'native_outcome' => $state['outcome'] ?? null, 'native_status' => $state['status'] ?? $original['status'] ?? null,
                'confirmations' => $signal ? [$signal->toIso8601String()] : [],
                'bars' => array_values(array_filter($bars[$row->symbol] ?? [], static fn ($bar) => $bar['at'] > ($signal?->toIso8601String() ?? $published->toIso8601String()))),
                'source' => [
                    'table' => 'swing_ideas', 'id' => (string) $row->id, 'source_hash' => $row->source_hash,
                    'source_version' => $original['source_version'] ?? null,
                    'volatility_version' => $original['volatility_version'] ?? null, 'original_plan' => $original,
                    'qualification' => $qualificationSource,
                ],
                'evidence' => is_array($original['evidence'] ?? null) ? $original['evidence'] : [],
            ];
        }
        $result['warnings'] = array_values(array_unique($result['warnings']));

        return $result;
    }

    private function qualification(array $state, array $original, CarbonImmutable $published, CarbonImmutable $cutoff, ?string $reviewDate = null): bool
    {
        $date = $this->date($state['qualified_at'] ?? null);
        $activation = $this->date($original['activation_session'] ?? $original['plan_session'] ?? null);
        $close = $date ? $this->close($date->toDateString()) : null;

        return $close && $activation && $date >= $activation && $close >= $published && $close <= $cutoff
            && ($reviewDate === null || $date->toDateString() <= $reviewDate)
            && $this->number($state['qualified_price'] ?? null) > 0;
    }

    private function bars(array $symbols, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $cutoff, array &$result): array
    {
        if (! Schema::hasTable('prices_daily')) {
            $result['warnings'][] = 'Daily prices are not available for swing measurement.';

            return [];
        }
        $bars = [];
        foreach ($symbols as $symbol) {
            $rows = DB::table('prices_daily')->where('symbol', $symbol)
                ->whereBetween('trade_date', [$start->toDateString(), $end->toDateString()])
                ->orderBy('trade_date')->limit(self::MAX_BARS + 1)->get(['trade_date', 'open', 'high', 'low', 'close']);
            if ($rows->count() > self::MAX_BARS) {
                $rows = $rows->take(self::MAX_BARS);
                $result['truncated'] = true;
                $result['warnings'][] = 'Swing measurement is limited to 50 daily bars per symbol.';
            }
            foreach ($rows as $row) {
                $at = $this->close($row->trade_date);
                $ohlc = array_map(fn ($field) => $this->number($row->{$field}), ['open', 'high', 'low', 'close']);
                if (! $at || $at > $cutoff || in_array(null, $ohlc, true) || min($ohlc) <= 0
                    || $ohlc[1] < max($ohlc[0], $ohlc[3]) || $ohlc[2] > min($ohlc[0], $ohlc[3])) {
                    continue;
                }
                $bars[$symbol][] = ['at' => $at->toIso8601String(), 'open' => $ohlc[0], 'high' => $ohlc[1], 'low' => $ohlc[2], 'close' => $ohlc[3]];
            }
        }

        return $bars;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'America/New_York');

            return $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function close(mixed $date): ?CarbonImmutable
    {
        $day = $this->date($date);
        $at = $day ? MarketSession::describe($day)['closes_at'] : null;

        return $at ? CarbonImmutable::parse($at)->utc() : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function json(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
