<?php

namespace App\Support\Swing;

use Carbon\CarbonImmutable;

final class SwingReactionDemo
{
    public function build(string $horizon): array
    {
        abort_unless(app()->environment('local'), 403);
        $now = CarbonImmutable::now('UTC');
        $items = [];
        foreach (['watching', 'ready', 'entry_touched'] as $index => $status) {
            $items[] = ['id' => 'synthetic-swing-'.$index, 'symbol' => ['SPY', 'QQQ', 'TSLA'][$index],
                'horizon' => $horizon, 'source_date' => $now->subDay()->toDateString(),
                'pattern' => $index === 1 ? 'break_retest' : 'hold_reclaim', 'direction' => 'bullish', 'side' => 'put',
                'status' => $status, 'status_group' => $status, 'demonstration' => true,
                'levels' => ['wall' => 100., 'touch_low' => 99.8, 'touch_high' => 100.2, 'trigger' => 100.2,
                    'entry_low' => 100.2, 'entry_high' => 100.6, 'invalidation' => 98.5, 'invalidation_side' => 'below', 'target' => 106.],
                'evidence' => ['close' => 101., 'atr14' => 2., 'wall_relative_gex' => 4.2, 'target_room_ratio' => 2.57,
                    'net_gex' => -4000000., 'target_rolloff_pct' => 12.],
                'available_at' => $now->subHour()->toIso8601String(), 'entry_deadline' => $now->addDays(3)->toIso8601String(),
                'holding_deadline' => $status === 'entry_touched' ? $now->addDays(5)->toIso8601String() : null,
                'qualified_at' => $status !== 'watching' ? $now->subMinutes(45)->toIso8601String() : null,
                'entry_at' => $status === 'entry_touched' ? $now->subMinutes(30)->toIso8601String() : null,
                'entry_price' => $status === 'entry_touched' ? 100.6 : null,
                'latest_price' => ['value' => 101., 'at' => $now->subMinutes(20)->toIso8601String()],
                'reason' => $status === 'watching' ? 'Watch for a wall test and recovery.' : ($status === 'ready' ? 'The reaction is recorded. Watch for a later entry-area touch.' : 'The entry area was touched. Follow the target and stop levels.'),
            ];
        }

        return ['prepared' => true, 'demonstration' => true, 'items' => $items,
            'evaluatedAt' => $now->toIso8601String(), 'symbolCount' => 3];
    }
}
