<?php

namespace App\Support\SetupStudy;

use App\Services\SetupStudy\SetupStudyService;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;

/** Local interface examples only; nothing here is persisted or dispatched. */
final class SetupStudyDemo
{
    public function report(array $filters): array
    {
        abort_unless(app()->environment('local'), 403);
        $intraday = $filters['cohort'] === 'intraday';
        $day = MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($filters['to'].' 12:00', 'America/New_York'));
        $end = CarbonImmutable::parse(MarketSession::describe(CarbonImmutable::parse($day.' 12:00', 'America/New_York'))['closes_at']);
        $times = [];
        $cursor = $end;
        for ($i = 0; $i < 4; $i++) {
            array_unshift($times, $cursor);
            $date = MarketSession::tradingDateOnOrBefore($cursor->setTimezone('America/New_York')->subDay());
            $cursor = $intraday ? $cursor->subMinutes(5) : CarbonImmutable::parse(MarketSession::describe(CarbonImmutable::parse($date.' 12:00', 'America/New_York'))['closes_at']);
        }
        $rows = [];
        foreach ([['SPY', [101., 103., 104., 104.]], ['QQQ', [100.6, 101.1, 98.8, 105.]],
            ['TSLA', [100.7, 100.4, 100.3, 100.2]], ['IWM', []], ['NVDA', [101.]]] as $i => [$symbol, $closes]) {
            $sample = ['id' => hash('sha256', 'demonstration-'.$filters['cohort'].$symbol), 'mode' => $intraday ? 'intraday' : 'swing',
                'horizon' => $intraday ? 'session' : ($filters['cohort'] === 'swing_short' ? 'short' : 'position'),
                'symbol' => $symbol, 'session' => $cursor->setTimezone('America/New_York')->toDateString(), 'timeframe' => $intraday ? '7d' : ($filters['cohort'] === 'swing_position' ? '90d' : '30d'),
                'rule_version' => 'synthetic-example', 'direction' => 'bullish', 'side' => 'put', 'pattern' => $intraday ? ($i % 2 ? 'reclaim' : 'reversal') : 'wall_pullback_reclaim',
                'signal_at' => $i === 3 ? null : $cursor->toIso8601String(), 'signal_price' => $i === 3 ? null : 100.,
                'available_at' => $cursor->addMinutes(15)->toIso8601String(), 'entry_low' => 99.8, 'entry_high' => 100.2,
                'invalidation' => 99., 'invalidation_side' => 'below', 'target' => 103., 'review_until' => $end->toIso8601String(),
                'native_status' => $i === 3 ? 'watching' : 'conditions_met', 'native_outcome' => null,
                'confirmations' => $i === 3 ? [] : [$cursor->toIso8601String()], 'bars' => [],
                'evidence' => ['example' => 'Synthetic prices for interface review only'], 'source' => ['demonstration' => true]];
            foreach ($closes as $index => $close) {
                if ($i === 1) {
                    $close = 200 - $close;
                }
                $sample['bars'][] = ['at' => $times[$index]->toIso8601String(), 'open' => $close, 'high' => $close + .1, 'low' => $close - .1, 'close' => $close];
            }
            if ($i === 1) {
                $sample = [...$sample, 'direction' => 'bearish', 'side' => 'call', 'invalidation' => 101., 'invalidation_side' => 'above', 'target' => 97.];
            }
            $rows[] = (new SetupStudyMetrics)->measure($sample, $end->addHour());
        }
        $rows = array_values(array_filter($rows, fn ($row) => (empty($filters['symbol']) || $row['symbol'] === $filters['symbol'])
            && (empty($filters['direction']) || $row['direction'] === $filters['direction'])));
        $breakdowns = [];
        foreach (['symbol' => 'symbols', 'pattern' => 'patterns'] as $field => $name) {
            $groups = [];
            foreach ($rows as $row) {
                $groups[$row[$field]][] = $row;
            }
            $breakdowns[$name] = [];
            foreach ($groups as $label => $items) {
                $breakdowns[$name][] = ['label' => $label, ...(new SetupStudyService)->summarize($items)];
            }
        }
        $report = (new SetupStudyService)->report(0, $filters);

        return [...$report, 'demonstration' => true, 'prepared' => true, 'warnings' => [],
            'last_collected_at' => $end->toIso8601String(), 'rows' => $rows,
            'summary' => (new SetupStudyService)->summarize($rows), 'breakdowns' => $breakdowns];
    }
}
