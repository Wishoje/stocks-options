<?php

namespace App\Support;

use App\Http\Controllers\GexController;
use App\Support\Social\SocialCard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class PublicSpyPreview
{
    private const LAST_GOOD = 'public:spy-preview:v1:last-good';

    public function read(): ?array
    {
        try {
            $version = app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, 'SPY');
            $key = 'public:spy-preview:v1:'.hash('sha256', $version.':'.now('America/New_York')->toDateString());
            $entry = Cache::get($key);
            if (is_array($entry) && array_key_exists('data', $entry)) {
                return $this->present($entry['data'] ?? Cache::get(self::LAST_GOOD));
            }
            $fallback = Cache::get(self::LAST_GOOD);
            $lock = CoordinationCache::store()->lock('public:spy-preview:v1:build', 90);
            if (! $lock->get()) {
                return $this->present($fallback);
            }
            try {
                $entry = Cache::get($key);
                if (is_array($entry) && array_key_exists('data', $entry)) {
                    return $this->present($entry['data'] ?? $fallback);
                }
                try {
                    // Fixed server-owned input. Public query parameters cannot
                    // trigger refreshes, select another symbol or start work.
                    $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
                        'symbol' => 'SPY', 'timeframe' => '14d', 'view' => 'latest_eod',
                    ]));
                    $data = $response->getStatusCode() === 200 ? $this->normalize($response->getData(true)) : null;
                } catch (\Throwable $error) {
                    Log::warning('public.spy-preview-build-failed', ['exception' => get_class($error)]);
                    $data = null;
                }
                if ($version !== app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, 'SPY')) {
                    return $this->present($fallback);
                }
                Cache::put($key, ['data' => $data], now()->addSeconds($data ? 300 : 60));
                if ($data !== null) {
                    Cache::put(self::LAST_GOOD, $data, now()->addDays(14));
                }

                return $this->present($data ?? $fallback);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // A marketing page stays useful during a cache/database outage.
            // Do not turn each public request into an uncached chain rebuild.
            return null;
        }
    }

    public static function completedSession(): string
    {
        $now = CarbonImmutable::now('America/New_York');
        $market = MarketSession::describe($now);
        if ($market['is_trading_day'] && $now->greaterThanOrEqualTo(CarbonImmutable::parse($market['closes_at']))) {
            return $now->toDateString();
        }

        return MarketSession::tradingDateOnOrBefore($now->subDay());
    }

    public function normalize(array $payload): ?array
    {
        $date = substr((string) ($payload['data_date'] ?? ''), 0, 10);
        $quality = $payload['social_quality'] ?? [];
        $minimumCoverage = max(0, min(100, (float) config('wall_tracking.minimum_oi_coverage_pct', 95)));
        $smallGammaGap = ($quality['gamma_only_gaps'] ?? false) === true
            && is_numeric($quality['missing_gamma_oi_share'] ?? null)
            && (float) $quality['missing_gamma_oi_share'] >= 0
            && (float) $quality['missing_gamma_oi_share'] <= 1 - $minimumCoverage / 100 + 1e-12;
        if (($payload['symbol'] ?? null) !== 'SPY' || ($payload['timeframe'] ?? null) !== '14d'
            || ! self::validDate($date) || $date > self::completedSession()
            || ($quality['source_dates'] ?? []) !== [$date]
            || ($quality['missing_expiration_count'] ?? null) !== 0
            || (($quality['publishable'] ?? false) !== true && ! $smallGammaGap)
            || empty($payload['strike_data']) || empty($payload['expiration_dates'])) {
            return null;
        }
        $expiries = $payload['expiration_dates'];
        foreach ($expiries as $expiry) {
            if (! is_string($expiry) || ! self::validDate($expiry) || $expiry < $date) {
                return null;
            }
        }
        $rows = [];
        $seen = [];
        foreach ($payload['strike_data'] as $row) {
            if (! is_numeric($row['strike'] ?? null) || ! is_numeric($row['net_gex'] ?? null)
                || ! is_finite((float) $row['strike']) || ! is_finite((float) $row['net_gex']) || (float) $row['strike'] <= 0) {
                return null;
            }
            $strike = (float) $row['strike'];
            if (isset($seen[(string) $strike])) {
                return null;
            }
            $seen[(string) $strike] = true;
            // Match the normalized wall-analysis units, converting exactly once.
            $rows[] = ['strike' => $strike, 'net_gex' => (float) $row['net_gex'] * .01];
        }
        usort($rows, static fn ($left, $right) => $left['strike'] <=> $right['strike']);
        $positive = $negative = null;
        $total = 0.;
        foreach ($rows as $row) {
            $total += $row['net_gex'];
            if ($row['net_gex'] > 0 && ($positive === null || $row['net_gex'] > $positive['net_gex'])) {
                $positive = $row;
            }
            if ($row['net_gex'] < 0 && ($negative === null || $row['net_gex'] < $negative['net_gex'])) {
                $negative = $row;
            }
        }
        if (! is_finite($total)) {
            return null;
        }
        sort($expiries, SORT_STRING);

        return [
            'symbol' => 'SPY', 'timeframe' => '14d', 'scope_label' => '2W', 'view' => 'latest_eod',
            'data_date' => $date, 'expiration_dates' => array_values(array_unique($expiries)),
            'units' => 'USD_per_1pct_underlying_move', 'total_gex' => $total,
            'total_label' => SocialCard::exposure($total), 'put_wall' => $this->wall($negative), 'call_wall' => $this->wall($positive),
            'strike_count' => count($rows), 'chart' => $this->chart($rows, $positive, $negative),
        ];
    }

    private function present(?array $data): ?array
    {
        if ($data === null || ($data['data_date'] ?? '9999') > self::completedSession()) {
            return null;
        }

        return [...$data, 'is_latest_session' => $data['data_date'] === self::completedSession()];
    }

    private static function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    private function wall(?array $row): ?array
    {
        return $row ? [...$row, 'strike_label' => SocialCard::level($row['strike']), 'exposure_label' => SocialCard::exposure($row['net_gex'])] : null;
    }

    private function chart(array $rows, ?array $positive, ?array $negative): array
    {
        $absolute = array_sum(array_map(static fn ($row) => abs($row['net_gex']), $rows));
        $lo = 0;
        $hi = count($rows) - 1;
        $trimmed = 0.;
        while ($lo < $hi && $absolute > 0 && $trimmed + abs($rows[$lo]['net_gex']) <= $absolute * .01) {
            $trimmed += abs($rows[$lo++]['net_gex']);
        }
        $trimmed = 0.;
        while ($hi > $lo && $absolute > 0 && $trimmed + abs($rows[$hi]['net_gex']) <= $absolute * .01) {
            $trimmed += abs($rows[$hi--]['net_gex']);
        }
        // Even a relatively small opposite-side peak must remain visible.
        foreach ($rows as $index => $row) {
            if (in_array($row['strike'], [$positive['strike'] ?? null, $negative['strike'] ?? null], true)) {
                $lo = min($lo, $index);
                $hi = max($hi, $index);
            }
        }
        $rows = array_slice($rows, max(0, $lo - 2), min(count($rows) - 1, $hi + 2) - max(0, $lo - 2) + 1);
        $min = $rows[0]['strike'];
        $max = $rows[count($rows) - 1]['strike'];
        $range = max(1., $max - $min);
        $limit = max(1., ...array_map(static fn ($row) => abs($row['net_gex']), $rows)) * 1.1;
        $left = 100.;
        $width = 990.;
        $zero = 208.;
        $amplitude = 166.;
        $step = $range;
        for ($i = 1; $i < count($rows); $i++) {
            $step = min($step, $rows[$i]['strike'] - $rows[$i - 1]['strike']);
        }
        $domain = $range + 2 * $step;
        $barWidth = max(.8, min(16., $step / $domain * $width * .75));
        $bars = array_map(static function ($row) use ($left, $width, $min, $domain, $step, $zero, $limit, $amplitude, $barWidth) {
            $end = $zero - $row['net_gex'] / $limit * $amplitude;

            return [...$row, 'x' => round($left + ($row['strike'] - $min + $step) / $domain * $width - $barWidth / 2, 2),
                'y' => round(min($zero, $end), 2), 'width' => round($barWidth, 2), 'height' => round(abs($end - $zero), 2),
                'positive' => $row['net_gex'] >= 0, 'title' => 'Strike '.SocialCard::level($row['strike']).': '.SocialCard::exposure($row['net_gex']).' USD per 1% move'];
        }, $rows);
        $xTicks = [];
        for ($i = 0; $i <= 6; $i++) {
            $strike = $min + ($max - $min) * $i / 6;
            $xTicks[] = ['x' => round($left + ($strike - $min + $step) / $domain * $width, 2), 'label' => SocialCard::level($strike)];
        }
        $yTicks = array_map(static fn ($fraction) => ['y' => round($zero - $fraction * $amplitude, 2), 'label' => SocialCard::exposure($fraction * $limit)], [-1, -.5, 0, .5, 1]);

        return ['bars' => $bars, 'x_ticks' => $xTicks, 'y_ticks' => $yTicks, 'zero' => $zero];
    }
}
