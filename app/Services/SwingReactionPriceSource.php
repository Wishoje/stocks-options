<?php

namespace App\Services;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pins existing completed five-minute observations. No market-data requests. */
class SwingReactionPriceSource
{
    public function capture(string $symbol, CarbonImmutable $now): array
    {
        if (! Schema::hasTable('wall_observations')) {
            return ['pinned' => 0, 'conflicts' => []];
        }
        $latest = DB::table('wall_observations')->where('symbol', $symbol)
            ->where('dataset', 'intraday_price_bars')->where('schema_version', 'wall-price-bars.v1')
            ->where('recorded_at', '<=', $now->toDateTimeString())
            ->where('analysis_session', '>=', $now->subDays(3)->toDateString())
            ->selectRaw('MAX(id) as id')->groupBy('analysis_session');
        $ids = $latest->pluck('id')->all();
        sort($ids);
        $key = 'swing:reaction:prices:v2:'.$symbol;
        if (Cache::get($key) === $ids) {
            return ['pinned' => 0, 'conflicts' => []];
        }
        $bundles = DB::table('wall_observations')->whereIn('id', $ids)
            ->get(['payload_json', 'recorded_at', 'analysis_session']);
        $writes = [];
        foreach ($bundles as $bundle) {
            if (strlen($bundle->payload_json) > 200000) {
                continue;
            }
            $data = json_decode($bundle->payload_json, true);
            if (! is_array($data) || ($data['symbol'] ?? null) !== $symbol
                || ! empty($data['demonstration']) || ($data['source'] ?? null) !== 'massive_5m_unadjusted') {
                continue;
            }
            $calendar = MarketSession::describe(CarbonImmutable::parse($bundle->analysis_session.' 12:00', 'America/New_York'));
            $open = strtotime($calendar['opens_at'] ?? '') ?: PHP_INT_MAX;
            $end = strtotime($calendar['closes_at'] ?? '') ?: 0;
            $available = CarbonImmutable::parse($bundle->recorded_at, 'UTC');
            $cutoff = min($now->subSeconds(930)->getTimestamp(), $available->getTimestamp(), $end);
            $counts = array_count_values(array_map(fn ($bar) => (string) ($bar['t'] ?? ''), $data['bars'] ?? []));
            foreach ($data['bars'] ?? [] as $bar) {
                $t = $bar['t'] ?? null;
                if (! is_numeric($t) || (int) $t != $t || (int) $t % 300000 !== 0 || ($counts[(string) $t] ?? 0) !== 1) {
                    continue;
                }
                $start = (int) ($t / 1000);
                if ($start < $open || $start + 300 > $cutoff) {
                    continue;
                }
                foreach (['o', 'h', 'l', 'c'] as $field) {
                    if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field]) || $bar[$field] <= 0) {
                        continue 2;
                    }
                }
                if ($bar['l'] > min($bar['o'], $bar['c']) || $bar['h'] < max($bar['o'], $bar['c'])) {
                    continue;
                }
                $values = array_map(fn ($v) => round((float) $v, 8), array_intersect_key($bar, array_flip(['o', 'h', 'l', 'c'])));
                $writes[] = ['symbol' => $symbol, 'bar_at' => gmdate('Y-m-d H:i:s', $start + 300),
                    'open' => $values['o'], 'high' => $values['h'], 'low' => $values['l'], 'close' => $values['c'],
                    'first_recorded_at' => $available->toDateTimeString(),
                    'evidence_hash' => hash('sha256', json_encode([$values['o'], $values['h'], $values['l'], $values['c']]))];
            }
        }
        $existing = $writes ? DB::table('swing_reaction_prices')->where('symbol', $symbol)
            ->whereBetween('bar_at', [min(array_column($writes, 'bar_at')), max(array_column($writes, 'bar_at'))])
            ->pluck('evidence_hash', 'bar_at')->all() : [];
        $conflicts = [];
        foreach ($writes as $row) {
            if (isset($existing[$row['bar_at']]) && $existing[$row['bar_at']] !== $row['evidence_hash']) {
                $conflicts[] = $row['bar_at'];
            }
        }
        $inserted = 0;
        $new = array_values(array_filter($writes, fn ($row) => ! isset($existing[$row['bar_at']])));
        foreach (array_chunk($new, 200) as $chunk) {
            $inserted += DB::table('swing_reaction_prices')->insertOrIgnore($chunk);
        }
        Cache::put($key, $ids, $now->addHours(6));

        return ['pinned' => $inserted, 'conflicts' => $conflicts];
    }

    public function bars(string $symbol, CarbonImmutable $from, CarbonImmutable $now): array
    {
        return DB::table('swing_reaction_prices')->where('symbol', $symbol)
            ->where('bar_at', '>', $from->toDateTimeString())->where('bar_at', '<=', $now->subSeconds(930)->toDateTimeString())
            ->orderBy('bar_at')->limit(4000)->get()->map(fn ($bar) => [
                'at' => CarbonImmutable::parse($bar->bar_at, 'UTC')->toIso8601String(),
                'start_at' => CarbonImmutable::parse($bar->bar_at, 'UTC')->subMinutes(5)->toIso8601String(),
                'open' => (float) $bar->open, 'high' => (float) $bar->high, 'low' => (float) $bar->low,
                'close' => (float) $bar->close, 'recorded_at' => CarbonImmutable::parse($bar->first_recorded_at, 'UTC')->toIso8601String(),
                'evidence_hash' => $bar->evidence_hash,
            ])->all();
    }
}
