<?php

// Run from the current Laravel release, including over PHP stdin. Flags are
// process-local. Reads may fill response caches or enqueue coalesced metadata
// repair, but no provider fetch, raw write, or certification is performed.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::statement('SET SESSION MAX_EXECUTION_TIME=5000');
$clock = getenv('GEX_PROBE_CLOCK') ?: gmdate('c');
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($clock));
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($clock));
$out = ['revision' => trim(shell_exec('git rev-parse HEAD')), 'clock' => $clock,
    'global_read_flag' => config('eod_snapshot_health.read_enabled'),
    'comparator' => App\Support\Regression\BaselineComparator::class,
    'absolute_float_tolerance' => 0.000001, 'rows' => []];
$queries = $marketQueries = 0;
Illuminate\Support\Facades\DB::listen(function ($event) use (&$queries, &$marketQueries): void {
    $queries++;
    $marketQueries += str_contains($event->sql, 'option_chain_data') || str_contains($event->sql, 'option_expirations') ? 1 : 0;
});
$numericDiff = function ($a, $b, string $path, array &$stats) use (&$numericDiff): void {
    if (is_array($a) && is_array($b)) {
        foreach ($a as $key => $value) {
            if (array_key_exists($key, $b)) {
                $numericDiff($value, $b[$key], $path.'.'.$key, $stats);
            }
        }
    } elseif ((is_float($a) || is_int($a)) && (is_float($b) || is_int($b)) && $a !== $b) {
        $delta = abs($a - $b);
        $stats['numeric_differences']++;
        if ($delta > $stats['max_absolute_delta']) {
            $stats['max_absolute_delta'] = $delta;
            $stats['max_delta_path'] = $path;
            $stats['max_delta_values'] = [$a, $b];
        }
        if (is_int($a) && is_int($b)) {
            $stats['integer_value_differences']++;
        }
    }
};
foreach (['SPY', 'QQQ', 'IWM', 'TSLA', 'AAPL', 'V', 'MCK', 'MSFT', 'NVDA'] as $symbol) {
    foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
        $row = ['symbol' => $symbol, 'timeframe' => $timeframe,
            'has_state' => Illuminate\Support\Facades\DB::table('eod_snapshot_states')->where('symbol', $symbol)->exists(),
            'numeric_differences' => 0, 'integer_value_differences' => 0, 'max_absolute_delta' => 0.0, 'samples' => []];
        $baseline = null;
        $candidate = null;
        foreach (['legacy-cold', 'candidate-cold', 'candidate-warm', 'candidate-warm', 'candidate-warm', 'candidate-warm', 'candidate-warm'] as $mode) {
            config(['eod_snapshot_health.read_enabled' => $mode !== 'legacy-cold']);
            $queries = $marketQueries = 0;
            $start = hrtime(true);
            try {
                $response = app(App\Http\Controllers\GexController::class)->getGexLevels(
                    Illuminate\Http\Request::create('/api/gex-levels', 'GET', [
                        'symbol' => $symbol, 'timeframe' => $timeframe, 'refresh' => $mode !== 'candidate-warm',
                    ])
                );
                $elapsed = round((hrtime(true) - $start) / 1e6, 3);
                $payload = $response->getData(true);
                unset($payload['run'], $payload['bootstrap']);
                $value = ['status' => $response->getStatusCode(), 'payload' => $payload];
                $hash = hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
                $sample = ['mode' => $mode, 'status' => $value['status'], 'ms' => $elapsed,
                    'queries' => $queries, 'market_queries' => $marketQueries, 'hash' => $hash];
                if ($mode === 'legacy-cold') {
                    $baseline = $value;
                } else {
                    $comparison = (new App\Support\Regression\BaselineComparator(maxDifferences: 3))->compare($baseline, $value);
                    $sample['matches_baseline'] = $comparison['matches'];
                    if (! $comparison['matches'] && $mode === 'candidate-cold') {
                        $sample['differences'] = $comparison['differences'];
                    }
                    if ($mode === 'candidate-cold') {
                        $candidate = $hash;
                        $numericDiff($baseline, $value, '$', $row);
                    } else {
                        $sample['exact_candidate_cold_match'] = $hash === $candidate;
                    }
                }
                $row['samples'][] = $sample;
            } catch (Throwable $error) {
                $row['error_class'] = get_class($error);
                $out['rows'][] = $row;
                echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
                exit(1);
            }
        }
        $out['rows'][] = $row;
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
