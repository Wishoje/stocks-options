<?php

namespace App\Support\IntradayResearch;

use App\Support\MarketSession;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;

/** Invented interface examples. Never persisted, collected, or sent as alerts. */
final class IntradayResearchDemo
{
    public function report(array $filters): array
    {
        abort_unless(app()->environment('local'), 403);
        $session = MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($filters['to'], 'America/New_York'));
        $calendar = MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York'));
        $open = CarbonImmutable::parse($calendar['opens_at'])->utc();
        $end = CarbonImmutable::parse($calendar['closes_at'])->utc();
        $created = $open->addMinutes(30)->addSeconds(30);
        $groups = [];
        foreach (['SPY', 'QQQ', 'NVDA'] as $index => $symbol) {
            if ($session < $filters['from'] || $session > $filters['to']
                || (($filters['symbol'] ?? '') !== '' && $filters['symbol'] !== $symbol)) {
                continue;
            }
            $base = [600., 500., 140.][$index];
            $bearish = $symbol === 'QQQ';
            $sample = ['id' => 'demo-'.$symbol.'-'.$session, 'mode' => 'intraday', 'symbol' => $symbol,
                'session' => $session, 'timeframe' => '7d', 'direction' => $bearish ? 'bearish' : 'bullish',
                'side' => $bearish ? 'call' : 'put', 'pattern' => 'reversal', 'rule_version' => WallSetupRules::VERSION,
                'signal_at' => $open->addMinutes(15)->toIso8601String(), 'available_at' => $open->addMinutes(30)->toIso8601String(),
                'entry_expires_at' => $open->addMinutes(30)->toIso8601String(), 'review_until' => $end->toIso8601String(),
                'signal_price' => $base + 1, 'entry_low' => $base, 'entry_high' => $base + 2,
                'invalidation' => $bearish ? $base + 3 : $base - 1, 'target' => $bearish ? $base - 3 : $base + 5,
                'review_basis' => 'saved_assessment'];
            $bars = $this->bars($open, $end, $symbol, $base);
            $cases = [];
            foreach (IntradayResearchRules::variants() as $variant => $metadata) {
                $plan = IntradayResearchRules::create($sample, $created, $variant);
                $state = IntradayResearchRules::evaluate($plan, $bars, $end->addMinutes(30));
                $cases[] = ['id' => 'demo-'.$symbol.'-'.$session.'-'.$variant, 'variant' => $variant, 'label' => $metadata['label'],
                    'status' => $state['status'], 'state' => $state, 'notification_recorded_at' => null, 'alert_read_at' => null];
            }
            $groups[] = ['id' => 'demo-'.$symbol.'-'.$session, 'symbol' => $symbol, 'session' => $session,
                'created_at' => $created->toIso8601String(), 'source_signal_at' => $sample['signal_at'],
                'source_available_at' => $sample['available_at'], 'cases' => $cases];
        }

        return ['prepared' => true, 'enabled' => false, 'demonstration' => true, 'filters' => $filters,
            'variants' => IntradayResearchRules::variants(), 'summary' => $this->summary($groups), 'groups' => $groups,
            'collection' => [], 'warnings' => ['Synthetic examples use invented prices. No research records or alerts are saved.']];
    }

    private function bars(CarbonImmutable $open, CarbonImmutable $end, string $symbol, float $base): array
    {
        $bars = [];
        $through = $symbol === 'NVDA' ? $end : $open->addMinutes(50);
        for ($at = $open->addMinutes(20); $at <= $through; $at = $at->addMinutes(5)) {
            $minutes = (int) $open->diffInMinutes($at);
            $close = match (true) {
                $symbol === 'NVDA' => $base + 2.5,
                $minutes === 50 => $base + ($symbol === 'SPY' ? 5 : 4),
                $minutes === 45 => $base + ($symbol === 'SPY' ? 3 : 0),
                default => $base + 1,
            };
            $bars[] = ['at' => $at->toIso8601String(), 'open' => $close, 'high' => $close + .1,
                'low' => $close - .1, 'close' => $close];
        }

        return $bars;
    }

    private function summary(array $groups): array
    {
        $summary = [];
        foreach (array_keys(IntradayResearchRules::variants()) as $variant) {
            $items = [];
            foreach ($groups as $group) {
                foreach ($group['cases'] as $case) {
                    if ($case['variant'] === $variant) {
                        $items[] = $case['state'];
                    }
                }
            }
            $completed = array_values(array_filter($items, fn ($state) => ($state['metrics']['followup_complete'] ?? false)
                && ! ($state['metrics']['needs_update'] ?? false) && ! ($state['metrics']['evidence_gap'] ?? false) && $state['status'] !== 'needs_update'));
            $countOutcome = fn ($outcome) => count(array_filter($completed, fn ($state) => ($state['outcome'] ?? null) === $outcome));
            $hits = $countOutcome('target_reached');
            $average = function ($key) use ($completed) {
                $values = array_values(array_filter(array_map(fn ($state) => $state['metrics'][$key] ?? null, $completed),
                    fn ($value) => is_numeric($value) && is_finite((float) $value)));

                return $values ? round(array_sum($values) / count($values), 3) : null;
            };
            $summary[] = ['variant' => $variant, 'total' => count($items),
                'qualified' => count(array_filter($items, fn ($state) => (bool) ($state['metrics']['qualified'] ?? false))),
                'waiting' => count(array_filter($items, fn ($state) => in_array($state['status'], ['watching', 'waiting'], true))),
                'not_qualified' => count(array_filter($items, fn ($state) => $state['status'] === 'not_qualified')),
                'in_progress' => count(array_filter($items, fn ($state) => in_array($state['status'], ['conditions_met', 'in_progress'], true))),
                'completed' => count($completed), 'needs_review' => count(array_filter($items, fn ($state) => ($state['metrics']['needs_update'] ?? false) || $state['status'] === 'needs_update')),
                'target_rate' => ['hits' => $hits, 'eligible' => count($completed), 'rate' => $completed ? round(100 * $hits / count($completed), 1) : null],
                'target_reached' => $hits, 'invalidated' => $countOutcome('invalidated'),
                'ended' => $countOutcome('session_ended') + $countOutcome('holding_ended'), 'avg_outcome_move_pct' => $average('outcome_move_pct'),
                'avg_mfe_pct' => $average('mfe_pct'), 'avg_mae_pct' => $average('mae_pct')];
        }

        return $summary;
    }
}
