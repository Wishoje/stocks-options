<?php

namespace App\Services;

use App\Support\EodSnapshotSelector;
use App\Support\WallIntelligence\WallSnapshotContract;
use Illuminate\Support\Facades\DB;

class GexExpiryStrikeService
{
    public function build(array $levels): array
    {
        $expiries = array_values(array_unique($levels['expiration_dates'] ?? []));
        sort($expiries);
        abort_if(! ($levels['data_date'] ?? null) || ! $expiries, 404, 'Choose an EOD expiry scope to explore exposure.');
        $ids = DB::table('option_expirations')->where('symbol', $levels['symbol'])
            ->whereIn('expiration_date', $expiries)->pluck('expiration_date', 'id')->all();
        abort_if(count($ids) !== count($expiries), 409, 'Refresh the dashboard to update the expiration map.');
        $selected = app(EodSnapshotSelector::class)->selectedDatesSubquery(array_keys($ids), $levels['view_context']['source_anchor'] ?? $levels['data_date']);
        // Match the published strike chart's stored-Greek convention, normalized to USD / 1%.
        $gex = 'COALESCE(o.gamma, 0) * COALESCE(o.open_interest, 0) * CASE WHEN o.underlying_price > 0 THEN o.underlying_price * o.underlying_price ELSE 1 END';
        $invalid = 'o.open_interest IS NULL OR o.open_interest < 0 OR (o.open_interest > 0 AND (o.gamma IS NULL OR o.gamma < 0 OR o.underlying_price IS NULL OR o.underlying_price <= 0))';
        $rows = DB::table('option_chain_data as o')->joinSub($selected, 'selected', function ($join) {
            $join->on('o.expiration_id', '=', 'selected.expiration_id')->on('o.data_date', '=', 'selected.max_date');
        })->whereIn('o.expiration_id', array_keys($ids))
            ->select('o.expiration_id', 'o.strike', 'o.data_date')
            ->selectRaw("SUM(CASE WHEN o.option_type = 'call' THEN $gex ELSE 0 END) as call_gex")
            ->selectRaw("SUM(CASE WHEN o.option_type = 'put' THEN $gex ELSE 0 END) as put_gex")
            ->selectRaw('COUNT(*) as contract_rows, SUM(CASE WHEN o.open_interest > 0 THEN o.open_interest ELSE 0 END) as known_oi')
            ->selectRaw("SUM(CASE WHEN $invalid THEN 1 ELSE 0 END) as excluded_rows")
            ->selectRaw("SUM(CASE WHEN $invalid THEN 0 ELSE COALESCE(o.open_interest, 0) END) as covered_oi")
            ->groupBy('o.expiration_id', 'o.strike', 'o.data_date')->orderBy('o.strike')->orderBy('o.expiration_id')->get();

        $strikes = [];
        $totals = array_fill_keys($expiries, ['call_gex' => 0., 'put_gex' => 0., 'net_gex' => 0., 'absolute_net_gex' => 0.]);
        foreach ($rows as $row) {
            $strike = WallSnapshotContract::number($row->strike);
            $call = WallSnapshotContract::number($row->call_gex);
            $put = WallSnapshotContract::number($row->put_gex);
            abort_if($strike === null || $call === null || $put === null, 409, 'Refresh the dashboard to update the expiration map.');
            $key = (string) $strike;
            $expiry = (string) $ids[$row->expiration_id];
            $net = $call - $put;
            $strikes[$key] ??= ['strike' => $strike, 'call_gex' => 0., 'put_gex' => 0., 'net_gex' => 0., 'expirations' => []];
            $cell = ['expiration' => $expiry, 'data_date' => (string) $row->data_date,
                'call_gex' => $call, 'put_gex' => $put, 'net_gex' => $net,
                'contract_rows' => (int) $row->contract_rows, 'excluded_rows' => (int) $row->excluded_rows,
                'known_oi' => (float) $row->known_oi, 'covered_oi' => (float) $row->covered_oi];
            $strikes[$key]['expirations'][] = $cell;
            foreach (['call_gex', 'put_gex', 'net_gex'] as $field) {
                $strikes[$key][$field] += $cell[$field];
                $totals[$expiry][$field] += $cell[$field];
            }
            $totals[$expiry]['absolute_net_gex'] += abs($net);
        }
        // Reject a different generation, including offsetting leg changes with unchanged net.
        abort_if(count($strikes) !== count($levels['strike_data'] ?? []), 409, 'Refresh the dashboard to update the expiration map.');
        foreach ($levels['strike_data'] ?? [] as $row) {
            $actual = $strikes[(string) (float) $row['strike']] ?? null;
            foreach (['call_gex', 'put_gex', 'net_gex'] as $field) {
                $expected = WallSnapshotContract::number($row[$field] ?? null);
                $expected = $expected === null ? null : $expected * WallSnapshotContract::GEX_FACTOR;
                abort_if($actual === null || $expected === null || abs($actual[$field] - $expected) > max(.01, abs($expected) * 1e-8), 409, 'Refresh the dashboard to update the expiration map.');
            }
        }
        $next = $expiries[0];
        foreach ($strikes as &$strike) {
            usort($strike['expirations'], fn ($a, $b) => strcmp($a['expiration'], $b['expiration']));
            $cells = $strike['expirations'];
            $absolute = array_sum(array_map(fn ($cell) => abs($cell['net_gex']), $cells));
            $known = array_sum(array_column($cells, 'known_oi'));
            $covered = array_sum(array_column($cells, 'covered_oi'));
            $excluded = array_sum(array_column($cells, 'excluded_rows'));
            $count = array_sum(array_column($cells, 'contract_rows'));
            $dates = array_values(array_unique(array_column($cells, 'data_date')));
            $aligned = $dates === [$levels['data_date']];
            $eligible = $aligned && $known > 0 && $covered / $known >= .98 && $excluded / max(1, $count) <= .1;
            $reason = ! $aligned ? 'mixed_source_dates' : (! $eligible ? 'coverage_gate' : ($absolute == 0 ? 'zero_denominator' : null));
            $dominant = null;
            foreach ($cells as $cell) {
                if ($dominant === null || abs($cell['net_gex']) > abs($dominant['net_gex'])) {
                    $dominant = $cell;
                }
            }
            $strike['absolute_net_gex'] = $absolute;
            $strike['wall_expiry_concentration'] = $reason === null ? abs($dominant['net_gex']) / $absolute : null;
            $strike['dominant_expiry'] = $reason === null ? $dominant['expiration'] : null;
            $strike['expiring_next_ratio'] = $reason === null ? array_sum(array_map(fn ($cell) => $cell['expiration'] === $next ? abs($cell['net_gex']) : 0, $cells)) / $absolute : null;
            $strike['contributing_expiries'] = count(array_filter($cells, fn ($cell) => $cell['net_gex'] != 0));
            $strike['concentration_reason'] = $reason;
            foreach ($strike['expirations'] as &$cell) {
                $cell['contribution_pct'] = $reason === null ? 100 * abs($cell['net_gex']) / $absolute : null;
            }
            unset($cell);
        }
        unset($strike);
        $list = array_values($strikes);
        $putWall = $callWall = null;
        foreach ($list as $strike) {
            if ($strike['net_gex'] < 0 && ($putWall === null || $strike['net_gex'] < $putWall['net_gex'])) {
                $putWall = $strike;
            }
            if ($strike['net_gex'] > 0 && ($callWall === null || $strike['net_gex'] > $callWall['net_gex'])) {
                $callWall = $strike;
            }
        }
        $close = WallSnapshotContract::number(DB::table('prices_daily')->where('symbol', $levels['symbol'])->where('trade_date', $levels['data_date'])->value('close'));

        return ['schema_version' => 'gex-expiry-strike.v1', 'model_version' => 'stored-greeks-expiry-attribution.v1',
            'symbol' => $levels['symbol'], 'timeframe' => $levels['timeframe'], 'data_date' => $levels['data_date'],
            'view_context' => $levels['view_context'] ?? null, 'expiration_dates' => $expiries,
            'unit' => 'USD per 1% underlying move', 'inventory_convention' => 'call_minus_put',
            'legacy_gex_factor' => WallSnapshotContract::GEX_FACTOR,
            'reference_price' => ['value' => $close !== null && $close > 0 ? $close : null, 'date' => $levels['data_date'], 'kind' => 'daily_close', 'live' => false],
            'next_expiry_dates' => [$next], 'strikes' => $list,
            'expiry_totals' => array_map(fn ($expiry) => ['expiration' => $expiry, ...$totals[$expiry]], $expiries),
            'summary' => ['net_gex' => array_sum(array_column($list, 'net_gex')), 'strike_count' => count($list),
                'cell_count' => $rows->count(), 'put_wall' => $putWall['strike'] ?? null, 'call_wall' => $callWall['strike'] ?? null],
            'audit' => ['generated_at' => now()->toIso8601String(), 'historical_outcome_eligible' => false,
                'concentration_denominator' => 'sum_abs_net_gex_across_expirations_at_same_strike',
                'concentration_min_known_oi_ratio' => .98, 'concentration_max_excluded_row_ratio' => .1,
                'absent_cell' => 'no_selected_contract_rows', 'truncated' => false],
            'interpretation' => 'Expiry concentration describes exposure distribution and sensitivity to an expiry rolling off. It does not establish a hold, break, direction, or trade entry.'];
    }
}
