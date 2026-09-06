<?php

// Run from the current Laravel release. Read-only market data probe; normal
// response-cache writes may occur. No provider fetches or dispatch commands.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\DB::statement('SET SESSION MAX_EXECUTION_TIME=5000');

// Diagnostic override is process-local; it never edits the site's configuration.
$readOverride = getenv('GEX_PROBE_SNAPSHOT_READS');
if ($readOverride !== false) {
    if (! in_array($readOverride, ['0', '1'], true)) {
        throw new InvalidArgumentException('GEX_PROBE_SNAPSHOT_READS must be 0 or 1.');
    }
    config(['eod_snapshot_health.read_enabled' => $readOverride === '1']);
}

$clock = getenv('GEX_PROBE_CLOCK') ?: gmdate('c');
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($clock));
Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse($clock));
$out = ['revision' => trim(shell_exec('git rev-parse HEAD')), 'clock' => $clock,
    'expiration_universe_enabled' => config('gex_performance.expiration_universe_enabled', false),
    'snapshot_read_enabled' => config('eod_snapshot_health.read_enabled', false),
    'rows' => []];
$queryCount = $catalogQueries = $chainQueries = 0;
$queryMs = 0.0;
Illuminate\Support\Facades\DB::listen(function ($event) use (&$queryCount, &$catalogQueries, &$chainQueries, &$queryMs): void {
    $queryCount++;
    $queryMs += $event->time;
    $catalogQueries += str_contains($event->sql, 'option_expirations') ? 1 : 0;
    $chainQueries += str_contains($event->sql, 'option_chain_data') ? 1 : 0;
});
$cpu = static function (): float {
    $r = getrusage();

    return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000
        + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
};
foreach (['SPY', 'QQQ', 'IWM', 'TSLA', 'AAPL', 'V', 'MCK'] as $symbol) {
    foreach (['0d', '1d', '7d', '14d', '30d', '90d'] as $timeframe) {
        foreach (['cold', 'warm', 'warm', 'warm', 'warm', 'warm'] as $sample => $mode) {
            $queryCount = $catalogQueries = $chainQueries = 0;
            $queryMs = 0.0;
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $cpuStart = $cpu();
            $started = hrtime(true);
            try {
                $response = app(App\Http\Controllers\GexController::class)->getGexLevels(
                    Illuminate\Http\Request::create('/api/gex-levels', 'GET', [
                        'symbol' => $symbol, 'timeframe' => $timeframe, 'refresh' => $mode === 'cold',
                    ])
                );
                $payload = $response->getData(true);
                unset($payload['run'], $payload['bootstrap']);
                $out['rows'][] = [
                    'symbol' => $symbol, 'timeframe' => $timeframe, 'mode' => $mode, 'sample' => $sample,
                    'status' => $response->getStatusCode(), 'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 3),
                    'cpu_ms' => round($cpu() - $cpuStart, 3), 'peak_delta_bytes' => max(0, memory_get_peak_usage() - $memory),
                    'queries' => $queryCount, 'query_ms' => round($queryMs, 3),
                    'catalog_queries' => $catalogQueries, 'chain_queries' => $chainQueries,
                    'strikes' => count($payload['strike_data'] ?? []), 'data_date' => $payload['data_date'] ?? null,
                    'expirations' => $payload['expiration_dates'] ?? [],
                    'hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                ];
            } catch (Throwable $error) {
                $out['rows'][] = ['symbol' => $symbol, 'timeframe' => $timeframe, 'mode' => $mode,
                    'sample' => $sample, 'error_class' => get_class($error),
                    'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 3)];
                // Stop on a failed bounded diagnostic, rather than repeat expensive work.
                break 3;
            }
        }
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
