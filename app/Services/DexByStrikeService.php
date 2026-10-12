<?php

namespace App\Services;

use App\Support\EodSnapshotSelector;
use App\Support\WallIntelligence\WallSnapshotContract;
use Illuminate\Support\Facades\DB;

class DexByStrikeService
{
    public function build(array $levels): array
    {
        $expiries = array_values(array_unique($levels['expiration_dates'] ?? []));
        sort($expiries);
        abort_if(! ($levels['data_date'] ?? null) || ! $expiries, 404, 'Choose an EOD expiry scope to explore delta exposure.');
        $ids = DB::table('option_expirations')->where('symbol', $levels['symbol'])
            ->whereIn('expiration_date', $expiries)->pluck('expiration_date', 'id')->all();
        $message = 'The levels have updated. Refresh the dashboard to compare delta exposure.';
        abort_if(count($ids) !== count($expiries), 409, $message);
        $selected = app(EodSnapshotSelector::class)->selectedDatesSubquery(array_keys($ids), $levels['view_context']['source_anchor'] ?? $levels['data_date']);
        // Match positioning's signed delta convention. Puts are already negative.
        // Zero OI has a known zero contribution even if delta was not supplied.
        $valid = "o.option_type IN ('call', 'put') AND o.open_interest >= 0 AND (o.open_interest = 0 OR (o.delta IS NOT NULL AND ((o.option_type = 'call' AND o.delta BETWEEN 0 AND 1) OR (o.option_type = 'put' AND o.delta BETWEEN -1 AND 0))))";
        $gex = 'COALESCE(o.gamma, 0) * COALESCE(o.open_interest, 0) * CASE WHEN o.underlying_price > 0 THEN o.underlying_price * o.underlying_price ELSE 1 END';
        $rows = DB::table('option_chain_data as o')->joinSub($selected, 'selected', function ($join) {
            $join->on('o.expiration_id', '=', 'selected.expiration_id')->on('o.data_date', '=', 'selected.max_date');
        })->whereIn('o.expiration_id', array_keys($ids))
            ->select('o.strike', 'o.expiration_id', 'o.data_date', 'o.option_type')
            ->selectRaw("SUM(CASE WHEN $valid THEN COALESCE(o.delta, 0) * o.open_interest * 100 ELSE 0 END) as dex")
            ->selectRaw("SUM(CASE WHEN $valid THEN 1 ELSE 0 END) as usable_rows")
            ->selectRaw("SUM(CASE WHEN $valid THEN o.open_interest ELSE 0 END) as covered_oi")
            ->selectRaw('COUNT(*) as contract_rows, SUM(CASE WHEN o.open_interest > 0 THEN o.open_interest ELSE 0 END) as known_oi')
            ->selectRaw("SUM($gex) as gex")
            ->groupBy('o.strike', 'o.expiration_id', 'o.data_date', 'o.option_type')->orderBy('o.strike')->get();

        $strikes = [];
        $dates = [];
        foreach ($rows as $row) {
            $strike = WallSnapshotContract::number($row->strike);
            $dex = WallSnapshotContract::number($row->dex);
            $exposure = WallSnapshotContract::number($row->gex);
            abort_if($strike === null || $dex === null || $exposure === null, 409, $message);
            $key = (string) $strike;
            $strikes[$key] ??= ['strike' => $strike, 'call_dex' => 0., 'put_dex' => 0., 'net_dex' => 0.,
                'call_gex' => 0., 'put_gex' => 0., 'net_gex' => 0.,
                'audit' => ['contract_rows' => 0, 'usable_rows' => 0, 'known_oi' => 0., 'covered_oi' => 0.,
                    'call_rows' => 0, 'put_rows' => 0, 'call_usable_rows' => 0, 'put_usable_rows' => 0]];
            $entry = &$strikes[$key];
            foreach (['contract_rows', 'usable_rows', 'known_oi', 'covered_oi'] as $field) {
                $entry['audit'][$field] += (float) $row->$field;
            }
            if (in_array($row->option_type, ['call', 'put'], true)) {
                $side = $row->option_type;
                $entry[$side.'_dex'] += $dex;
                $entry[$side.'_gex'] += $exposure;
                $entry['audit'][$side.'_rows'] += (int) $row->contract_rows;
                $entry['audit'][$side.'_usable_rows'] += (int) $row->usable_rows;
            }
            $dates[(string) $ids[$row->expiration_id]] = (string) $row->data_date;
            unset($entry);
        }
        abort_if(count($strikes) !== count($levels['strike_data'] ?? []), 409, $message);
        foreach ($strikes as &$row) {
            $row['net_gex'] = $row['call_gex'] - $row['put_gex'];
            $row['net_dex'] = $row['audit']['usable_rows'] > 0 ? $row['call_dex'] + $row['put_dex'] : null;
            foreach (['call', 'put'] as $side) {
                if ($row['audit'][$side.'_rows'] > 0 && $row['audit'][$side.'_usable_rows'] === 0) {
                    $row[$side.'_dex'] = null;
                }
            }
            $row['audit']['excluded_rows'] = (int) ($row['audit']['contract_rows'] - $row['audit']['usable_rows']);
            $row['audit']['calculation_status'] = $row['audit']['usable_rows'] == 0 ? 'unavailable'
                : ($row['audit']['excluded_rows'] > 0 ? 'available_inputs_only' : 'complete');
        }
        unset($row);
        // The adjacent GEX values must match the selected dashboard generation,
        // including offsetting changes that would leave only net GEX unchanged.
        foreach ($levels['strike_data'] ?? [] as $row) {
            $actual = $strikes[(string) (float) $row['strike']] ?? null;
            foreach (['call_gex', 'put_gex', 'net_gex'] as $field) {
                $expected = WallSnapshotContract::number($row[$field] ?? null);
                $expected = $expected === null ? null : $expected * WallSnapshotContract::GEX_FACTOR;
                abort_if($actual === null || $expected === null || abs($actual[$field] - $expected) > max(.01, abs($expected) * 1e-8), 409, $message);
            }
        }
        $list = array_values($strikes);
        $sum = static function (string $field) use ($list): ?float {
            $values = array_filter(array_column($list, $field), fn ($value) => $value !== null);

            return count($values) ? array_sum($values) : null;
        };
        $positive = $negative = null;
        foreach ($list as $row) {
            if ($row['net_dex'] > 0 && ($positive === null || $row['net_dex'] > $positive['net_dex'])) {
                $positive = $row;
            }
            if ($row['net_dex'] !== null && $row['net_dex'] < 0 && ($negative === null || $row['net_dex'] < $negative['net_dex'])) {
                $negative = $row;
            }
        }
        ksort($dates);
        $close = WallSnapshotContract::number(DB::table('prices_daily')->where('symbol', $levels['symbol'])->where('trade_date', $levels['data_date'])->value('close'));

        return ['schema_version' => 'dex-by-strike.v1', 'model_version' => 'stored-signed-delta-oi.v1',
            'symbol' => $levels['symbol'], 'timeframe' => $levels['timeframe'], 'data_date' => $levels['data_date'],
            'view_context' => $levels['view_context'] ?? null, 'expiration_dates' => $expiries,
            'dex_unit' => 'share equivalents', 'gex_unit' => 'USD per 1% underlying move',
            'dex_convention' => 'sum_signed_option_delta_times_oi_times_100', 'gex_convention' => 'call_minus_put',
            'contract_multiplier' => 100, 'reference_price' => ['value' => $close !== null && $close > 0 ? $close : null,
                'date' => $levels['data_date'], 'kind' => 'daily_close', 'live' => false],
            'strikes' => $list,
            'summary' => ['net_dex' => $sum('net_dex'), 'call_dex' => $sum('call_dex'), 'put_dex' => $sum('put_dex'),
                'net_gex' => $sum('net_gex'), 'strike_count' => count($list),
                'largest_positive' => $positive === null ? null : ['strike' => $positive['strike'], 'net_dex' => $positive['net_dex']],
                'largest_negative' => $negative === null ? null : ['strike' => $negative['strike'], 'net_dex' => $negative['net_dex']]],
            'pressure_direction' => null, 'pressure_status' => 'scenario_model_required',
            'audit' => ['generated_at' => now()->toIso8601String(), 'source_dates_by_expiry' => $dates,
                'historical_outcome_eligible' => false, 'truncated' => false,
                'contract_rows' => array_sum(array_column(array_column($list, 'audit'), 'contract_rows')),
                'excluded_rows' => array_sum(array_column(array_column($list, 'audit'), 'excluded_rows')),
                'aggregation' => 'Sums use eligible signed-delta inputs only. A wholly unavailable side or strike is null; absent option sides contribute zero. Audit counts describe each estimate.',
                'inventory_assumption' => 'Matches the existing positioning signed-option-delta convention. Actual dealer holdings and hedging trades are not observed.'],
            'interpretation' => 'DEX describes modeled delta exposure; GEX describes its sensitivity to a price move. Their units differ. Neither DEX sign nor size alone predicts dealer trades, a price direction, or a wall hold.'];
    }
}
