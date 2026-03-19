<?php

namespace App\Jobs;

use App\Support\EodSnapshotSelector;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ComputeVolMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols) {}

    public function handle(): void
    {
        $selector = app(EodSnapshotSelector::class);
        $date = $selector->completedSessionDate(now());

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);
            [$expMap, $selectedDates, $rows] = $this->selectedChainContext($symbol, $date, $selector);

            $termRows = $this->computeTermStructure($symbol, $date, $expMap, $selectedDates, $rows);

            DB::table('iv_term')->where('symbol', $symbol)->where('data_date', $date)->delete();
            if (!empty($termRows)) {
                DB::table('iv_term')->insert($termRows);
            }

            [$iv1m, $vrpMeta] = $this->pick1MIV($termRows, $date);
            $rv20 = $this->realizedVol20($symbol, $date);
            $vrp = (is_null($iv1m) || is_null($rv20)) ? null : ($iv1m - $rv20);
            $z = $this->zscoreVRP($symbol, $date, $vrp);

            DB::table('vrp_daily')->updateOrInsert(
                ['symbol' => $symbol, 'data_date' => $date],
                [
                    'iv1m' => $iv1m,
                    'rv20' => $rv20,
                    'vrp' => $vrp,
                    'z' => $z,
                    'source_meta_json' => json_encode($vrpMeta, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $this->computeSkewCurvature($symbol, $date, $expMap, $selectedDates, $rows);

            Cache::forget("iv_term:{$symbol}");
            Cache::forget("vrp:{$symbol}");
            Cache::forget("iv_skew:{$symbol}");
        }
    }

    /**
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    protected function selectedChainContext(string $symbol, string $date, EodSnapshotSelector $selector): array
    {
        $expMap = DB::table('option_expirations')->where('symbol', $symbol)->pluck('id', 'expiration_date');
        if ($expMap->isEmpty()) {
            return [$expMap, collect(), collect()];
        }

        $expirationIds = array_values($expMap->toArray());
        $selectedDates = $selector->selectedDateRows($expirationIds, $date)->keyBy('expiration_id');

        $rows = DB::table('option_chain_data as o')
            ->joinSub($selector->selectedDatesSubquery($expirationIds, $date), 'ld', fn ($join) => $join
                ->on('o.expiration_id', '=', 'ld.expiration_id')
                ->on('o.data_date', '=', 'ld.max_date'))
            ->whereIn('o.expiration_id', $expirationIds)
            ->get([
                'o.expiration_id',
                'o.data_date',
                'o.option_type',
                'o.strike',
                'o.iv',
                'o.delta',
                'o.underlying_price',
                'o.volume',
            ]);

        return [$expMap, $selectedDates, $rows];
    }

    protected function computeTermStructure(
        string $symbol,
        string $date,
        Collection $expMap,
        Collection $selectedDates,
        Collection $rows
    ): array {
        if ($expMap->isEmpty() || $rows->isEmpty()) {
            return [];
        }

        $termRows = [];
        foreach ($expMap as $expDate => $expirationId) {
            $slice = $rows->where('expiration_id', $expirationId);
            if ($slice->isEmpty()) {
                continue;
            }

            $spot = (float) round($slice->whereNotNull('underlying_price')->avg('underlying_price') ?? 0, 4);
            if ($spot <= 0) {
                continue;
            }

            $callATM = $slice->where('option_type', 'call')->sortBy(fn ($row) => abs($row->strike - $spot))->first();
            $putATM = $slice->where('option_type', 'put')->sortBy(fn ($row) => abs($row->strike - $spot))->first();

            $ivATM = null;
            if (!empty($callATM?->iv) && !empty($putATM?->iv)) {
                $ivATM = 0.5 * ((float) $callATM->iv + (float) $putATM->iv);
            } elseif (!empty($callATM?->iv)) {
                $ivATM = (float) $callATM->iv;
            } elseif (!empty($putATM?->iv)) {
                $ivATM = (float) $putATM->iv;
            }

            if (is_null($ivATM)) {
                $calls = $slice->where('option_type', 'call')->filter(
                    fn ($row) => $row->strike >= $spot && (float) $row->iv > 0
                );
                $puts = $slice->where('option_type', 'put')->filter(
                    fn ($row) => $row->strike <= $spot && (float) $row->iv > 0
                );

                $ivCall = $this->vwapIV($calls);
                $ivPut = $this->vwapIV($puts);
                if (!is_null($ivCall) && !is_null($ivPut)) {
                    $ivATM = 0.5 * ($ivCall + $ivPut);
                } else {
                    $ivATM = $ivCall ?? $ivPut;
                }
            }

            if (is_null($ivATM)) {
                continue;
            }

            $termRows[] = [
                'symbol' => $symbol,
                'data_date' => $date,
                'exp_date' => $expDate,
                'iv' => (float) $ivATM,
                'source_chain_date' => $selectedDates->get($expirationId)->max_date ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        usort($termRows, fn ($a, $b) => strcmp($a['exp_date'], $b['exp_date']));

        return $termRows;
    }

    protected function vwapIV(Collection $collection): ?float
    {
        $weight = 0.0;
        $sum = 0.0;

        foreach ($collection as $row) {
            $vol = max(1.0, (float) ($row->volume ?? 1));
            $iv = (float) ($row->iv ?? 0);
            if ($iv <= 0) {
                continue;
            }

            $weight += $vol;
            $sum += $vol * $iv;
        }

        return $weight > 0 ? $sum / $weight : null;
    }

    /**
     * @param array<int,array<string,mixed>> $termRows
     * @return array{0:?float,1:array<string,mixed>}
     */
    protected function pick1MIV(array $termRows, string $date): array
    {
        if (empty($termRows)) {
            return [null, [
                'anchor_date' => $date,
                'selected_exp_date' => null,
                'source_chain_date' => null,
                'fallback_reason' => 'no_term_rows',
            ]];
        }

        $target = strtotime(\Carbon\Carbon::parse($date)->addDays(21)->toDateString());
        $best = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($termRows as $row) {
            if (!isset($row['iv']) || is_null($row['iv'])) {
                continue;
            }

            $diff = abs(strtotime((string) $row['exp_date']) - $target);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $row;
            }
        }

        if (!$best) {
            return [null, [
                'anchor_date' => $date,
                'selected_exp_date' => null,
                'source_chain_date' => null,
                'fallback_reason' => 'no_non_null_iv',
            ]];
        }

        return [(float) $best['iv'], [
            'anchor_date' => $date,
            'selected_exp_date' => $best['exp_date'],
            'source_chain_date' => $best['source_chain_date'] ?? null,
            'fallback_reason' => null,
        ]];
    }

    protected function realizedVol20(string $symbol, string $date): ?float
    {
        $prices = DB::table('prices_daily')
            ->where('symbol', $symbol)
            ->where('trade_date', '<=', $date)
            ->orderByDesc('trade_date')
            ->limit(22)
            ->get(['trade_date', 'close'])
            ->sortBy('trade_date')
            ->values();

        if ($prices->count() < 21) {
            return null;
        }

        $returns = [];
        for ($i = 1; $i < $prices->count(); $i++) {
            $prev = (float) $prices[$i - 1]->close;
            $curr = (float) $prices[$i]->close;
            if ($prev > 0 && $curr > 0) {
                $returns[] = log($curr / $prev);
            }
        }

        if (count($returns) < 20) {
            return null;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $value) {
            $variance += ($value - $mean) * ($value - $mean);
        }

        $sd = sqrt($variance / max(1, count($returns) - 1));

        return $sd * sqrt(252.0);
    }

    protected function zscoreVRP(string $symbol, string $date, ?float $vrp): ?float
    {
        if (is_null($vrp)) {
            return null;
        }

        $history = DB::table('vrp_daily')
            ->where('symbol', $symbol)
            ->where('data_date', '<', $date)
            ->orderByDesc('data_date')
            ->limit(252)
            ->pluck('vrp')
            ->filter(fn ($value) => !is_null($value))
            ->values();

        if ($history->count() < 30) {
            return null;
        }

        $mean = $history->avg();
        $variance = 0.0;
        foreach ($history as $value) {
            $variance += ($value - $mean) * ($value - $mean);
        }

        $sd = sqrt($variance / max(1, $history->count() - 1));

        return $sd > 0 ? ($vrp - $mean) / $sd : null;
    }

    protected function computeSkewCurvature(
        string $symbol,
        string $date,
        Collection $expMap,
        Collection $selectedDates,
        Collection $rows
    ): void {
        if ($expMap->isEmpty() || $rows->isEmpty()) {
            return;
        }

        foreach ($expMap as $expDate => $expId) {
            $slice = $rows->where('expiration_id', $expId)
                ->filter(fn ($row) => !is_null($row->iv) && !is_null($row->delta) && !is_null($row->strike));

            if ($slice->isEmpty()) {
                continue;
            }

            $iv25c = $this->ivAtTargetDelta($slice->where('option_type', 'call'), 0.25);
            $iv25p = $this->ivAtTargetDelta($slice->where('option_type', 'put'), -0.25);

            $spot = (float) round($slice->avg('underlying_price') ?? 0, 6);
            $curvature = null;

            if ($spot > 0) {
                $points = $slice->filter(fn ($row) => (float) $row->iv > 0 && (float) $row->strike > 0)
                    ->map(fn ($row) => [
                        'k' => (float) log($row->strike / $spot),
                        'iv' => (float) $row->iv,
                    ])
                    ->filter(fn ($point) => abs($point['k']) <= 0.30)
                    ->sortBy(fn ($point) => abs($point['k']))
                    ->take(60)
                    ->values()
                    ->all();

                if (count($points) >= 10) {
                    $span = 0.0;
                    foreach ($points as $point) {
                        $span = max($span, abs($point['k']));
                    }

                    if ($span >= 0.05) {
                        $curvatureRaw = $this->quadA($points);
                        $curvature = is_finite($curvatureRaw) ? $curvatureRaw * 0.01 : null;
                        if (!is_finite($curvature) || abs($curvature) > 1e6) {
                            $curvature = null;
                        }
                    }
                }
            }

            $skew = (is_null($iv25p) || is_null($iv25c)) ? null : ($iv25p - $iv25c);
            if (!is_finite($skew) || abs($skew) > 10) {
                $skew = null;
            }

            $prev = DB::table('iv_skew')
                ->where('symbol', $symbol)
                ->where('exp_date', $expDate)
                ->where('data_date', '<', $date)
                ->orderByDesc('data_date')
                ->first(['skew_pc', 'curvature']);

            $skewDod = (!is_null($skew) && $prev) ? ($skew - (float) $prev->skew_pc) : null;
            $curvatureDod = (!is_null($curvature) && $prev) ? ($curvature - (float) $prev->curvature) : null;

            DB::table('iv_skew')->updateOrInsert(
                ['symbol' => $symbol, 'data_date' => $date, 'exp_date' => $expDate],
                [
                    'iv_put_25d' => $iv25p,
                    'iv_call_25d' => $iv25c,
                    'skew_pc' => $skew,
                    'curvature' => $curvature,
                    'skew_pc_dod' => $skewDod,
                    'curvature_dod' => $curvatureDod,
                    'source_chain_date' => $selectedDates->get($expId)->max_date ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    protected function ivAtTargetDelta(Collection $collection, float $target): ?float
    {
        $points = $collection->map(fn ($row) => ['d' => (float) $row->delta, 'iv' => (float) $row->iv])
            ->filter(fn ($point) => is_finite($point['d']) && is_finite($point['iv']))
            ->sortBy('d')
            ->values()
            ->all();

        if (count($points) === 0) {
            return null;
        }

        foreach ($points as $point) {
            if (abs($point['d'] - $target) < 1e-6) {
                return $point['iv'];
            }
        }

        $lo = null;
        $hi = null;
        foreach ($points as $point) {
            if ($point['d'] <= $target) {
                $lo = $point;
            }
            if ($point['d'] >= $target) {
                $hi = $point;
                break;
            }
        }

        if (!$lo || !$hi || $lo['d'] === $hi['d']) {
            usort($points, fn ($a, $b) => abs($a['d'] - $target) <=> abs($b['d'] - $target));
            return $points[0]['iv'] ?? null;
        }

        $t = ($target - $lo['d']) / ($hi['d'] - $lo['d']);
        return $lo['iv'] + $t * ($hi['iv'] - $lo['iv']);
    }

    /**
     * @param array<int,array{k: float, iv: float}> $points
     */
    protected function quadA(array $points): ?float
    {
        $count = count($points);
        $sum = fn ($callback) => array_reduce($points, fn ($carry, $point) => $carry + $callback($point), 0.0);

        $s0 = $count;
        $s1 = $sum(fn ($point) => $point['k']);
        $s2 = $sum(fn ($point) => $point['k'] * $point['k']);
        $s3 = $sum(fn ($point) => $point['k'] * $point['k'] * $point['k']);
        $s4 = $sum(fn ($point) => $point['k'] * $point['k'] * $point['k'] * $point['k']);

        $t0 = $sum(fn ($point) => $point['iv']);
        $t1 = $sum(fn ($point) => $point['iv'] * $point['k']);
        $t2 = $sum(fn ($point) => $point['iv'] * $point['k'] * $point['k']);

        $matrix = [
            [$s4, $s3, $s2],
            [$s3, $s2, $s1],
            [$s2, $s1, $s0],
        ];
        $vector = [$t2, $t1, $t0];

        $det = function (array $m): float {
            return $m[0][0] * ($m[1][1] * $m[2][2] - $m[1][2] * $m[2][1])
                - $m[0][1] * ($m[1][0] * $m[2][2] - $m[1][2] * $m[2][0])
                + $m[0][2] * ($m[1][0] * $m[2][1] - $m[1][1] * $m[2][0]);
        };

        $denominator = $det($matrix);
        if (abs($denominator) < 1e-12) {
            return null;
        }

        $matrixA = [
            [$vector[0], $matrix[0][1], $matrix[0][2]],
            [$vector[1], $matrix[1][1], $matrix[1][2]],
            [$vector[2], $matrix[2][1], $matrix[2][2]],
        ];

        return $det($matrixA) / $denominator;
    }
}
