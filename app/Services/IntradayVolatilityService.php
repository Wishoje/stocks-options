<?php

namespace App\Services;

use App\Support\IntradayReadIndex;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntradayVolatilityService
{
    public const MODEL = 'same-expiry-delta-interpolation.v1';

    public function build(string $symbol, ?string $session = null, ?string $expiry = null): array
    {
        // Read existing captures only. Leave a minute for the current chunked ingest to settle.
        $cutoff = now('UTC')->subMinute();
        $query = $this->query()->where('symbol', $symbol);
        $latest = (clone $query)
            ->where('captured_at', '<=', $cutoff)->orderByDesc('captured_at')->value('captured_at');
        $session ??= $latest ? CarbonImmutable::parse($latest, 'UTC')->setTimezone('America/New_York')->toDateString() : null;
        $result = ['schema_version' => 'intraday-volatility.v1', 'model_version' => self::MODEL,
            'symbol' => $symbol, 'session' => $session, 'expiry' => $expiry, 'expiries' => [], 'readings' => [],
            'generated_at' => now('UTC')->toIso8601String(), 'timestamp_basis' => 'collection_time',
            'source_observed_at' => null, 'status' => 'no_readings', 'summary' => null,
            'units' => ['iv' => 'percent', 'skew' => 'percentage_points', 'change' => 'percentage_points'],
            'method' => ['iv' => 'Mean of call and put IV at absolute delta 0.50.',
                'skew' => 'Put IV at delta -0.25 minus call IV at delta +0.25.',
                'interpolation' => 'Linear in absolute delta, within observed brackets only; maximum bracket width 0.20 and maximum distance to each endpoint 0.10.',
                'time' => 'Recorded times are collection times, not exchange timestamps. Inputs come from delayed intraday updates.',
                'session_window' => 'Start 15 minutes after the regular-session open to avoid anchoring to pre-open delayed pricing; end 15 minutes after the regular-session close.',
                'baseline' => 'First available reading for each metric in this session and expiration; not necessarily the market open.'],
            'historical_outcome_eligible' => false];
        if (! $session) {
            return $result;
        }
        $calendar = MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York'));
        if (! $calendar['is_trading_day']) {
            return $result;
        }
        $start = CarbonImmutable::parse($calendar['opens_at'])->addMinutes(15)->utc();
        $end = CarbonImmutable::parse($calendar['closes_at'])->addMinutes(15)->utc()->min($cutoff);
        $base = (clone $query)
            ->whereBetween('captured_at', [$start, $end])->where('expiration_date', '>=', $session);
        $expiries = (clone $base)->select('expiration_date')->distinct()->orderBy('expiration_date')->limit(32)->pluck('expiration_date')->all();
        $result['expiries'] = $expiries;
        if (! $expiry && $expiries) {
            $target = CarbonImmutable::parse($session)->addDays(7)->toDateString();
            $ordered = $expiries;
            usort($ordered, fn ($a, $b) => abs(strtotime($a) - strtotime($target)) <=> abs(strtotime($b) - strtotime($target)) ?: strcmp($a, $b));
            $expiry = $ordered[0];
        }
        $result['expiry'] = $expiry;
        if (! in_array($expiry, $expiries, true)) {
            return $result;
        }
        // Capture metadata retains empty readings instead of joining across them.
        $captures = (clone $base)->where('expiration_date', $expiry)->select('captured_at')->distinct()
            ->orderBy('captured_at')->limit(501)->pluck('captured_at')->all();
        if (count($captures) > 500) {
            $result['status'] = 'capacity_exceeded';

            return $result;
        }
        $rows = (clone $base)->where('expiration_date', $expiry)->whereIn('captured_at', $captures)
            ->where('implied_volatility', '>', 0)->where('strike_price', '>', 0)
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('contract_type', 'call')->whereBetween('delta', [.15, .60]))
                    ->orWhere(fn ($q) => $q->where('contract_type', 'put')->whereBetween('delta', [-.60, -.15]));
            })->orderBy('captured_at')->orderBy('id')->limit(25001)
            ->get(['contract_symbol', 'contract_type', 'strike_price', 'delta', 'implied_volatility', 'captured_at']);
        if ($rows->count() > 25000) {
            $result['status'] = 'capacity_exceeded';

            return $result;
        }
        $groups = $rows->groupBy('captured_at');
        $baselines = [];
        foreach ($captures as $capture) {
            $sample = ($groups[$capture] ?? collect())->map(fn ($row) => (array) $row)->all();
            $reading = $this->calculate($sample);
            $reading['recorded_at'] = CarbonImmutable::parse($capture, 'UTC')->toIso8601String();
            foreach (['iv', 'skew'] as $metric) {
                if ($reading[$metric] !== null && ! isset($baselines[$metric])) {
                    $baselines[$metric] = ['value' => $reading[$metric], 'recorded_at' => $reading['recorded_at']];
                }
                $reading[$metric.'_change'] = $reading[$metric] !== null && isset($baselines[$metric])
                    ? round($reading[$metric] - $baselines[$metric]['value'], 6) : null;
                $reading[$metric.'_baseline_at'] = $baselines[$metric]['recorded_at'] ?? null;
            }
            $result['readings'][] = $reading;
        }
        $result['summary'] = $result['readings'] ? end($result['readings']) : null;
        $result['status'] = $result['readings'] ? 'recorded' : 'no_readings';

        return $result;
    }

    public function calculate(array $rows): array
    {
        $inputs = [];
        foreach (['call', 'put'] as $side) {
            foreach ([25, 50] as $delta) {
                $inputs[$side.'_'.$delta] = $this->interpolate($rows, $side, $delta / 100);
            }
        }
        $call = $inputs['call_25']['iv'];
        $put = $inputs['put_25']['iv'];
        $nearCall = $inputs['call_50']['iv'];
        $nearPut = $inputs['put_50']['iv'];

        return ['iv' => $nearCall !== null && $nearPut !== null ? round(($nearCall + $nearPut) / 2, 6) : null,
            'call_iv' => $call, 'put_iv' => $put,
            'skew' => $call !== null && $put !== null ? round($put - $call, 6) : null,
            'inputs' => $inputs];
    }

    private function query(): Builder
    {
        $query = DB::table('intraday_option_volumes');
        if (DB::connection()->getDriverName() === 'mysql') {
            // MySQL can prefer the expiry-only index and scan every symbol's history.
            // Reuse the existing additive read index; do not perform DDL on a web request.
            $key = 'intraday-volatility:index:'.hash('sha256', DB::connection()->getDatabaseName());
            $index = Cache::remember($key, 3600, fn () => Schema::hasIndex('intraday_option_volumes', IntradayReadIndex::NAME)
                ? IntradayReadIndex::NAME : 'intraday_option_volumes_symbol_index');
            $query->forceIndex($index);
        }

        return $query;
    }

    private function interpolate(array $rows, string $side, float $target): array
    {
        $points = [];
        foreach ($rows as $row) {
            if (($row['contract_type'] ?? null) !== $side || ! is_numeric($row['delta'] ?? null)
                || ! is_numeric($row['implied_volatility'] ?? null) || ! is_numeric($row['strike_price'] ?? null)) {
                continue;
            }
            $signed = (float) $row['delta'];
            $iv = (float) $row['implied_volatility'];
            if (! is_finite($signed) || ! is_finite($iv) || $iv <= 0 || $row['strike_price'] <= 0
                || ($side === 'call' ? $signed <= 0 || $signed > 1 : $signed >= 0 || $signed < -1)) {
                continue;
            }
            $points[] = ['contract' => $row['contract_symbol'], 'strike' => (float) $row['strike_price'], 'delta' => abs($signed), 'iv' => $iv * 100];
        }
        usort($points, fn ($a, $b) => $a['delta'] <=> $b['delta'] ?: $a['strike'] <=> $b['strike']);
        $lower = null;
        foreach ($points as $point) {
            if (abs($point['delta'] - $target) < 1e-9) {
                return ['iv' => round($point['iv'], 6), 'status' => 'exact', 'contracts' => [$point]];
            }
            if ($point['delta'] < $target) {
                $lower = $point;

                continue;
            }
            if ($lower && $target - $lower['delta'] <= .100000001 && $point['delta'] - $target <= .100000001
                && $point['delta'] - $lower['delta'] <= .200000001) {
                $weight = ($target - $lower['delta']) / ($point['delta'] - $lower['delta']);

                return ['iv' => round($lower['iv'] + $weight * ($point['iv'] - $lower['iv']), 6),
                    'status' => 'interpolated', 'contracts' => [$lower, $point]];
            }
            break;
        }

        return ['iv' => null, 'status' => 'no_close_bracket', 'contracts' => []];
    }
}
