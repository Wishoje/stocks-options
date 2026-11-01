<?php

namespace App\Support\SwingResearch;

use App\Support\MarketSession;
use App\Support\SetupStudy\SetupStudyMetrics;
use App\Support\Swing\SwingIdeaRules;
use Carbon\CarbonImmutable;

/** Synthetic interface examples. Never persisted, collected, or sent as alerts. */
final class SwingResearchDemo
{
    public function report(array $filters): array
    {
        abort_unless(app()->environment('local'), 403);
        $horizon = $filters['horizon'];
        $through = MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($filters['to'], 'America/New_York'));
        $date = $through;
        for ($i = 0; $i < 2; $i++) {
            $date = MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($date, 'America/New_York')->subDay());
        }
        $dates = [SwingIdeaRules::sessionAfter($date, 1), SwingIdeaRules::sessionAfter($date, 2)];
        $rules = new SwingResearchRules;
        $groups = [];
        foreach (['SPY', 'QQQ', 'NVDA'] as $index => $symbol) {
            if (($filters['symbol'] ?? '') !== '' && $filters['symbol'] !== $symbol) {
                continue;
            }
            if ($date < $filters['from'] || $date > $filters['to']) {
                continue;
            }
            $input = $this->input($symbol, $date, $horizon, $index === 0, $index === 2);
            $published = $this->close($date)->addHour();
            $bars = $index === 2
                ? [['date' => $dates[0], 'open' => 93.5, 'high' => 94.1, 'low' => 93., 'close' => 93.5], ['date' => $dates[1], 'open' => 93.5, 'high' => 97., 'low' => 93., 'close' => 96.]]
                : [['date' => $dates[0], 'open' => 106.5, 'high' => 107., 'low' => 105.9, 'close' => 106.5], ['date' => $dates[1], 'open' => 106.5, 'high' => 110., 'low' => 106.4, 'close' => $index === 1 ? 109. : 108.]];
            $cases = [];
            foreach (SwingResearchRules::variants() as $variant => $metadata) {
                $plan = $rules->propose($input, $horizon, $variant);
                $plan['activation_session'] = $dates[0];
                $plan['first_recorded_at'] = $published->toIso8601String();
                $plan['reviewed_through'] = $date;
                $state = $plan;
                $signalRecorded = null;
                foreach ($bars as $bar) {
                    $state = $rules->advance($state, $bar);
                    if (! $signalRecorded && ! empty($state['qualified_at'])) {
                        $signalRecorded = $this->close($bar['date'])->addHour()->toIso8601String();
                    }
                }
                $measurement = (new SetupStudyMetrics)->measure([
                    'mode' => 'swing', 'direction' => $state['direction'], 'native_status' => $state['status'],
                    'signal_at' => empty($state['qualified_at']) ? null : $this->close($state['qualified_at'])->toIso8601String(),
                    'signal_price' => $state['qualified_price'] ?? null, 'available_at' => $signalRecorded,
                    'invalidation' => $state['levels']['invalidation'], 'target' => $state['levels']['target'],
                    'entry_low' => $state['levels']['entry_low'], 'entry_high' => $state['levels']['entry_high'],
                    'review_until' => empty($state['deadline']) ? null : $this->close($state['deadline'])->toIso8601String(),
                    'bars' => array_map(fn ($bar) => [...$bar, 'at' => $this->close($bar['date'])->toIso8601String()], $bars),
                ], $this->close($through)->addHours(3));
                $measurement['metrics']['outcome_move_pct'] = null;
                if ($measurement['followup_complete'] && ! empty($state['qualified_price'])) {
                    foreach ($bars as $bar) {
                        if ($this->close($bar['date'])->toIso8601String() === $measurement['metrics']['strict_at']) {
                            $measurement['metrics']['outcome_move_pct'] = 100 * ($state['direction'] === 'bullish' ? 1 : -1)
                                * ($bar['close'] - $state['qualified_price']) / $state['qualified_price'];
                        }
                    }
                }
                $measurement['metrics']['initial_entry_distance_pct'] = 100 * max($plan['levels']['entry_low'] - $plan['evidence']['close'],
                    $plan['evidence']['close'] - $plan['levels']['entry_high'], 0) / $plan['evidence']['close'];
                $close = end($bars)['close'];
                $cases[] = ['id' => 'demo-'.$symbol.'-'.$horizon.'-'.$variant, 'variant' => $variant, 'label' => $metadata['label'],
                    'status' => $state['status'], 'state' => $state, 'original_target' => $plan['original_target'] ?? $plan['levels']['target'],
                    'latest_price' => ['date' => $through, 'close' => $close, 'distance_to_entry_pct' => 100 * max($state['levels']['entry_low'] - $close, $close - $state['levels']['entry_high'], 0) / $close],
                    'signal_recorded_at' => $signalRecorded, 'alert_read_at' => null,
                    'metrics' => [...$measurement['metrics'], 'qualified' => $measurement['qualified'],
                        'followup_complete' => $measurement['followup_complete'], 'needs_update' => $measurement['needs_update']],
                    'measurement' => $measurement];
            }
            $groups[] = ['id' => 'demo-'.$symbol.'-'.$horizon, 'symbol' => $symbol, 'horizon' => $horizon,
                'source_date' => $date, 'created_at' => $published->toIso8601String(), 'cases' => $cases];
        }
        $summary = [];
        foreach (SwingResearchRules::variants() as $variant => $metadata) {
            $cases = array_values(array_filter(array_merge(...array_column($groups, 'cases')), fn ($case) => $case['variant'] === $variant));
            $complete = array_values(array_filter($cases, fn ($case) => $case['measurement']['followup_complete'] && ! $case['measurement']['needs_update']));
            $targets = count(array_filter($complete, fn ($case) => $case['metrics']['strict_outcome'] === 'target_reached'));
            $summary[] = ['variant' => $variant, 'total' => count($cases), 'watching' => count(array_filter($cases, fn ($case) => $case['status'] === 'watching')),
                'qualified' => count(array_filter($cases, fn ($case) => $case['measurement']['qualified'])),
                'in_progress' => count(array_filter($cases, fn ($case) => in_array($case['status'], ['conditions_met', 'in_progress'], true))),
                'completed' => count($complete), 'needs_review' => 0, 'target_reached' => $targets,
                'invalidated' => count(array_filter($complete, fn ($case) => $case['metrics']['strict_outcome'] === 'invalidated')),
                'ended' => count(array_filter($complete, fn ($case) => $case['metrics']['strict_outcome'] === 'holding_ended')),
                'target_rate' => ['hits' => $targets, 'eligible' => count($complete), 'rate' => $complete ? round(100 * $targets / count($complete), 1) : null],
                'avg_mfe_pct' => $complete ? round(array_sum(array_column(array_column($complete, 'metrics'), 'mfe_pct')) / count($complete), 3) : null,
                'avg_mae_pct' => $complete ? round(array_sum(array_column(array_column($complete, 'metrics'), 'mae_pct')) / count($complete), 3) : null,
                'avg_initial_entry_distance_pct' => $cases ? round(array_sum(array_column(array_column($cases, 'metrics'), 'initial_entry_distance_pct')) / count($cases), 3) : null,
                'avg_outcome_move_pct' => $complete ? round(array_sum(array_column(array_column($complete, 'metrics'), 'outcome_move_pct')) / count($complete), 3) : null];
        }
        foreach ($groups as &$group) {
            foreach ($group['cases'] as &$case) {
                unset($case['measurement']);
            }
        }

        return ['prepared' => true, 'enabled' => false, 'demonstration' => true, 'filters' => $filters,
            'variants' => SwingResearchRules::variants(), 'summary' => $summary, 'groups' => $groups,
            'collection' => [], 'warnings' => [], 'generated_at' => now('UTC')->toIso8601String()];
    }

    private function input(string $symbol, string $date, string $horizon, bool $expanded, bool $bearish): array
    {
        $dates = [];
        $day = CarbonImmutable::parse($date, 'America/New_York');
        while (count($dates) < 70) {
            if (MarketSession::isTradingDay($day)) {
                array_unshift($dates, $day->toDateString());
            }
            $day = $day->subDay();
        }
        $prices = [];
        foreach ($dates as $i => $value) {
            $close = 100 + $i * .1;
            $prices[] = ['date' => $value, 'open' => $close, 'high' => $close + 1, 'low' => $close - 1, 'close' => $close];
        }
        $expiry = CarbonImmutable::parse($date)->addDays($horizon === 'short' ? 28 : 60)->toDateString();
        $cell = fn ($net) => ['expiration' => $expiry, 'data_date' => $date, 'net_gex' => $net,
            'known_oi' => 10000., 'covered_oi' => 10000., 'contract_rows' => 2, 'excluded_rows' => 0];
        $strikes = [['strike' => 106., 'expirations' => [$cell(-7000000.)]], ['strike' => 111., 'expirations' => [$cell(6000000.)]]];
        if ($expanded) {
            foreach ([103., 102., 101.] as $i => $strike) {
                $strikes[] = ['strike' => $strike, 'expirations' => [$cell(-10000000. + $i * 1000000.)]];
            }
        }
        if ($bearish) {
            foreach ($prices as &$bar) {
                [$bar['high'], $bar['low']] = [200 - $bar['low'], 200 - $bar['high']];
                $bar['open'] = 200 - $bar['open'];
                $bar['close'] = 200 - $bar['close'];
            }
            foreach ($strikes as &$strike) {
                $strike['strike'] = 200 - $strike['strike'];
                $strike['expirations'][0]['net_gex'] *= -1;
            }
        }

        return ['symbol' => $symbol, 'data_date' => $date, 'prices' => $prices, 'strikes' => $strikes];
    }

    private function close(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(MarketSession::describe(CarbonImmutable::parse($date.' 12:00', 'America/New_York'))['closes_at'])->utc();
    }
}
