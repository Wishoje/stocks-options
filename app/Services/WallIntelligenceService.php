<?php

namespace App\Services;

use App\Support\EodSnapshotSelector;
use App\Support\MarketSession;
use App\Support\WallIntelligence\WallIntelligenceMetrics;
use App\Support\WallIntelligence\WallSnapshotContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WallIntelligenceService
{
    public function build(array $levels): array
    {
        $expiries = $levels['expiration_dates'] ?? [];
        abort_if(! ($levels['data_date'] ?? null) || ! $expiries, 404, 'Wall analysis is not available for this selection.');
        $ids = DB::table('option_expirations')->where('symbol', $levels['symbol'])
            ->whereIn('expiration_date', $expiries)->pluck('expiration_date', 'id')->all();
        abort_if(count($ids) !== count($expiries), 409, 'Refresh the dashboard to update wall analysis.');
        $selector = app(EodSnapshotSelector::class);
        $anchor = $levels['view_context']['source_anchor'] ?? $levels['data_date'];
        $selected = $selector->selectedDatesSubquery(array_keys($ids), $anchor);
        $currentQuery = DB::table('option_chain_data as o')->joinSub($selected, 'selected', function ($join) {
            $join->on('o.expiration_id', '=', 'selected.expiration_id')->on('o.data_date', '=', 'selected.max_date');
        })->whereIn('o.expiration_id', array_keys($ids));
        $current = $this->aggregate($currentQuery, $ids);
        $metrics = new WallIntelligenceMetrics;
        // Never pair a cached dashboard payload with a different generation of chain data.
        $actual = $metrics->byStrike($current);
        abort_if(count($actual) !== count($levels['strike_data'] ?? []), 409, 'Refresh the dashboard to update wall analysis.');
        foreach ($levels['strike_data'] ?? [] as $row) {
            $found = $actual[(string) (float) $row['strike']] ?? null;
            foreach (['net_gex', 'call_gex', 'put_gex'] as $field) {
                $expected = WallSnapshotContract::number($row[$field] ?? null);
                $expected = $expected === null ? null : $expected * .01;
                abort_if($found === null || $expected === null || abs($found[$field] - $expected) > max(.01, abs($expected) * 1e-8), 409, 'Refresh the dashboard to update wall analysis.');
            }
        }
        $sessions = [$levels['data_date']];
        $date = CarbonImmutable::parse($levels['data_date'], 'America/New_York');
        while (count($sessions) < 5) {
            $date = $date->subDay();
            if (MarketSession::isTradingDay($date)) {
                $sessions[] = $date->toDateString();
            }
        }
        $historyRows = $this->aggregate(DB::table('option_chain_data as o')
            ->whereIn('o.expiration_id', array_keys($ids))->whereIn('o.data_date', array_slice($sessions, 1)), $ids);
        $history = [];
        foreach ($historyRows as $row) {
            $history[$row['data_date']][] = $row;
        }
        $close = WallSnapshotContract::number(DB::table('prices_daily')
            ->where('symbol', $levels['symbol'])->where('trade_date', $levels['data_date'])->value('close'));
        $result = $metrics->calculate($levels, $current, $history, $sessions, $close !== null && $close > 0 ? $close : null, $selector->minSideRatio());
        $result['audit'] = [
            'current_group_count' => count($current),
            'groups_with_fallback_inputs' => count(array_filter($current, fn ($r) => ! $r['inputs_valid'])),
            'history_basis' => 'retrospective_fixed_expiry_set',
            'observed_at' => null, 'generated_at' => now()->toIso8601String(),
            'historical_outcome_eligible' => false,
        ];

        return $result;
    }

    private function aggregate(Builder $query, array $ids): array
    {
        // Legacy = gamma * OI * 100 * spot^2; multiply by .01 for USD / 1%.
        // Preserve the existing fallback convention, but prevent affected strike comparisons.
        $gex = 'COALESCE(o.gamma, 0) * COALESCE(o.open_interest, 0) * CASE WHEN o.underlying_price > 0 THEN o.underlying_price * o.underlying_price ELSE 1 END';

        return $query->select('o.expiration_id', 'o.data_date', 'o.strike')
            ->selectRaw("SUM(CASE WHEN o.option_type = 'call' THEN $gex ELSE 0 END) as call_gex")
            ->selectRaw("SUM(CASE WHEN o.option_type = 'put' THEN $gex ELSE 0 END) as put_gex")
            ->selectRaw('SUM(COALESCE(o.open_interest, 0)) as total_oi')
            ->selectRaw("SUM(CASE WHEN o.option_type = 'call' THEN 1 ELSE 0 END) as call_rows")
            ->selectRaw("SUM(CASE WHEN o.option_type = 'put' THEN 1 ELSE 0 END) as put_rows")
            ->selectRaw('SUM(CASE WHEN o.open_interest IS NULL OR o.open_interest < 0 OR (o.open_interest > 0 AND (o.gamma IS NULL OR o.gamma < 0 OR o.underlying_price IS NULL OR o.underlying_price <= 0)) THEN 1 ELSE 0 END) as invalid_rows')
            ->groupBy('o.expiration_id', 'o.data_date', 'o.strike')
            ->get()->map(fn ($r) => [
                'expiry' => (string) $ids[$r->expiration_id], 'data_date' => (string) $r->data_date,
                'strike' => (float) $r->strike, 'call_gex' => (float) $r->call_gex, 'put_gex' => (float) $r->put_gex,
                'net_gex' => (float) $r->call_gex - (float) $r->put_gex, 'open_interest' => (float) $r->total_oi,
                'call_rows' => (int) $r->call_rows, 'put_rows' => (int) $r->put_rows, 'inputs_valid' => (int) $r->invalid_rows === 0,
            ])->all();
    }
}
