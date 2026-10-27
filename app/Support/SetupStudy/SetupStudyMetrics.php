<?php

namespace App\Support\SetupStudy;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Observed-close scenario measurements. No assumed fills, orders, or option P&L. */
final class SetupStudyMetrics
{
    public const VERSION = 'recorded-close-study.v1';

    public function measure(array $sample, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $bars = $sample['bars'] ?? [];
        unset($sample['bars']);
        $qualified = ! empty($sample['signal_at']) && $this->number($sample['signal_price'] ?? null) !== null;
        $result = [...$sample, 'metric_version' => self::VERSION, 'qualified' => $qualified,
            'confirmation_count' => count(array_unique($sample['confirmations'] ?? [])),
            'followup_complete' => false, 'needs_update' => ($sample['native_status'] ?? null) === 'needs_update',
            'status' => $qualified ? 'in_progress' : 'watching', 'outcome' => null,
            'metrics' => ['mfe_r' => null, 'mae_r' => null, 'mfe_pct' => null, 'mae_pct' => null,
                'close_mfe_r' => null, 'close_mae_r' => null, 'reached_05r' => null, 'reached_1r' => null,
                'entry_revisited' => null, 'entry_observable' => false, 'strict_outcome' => null, 'strict_at' => null,
                'observations' => 0, 'last_at' => null, 'risk_per_share' => null, 'evidence_gap' => false,
                'first_05r_at' => null, 'first_1r_at' => null, 'publication_timing' => 'timing_unknown']];
        if (! $qualified) {
            $result['status'] = in_array($sample['native_status'] ?? '', ['finished', 'no_idea'], true) ? 'not_qualified' : 'watching';

            return $result;
        }
        $price = $this->number($sample['signal_price']);
        $invalid = $this->number($sample['invalidation'] ?? null);
        $target = $this->number($sample['target'] ?? null);
        $d = ($sample['direction'] ?? null) === 'bullish' ? 1 : -1;
        $risk = $invalid === null ? 0 : $d * ($price - $invalid);
        if ($risk <= 0 || $target === null || $d * ($target - $price) <= 0 || empty($sample['review_until'])) {
            return [...$result, 'status' => 'needs_update', 'needs_update' => true];
        }
        $start = CarbonImmutable::parse($sample['signal_at'])->utc();
        $end = CarbonImmutable::parse($sample['review_until'])->utc();
        $available = empty($sample['available_at']) ? null : CarbonImmutable::parse($sample['available_at'])->utc();
        $unique = [];
        foreach ($bars as $bar) {
            if (empty($bar['at']) || $this->number($bar['close'] ?? null) === null || $bar['close'] <= 0) {
                continue;
            }
            $at = CarbonImmutable::parse($bar['at'])->utc();
            if ($at->gt($start) && $at->lte($end) && $at->lte($now)) {
                $unique[$at->getTimestamp()] = [...$bar, 'at' => $at];
            }
        }
        ksort($unique, SORT_NUMERIC);
        $m = $result['metrics'];
        $m['risk_per_share'] = $risk;
        $m['reached_05r'] = $m['reached_1r'] = false;
        $mfe = $mae = 0.;
        $expected = $this->next($start, $sample['mode']);
        $stopped = false;
        $entryExpected = $available ? $this->nextAvailableBar($available, $sample['mode']) : null;
        $entryComplete = $entryExpected !== null;
        foreach ($unique as $bar) {
            $at = $bar['at'];
            if (! $at->equalTo($expected)) {
                $m['evidence_gap'] = true;
            }
            $expected = $this->next($at, $sample['mode']);
            $m['observations']++;
            $m['last_at'] = $at->toIso8601String();
            $move = $d * ((float) $bar['close'] - $price);
            $mfe = max($mfe, $move);
            $mae = max($mae, -$move);
            $m['reached_05r'] = $m['reached_05r'] || $move >= .5 * $risk - 1e-9;
            $m['reached_1r'] = $m['reached_1r'] || $move >= $risk - 1e-9;
            if ($m['reached_05r']) {
                $m['first_05r_at'] ??= $at->toIso8601String();
            }
            if ($m['reached_1r']) {
                $m['first_1r_at'] ??= $at->toIso8601String();
            }
            // Only a whole bar beginning after availability can establish a zone revisit.
            if ($entryExpected && $at->gte($entryExpected)) {
                if (! $at->equalTo($entryExpected)) {
                    $entryComplete = false;
                }
                $entryExpected = $this->next($at, $sample['mode']);
                $high = $this->number($bar['high'] ?? null);
                $low = $this->number($bar['low'] ?? null);
                if ($high !== null && $low !== null && $high >= ($sample['entry_low'] ?? INF) && $low <= ($sample['entry_high'] ?? -INF)) {
                    $m['entry_revisited'] = true;
                }
            }
            if ($d * ((float) $bar['close'] - $invalid) < -1e-9) {
                $m['strict_outcome'] = 'invalidated';
                $stopped = true;
            } elseif ($d * ((float) $bar['close'] - $target) >= -1e-9) {
                $m['strict_outcome'] = 'target_reached';
                $stopped = true;
            }
            if ($stopped) {
                $m['strict_at'] = $at->toIso8601String();
                break;
            }
        }
        if ($m['observations'] > 0) {
            $m['mfe_r'] = $m['close_mfe_r'] = round($mfe / $risk, 4);
            $m['mae_r'] = $m['close_mae_r'] = round($mae / $risk, 4);
            $m['mfe_pct'] = round(100 * $mfe / $price, 4);
            $m['mae_pct'] = round(100 * $mae / $price, 4);
        } else {
            $m['reached_05r'] = $m['reached_1r'] = null;
        }
        $last = $m['last_at'] ? CarbonImmutable::parse($m['last_at']) : null;
        $complete = ! $m['evidence_gap'] && ($stopped || ($now->gte($end) && $last?->gte($end)));
        if ($complete && ! $stopped) {
            $m['strict_outcome'] = $sample['mode'] === 'intraday' ? 'session_ended' : 'holding_ended';
            $m['strict_at'] = $end->toIso8601String();
        }
        $m['entry_observable'] = $available !== null && $last !== null && $last->gte($this->nextAvailableBar($available, $sample['mode'])) && $entryComplete;
        if ($m['entry_observable'] && $m['entry_revisited'] === null) {
            $m['entry_revisited'] = false;
        }
        $overdue = $now->gte($end) && ! $complete;
        $needsUpdate = $result['needs_update'] || $m['evidence_gap'] || $overdue;
        if ($m['evidence_gap']) {
            $m['unverified_outcome'] = $m['strict_outcome'];
            $m['strict_outcome'] = $m['strict_at'] = null;
        }
        if ($available && ! $m['evidence_gap']) {
            if ($m['strict_at']) {
                $m['publication_timing'] = CarbonImmutable::parse($m['strict_at'])->lte($available)
                    ? 'resolved_before_publication' : 'open_at_publication';
            } elseif ($last?->gte($available)) {
                $m['publication_timing'] = 'open_at_publication';
            }
        }

        return [...$result, 'followup_complete' => $complete && ! $needsUpdate, 'needs_update' => $needsUpdate,
            'status' => $needsUpdate ? 'needs_update' : ($complete ? 'completed' : 'in_progress'),
            'outcome' => $m['strict_outcome'], 'metrics' => $m];
    }

    private function next(CarbonImmutable $at, string $mode): CarbonImmutable
    {
        if ($mode === 'intraday') {
            return $at->addMinutes(5);
        }
        $open = CarbonImmutable::parse(MarketSession::describe($at)['next_open_at']);

        return CarbonImmutable::parse(MarketSession::describe($open)['closes_at'])->utc();
    }

    private function nextAvailableBar(CarbonImmutable $at, string $mode): CarbonImmutable
    {
        if ($mode === 'intraday') {
            // A completed five-minute bar must start at or after publication.
            return CarbonImmutable::createFromTimestampUTC((int) (ceil($at->getTimestamp() / 300) * 300 + 300));
        }
        $market = MarketSession::describe($at);
        if ($market['is_trading_day'] && $at->lte(CarbonImmutable::parse($market['opens_at']))) {
            return CarbonImmutable::parse($market['closes_at'])->utc();
        }

        return $this->next($at, 'swing');
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
