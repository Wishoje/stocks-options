<?php

namespace App\Support\WallIntelligence;

use App\Services\IntradayWallTracker;
use Carbon\CarbonImmutable;

final class IntradayWallDemo
{
    public function build(string $symbol): array
    {
        abort_unless(app()->environment('local'), 404);
        $center = ['SPY' => 760, 'QQQ' => 720, 'TSLA' => 350][$symbol] ?? 100;
        $contracts = [];
        foreach ([-10 => ['put', 20000], -5 => ['put', 15000], 5 => ['call', 19000], 10 => ['call', 28000]] as $offset => [$type, $oi]) {
            $contracts[] = ['expiry' => '2026-10-02', 'type' => $type, 'strike' => $center + $offset,
                'oi' => $oi, 'iv' => .12, 'data_date' => '2026-09-28', 'data_timestamp' => null];
        }
        $model = new IntradayWallModel;
        $basis = $model->basis($symbol, '2026-09-29', '2026-09-28', ['2026-10-02'], $contracts);
        $observations = [];
        foreach ([-9, -6, -3, 0, 4, 8, 11, 13] as $i => $offset) {
            $at = CarbonImmutable::parse('2026-09-29 10:00', 'America/New_York')->addMinutes($i * 5);
            $observations[] = $model->observe($basis, $center + $offset, $at, 'synthetic_review');
        }
        $result = app(IntradayWallTracker::class)->response($symbol, '2026-09-29', $observations);
        $result['dataset'] = 'synthetic_review';
        $result['sessions'] = ['2026-09-29'];
        $result['local_demo_available'] = true;
        $result['truncated'] = false;

        return $result;
    }
}
