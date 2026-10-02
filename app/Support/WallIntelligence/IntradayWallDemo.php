<?php

namespace App\Support\WallIntelligence;

use App\Services\IntradayWallTracker;
use Carbon\CarbonImmutable;

final class IntradayWallDemo
{
    public function build(string $symbol, string $timeframe = '14d'): array
    {
        abort_unless(app()->environment('local'), 404);
        $center = ['SPY' => 760, 'QQQ' => 720, 'TSLA' => 350][$symbol] ?? 100;
        $contracts = [];
        $expiry = $timeframe === '0d' ? '2026-09-29' : ($timeframe === '1d' ? '2026-09-30' : '2026-10-02');
        foreach ([-5 => ['put', 20000], 5 => ['call', 28000]] as $offset => [$type, $oi]) {
            $contracts[] = ['expiry' => $expiry, 'type' => $type, 'strike' => $center + $offset,
                'oi' => $oi, 'iv' => .12, 'data_date' => '2026-09-28', 'data_timestamp' => null];
        }
        $model = new IntradayWallModel;
        $basis = $model->basis($symbol, '2026-09-29', '2026-09-28', [$expiry], $contracts, $timeframe);
        $observations = [];
        $offsets = [0, -4, -5, -3, -5.8, -6.2, -5.1, -6.4, -7.2, -4, -3, 3, 4.7, 6, 7, 5.1, 6.5, 7.3];
        $bars = [];
        $previous = $center + 1;
        foreach ($offsets as $i => $offset) {
            $at = CarbonImmutable::parse('2026-09-29 10:00', 'America/New_York')->addMinutes($i * 5);
            $observations[] = $model->observe($basis, $previous, $at, 'synthetic_review');
            $close = $center + $offset;
            $bars[] = ['t' => $at->getTimestampMs(), 'o' => $previous, 'h' => max($previous, $close) + .03,
                'l' => min($previous, $close) - .03, 'c' => $close, 'v' => 1000];
            $previous = $close;
        }
        $observations[] = $model->observe($basis, $previous, $at->addMinutes(5), 'synthetic_review');
        $result = app(IntradayWallTracker::class)->response($symbol, '2026-09-29', $observations, $timeframe);
        $result['dataset'] = 'synthetic_review';
        $result['sessions'] = ['2026-09-29'];
        $result['local_demo_available'] = true;
        $result['truncated'] = false;
        $result['wall_interaction'] = (new WallInteractionDetector)->analyze($symbol, '2026-09-29', $timeframe,
            $observations, $bars, $at->addMinutes(5));
        $result['wall_interaction']['state'] = 'demonstration';

        return $result;
    }
}
