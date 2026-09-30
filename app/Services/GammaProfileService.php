<?php

namespace App\Services;

use App\Support\EodSnapshotSelector;
use App\Support\MarketSession;
use App\Support\WallIntelligence\GammaProfileModel;
use App\Support\WallIntelligence\WallSnapshotContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class GammaProfileService
{
    public function build(array $levels): array
    {
        $symbol = $levels['symbol'];
        $date = $levels['data_date'] ?? null;
        $expiries = $levels['expiration_dates'] ?? [];
        $base = ['schema_version' => 'gamma-profile.v1', 'model_version' => GammaProfileModel::VERSION,
            'symbol' => $symbol, 'timeframe' => $levels['timeframe'], 'data_date' => $date,
            'view_context' => $levels['view_context'] ?? null, 'expiration_dates' => $expiries,
            'unit' => 'USD per 1% underlying move', 'inventory_convention' => 'call_minus_put',
            'generated_at' => now()->toIso8601String(), 'historical_outcome_eligible' => false,
            'assumptions' => ['volatility' => 'fixed_per_contract_eod_iv', 'open_interest' => 'fixed_eod',
                'risk_free_rate' => 0, 'dividend_yield' => 0, 'contract_multiplier' => 100,
                'option_model' => 'European Black-Scholes', 'year_days' => 365, 'zero_dte_time_floor_seconds' => 60,
                'expiry_time' => 'NYSE regular-session close, including early closes'],
            'interpretation' => 'A modeled exposure profile, not observed dealer inventory or a price forecast. A flip is a sign change in aggregate exposure across underlying-price scenarios, not a sign change between strike bars or HVL.'];
        $notReady = function ($reason) use (&$base) {
            return $base + ['status' => 'not_ready', 'reason' => $reason, 'curve' => [], 'crossings' => [],
                'gamma_flip' => null, 'regime' => null, 'net_gex_at_spot' => null];
        };
        if (! $date || ! $expiries) {
            return $notReady('no_expirations');
        }
        $close = WallSnapshotContract::number(DB::table('prices_daily')->where('symbol', $symbol)->where('trade_date', $date)->value('close'));
        if ($close === null || $close <= 0) {
            return $notReady('no_reference_close');
        }
        $at = MarketSession::describe(CarbonImmutable::parse($date, 'America/New_York'))['closes_at'];
        if (! $at) {
            return $notReady('no_session_close');
        }
        $base['reference_price'] = ['value' => $close, 'date' => $date, 'kind' => 'daily_close', 'live' => false];
        $base['model_evaluated_at'] = $at;
        $ids = DB::table('option_expirations')->where('symbol', $symbol)->whereIn('expiration_date', $expiries)->pluck('expiration_date', 'id')->all();
        abort_if(count($ids) !== count($expiries), 409, 'Refresh the dashboard to update the gamma profile.');
        $rows = app(EodSnapshotSelector::class)->selectedRows(array_keys($ids),
            ['option_chain_data.expiration_id', 'option_chain_data.option_type', 'option_chain_data.strike', 'option_chain_data.open_interest',
                'option_chain_data.iv', 'option_chain_data.gamma', 'option_chain_data.underlying_price', 'option_chain_data.data_date'],
            $levels['view_context']['source_anchor'] ?? $date);
        if ($rows->isEmpty() || $rows->contains(fn ($r) => (string) $r->data_date !== $date)
            || $rows->pluck('expiration_id')->unique()->count() !== count($expiries)) {
            return $notReady('model_basis_not_ready');
        }
        if ($rows->count() > 30000) {
            return $notReady('model_scope_limit');
        }
        // Reconcile the selected chain generation with the dashboard's stored GEX.
        $legacy = [];
        foreach ($rows as $r) {
            $key = (string) (float) $r->strike;
            $legacy[$key] ??= 0.;
            $price = $r->underlying_price > 0 ? $r->underlying_price : 1;
            $legacy[$key] += ($r->option_type === 'call' ? 1 : -1) * ($r->gamma ?? 0) * ($r->open_interest ?? 0) * $price * $price;
        }
        abort_if(count($legacy) !== count($levels['strike_data'] ?? []), 409, 'Refresh the dashboard to update the gamma profile.');
        foreach ($levels['strike_data'] ?? [] as $row) {
            $expected = WallSnapshotContract::number($row['net_gex'] ?? null);
            $actual = $legacy[(string) (float) $row['strike']] ?? null;
            abort_if($expected === null || $actual === null || abs($actual - $expected * .01) > max(.01, abs($expected * .01) * 1e-8),
                409, 'Refresh the dashboard to update the gamma profile.');
        }
        $contracts = $rows->map(fn ($r) => ['expiry' => (string) $ids[$r->expiration_id], 'type' => $r->option_type,
            'strike' => $r->strike, 'oi' => $r->open_interest, 'iv' => $r->iv])->all();
        $result = (new GammaProfileModel)->calculate($contracts, $close, CarbonImmutable::parse($at));
        $base['basis_key'] = WallSnapshotContract::hash([$symbol, $date, $expiries, $contracts, $close, GammaProfileModel::VERSION]);
        $base['provenance'] = ['oi_date' => $date, 'iv_date' => $date, 'greeks_source_timestamp' => null, 'reference_price_kind' => 'daily_close'];

        return array_merge($base, $result);
    }
}
