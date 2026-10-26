<?php

namespace App\Services;

use App\Http\Controllers\GexController;
use App\Support\EodCacheVersion;
use App\Support\EodViewContext;
use App\Support\PublicSpyPreview;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Used by the bounded refresh command, never by a page view. No provider calls. */
class SwingDataSource
{
    public function read(string $symbol): array
    {
        $versions = app(EodCacheVersion::class);
        $version = $versions->current(EodCacheVersion::DOMAIN_GEX, $symbol);
        $volVersion = $versions->current(EodCacheVersion::DOMAIN_VOLATILITY, $symbol);
        $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => $symbol, 'timeframe' => '90d', 'view' => 'latest_eod',
        ]));
        $levels = $response->getData(true);
        $date = $levels['data_date'] ?? null;
        $quality = $levels['social_quality'] ?? [];
        if ($response->getStatusCode() !== 200 || ! $date || $date > PublicSpyPreview::completedSession()
            || ($levels['symbol'] ?? null) !== $symbol || ($quality['source_dates'] ?? []) !== [$date]
            || ($quality['missing_expiration_count'] ?? null) !== 0) {
            throw new DomainException('No aligned completed EOD reading is available.');
        }
        $map = app(GexExpiryStrikeService::class)->build($levels);
        $prices = DB::table('prices_daily')->where('symbol', $symbol)->where('trade_date', '<=', $date)
            ->orderByDesc('trade_date')->limit(80)->get(['trade_date', 'open', 'high', 'low', 'close'])
            ->reverse()->map(fn ($r) => ['date' => $r->trade_date, 'open' => $this->number($r->open),
                'high' => $this->number($r->high), 'low' => $this->number($r->low), 'close' => $this->number($r->close)])->values()->all();
        $vol = DB::table('vrp_daily')->where('symbol', $symbol)->where('data_date', $date)->first(['iv1m', 'rv20', 'vrp']);
        $terms = DB::table('iv_term')->where('symbol', $symbol)->where('data_date', $date)->orderBy('exp_date')->get(['exp_date', 'iv']);
        $skews = DB::table('iv_skew')->where('symbol', $symbol)->where('data_date', $date)->get(['exp_date', 'skew_pc'])->keyBy('exp_date');
        $dex = DB::table('dex_by_expiry')->where('symbol', $symbol)->where('data_date', $date)->get(['exp_date', 'dex_total']);
        $benchmark = DB::table('prices_daily')->where('symbol', 'SPY')->where('trade_date', '<=', $date)
            ->orderByDesc('trade_date')->limit(6)->get(['trade_date', 'close']);
        $relative = $benchmark->count() === 6 && $benchmark->first()->trade_date === $date && $benchmark->last()->close > 0
            ? ['symbol' => 'SPY', 'date' => $date, 'return5_pct' => 100 * ($benchmark->first()->close / $benchmark->last()->close - 1)] : null;
        $priorDate = EodViewContext::previousSession($date);
        $prior = DB::table('option_chain_data as o')->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)->whereIn('e.expiration_date', $map['expiration_dates'])->where('o.data_date', $priorDate)
            ->select('e.expiration_date', 'o.strike')->selectRaw('COUNT(*) as rows_count, SUM(COALESCE(o.open_interest, 0)) as oi, SUM(CASE WHEN o.open_interest IS NULL OR o.open_interest < 0 THEN 1 ELSE 0 END) as invalid')
            ->groupBy('e.expiration_date', 'o.strike')->get();
        $priorOi = [];
        foreach ($prior as $r) {
            $priorOi[$r->expiration_date.':'.(float) $r->strike] = ['count' => (int) $r->rows_count, 'oi' => (float) $r->oi, 'invalid' => (int) $r->invalid];
        }
        if ($version !== $versions->current(EodCacheVersion::DOMAIN_GEX, $symbol)
            || $volVersion !== $versions->current(EodCacheVersion::DOMAIN_VOLATILITY, $symbol)) {
            throw new DomainException('The EOD publication changed during the comparison.');
        }

        return ['symbol' => $symbol, 'data_date' => $date, 'strikes' => $map['strikes'], 'prices' => $prices,
            'source_version' => $version, 'volatility_version' => $volVersion, 'prior_oi' => $priorOi,
            'benchmark' => $relative, 'units' => 'USD_per_1pct_move',
            'volatility' => ['date' => $date, 'iv1m' => $this->number($vol?->iv1m), 'rv20' => $this->number($vol?->rv20),
                'vrp' => $this->number($vol?->vrp), 'terms' => $terms->map(fn ($r) => ['expiration' => $r->exp_date,
                    'iv' => $this->number($r->iv), 'skew_pp' => $this->number($skews->get($r->exp_date)?->skew_pc) === null
                        ? null : 100 * (float) $skews->get($r->exp_date)->skew_pc])->all()],
            'dex' => $dex->filter(fn ($r) => $this->number($r->dex_total) !== null)->map(fn ($r) => ['expiration' => $r->exp_date, 'value' => (float) $r->dex_total])->values()->all()];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
