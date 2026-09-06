<?php

namespace Tests\Feature;

use App\Http\Controllers\GexController;
use App\Support\EodCacheVersion;
use App\Support\EodSnapshotHealth;
use App\Support\Regression\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

/** Paired local fixtures, not production latency or capacity measurements. */
class GexSnapshotManifestPerformanceTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('symbol_bootstrap.enabled', false);
        config()->set('cache.default', 'array');
        config()->set('queue_lanes.isolated', false);
        config()->set('provider_backpressure.enabled', false);
        config()->set('services.massive.eod_min_side_strike_ratio', 0.5);
        config()->set('services.massive.eod_force_data_date', '');
        config()->set('gex_performance.expiration_shadow_enabled', false);
        config()->set('eod_snapshot_health.enabled', true);
        config()->set('eod_snapshot_health.read_enabled', false);
        $this->travelTo(CarbonImmutable::parse('2026-09-04 21:00:00', 'UTC'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->travelBack();

        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return [
            'normal' => ['AAPL', 1, 25],
            'heavy' => ['SPY', 8, 200],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_paired_phase_latency_cpu_memory_and_queries_preserve_identical_payloads(string $symbol, int $expirationCount, int $strikeCount): void
    {
        $manifestBuildMs = $this->fixture($symbol, $expirationCount, $strikeCount);
        $expectedHash = null;
        $expectedPayload = null;
        $results = [];
        foreach (['legacy', 'gex024', 'gex025'] as $phase) {
            config()->set('gex_performance.expiration_universe_enabled', $phase !== 'legacy');
            config()->set('eod_snapshot_health.read_enabled', $phase === 'gex025');
            $cold = [];
            $warm = [];
            foreach (['cold' => 3, 'warm' => 10] as $mode => $count) {
                for ($sample = 0; $sample < $count; $sample++) {
                    [$payload, $metrics] = $this->measure($symbol, $mode === 'cold');
                    $hash = hash('sha256', CanonicalJson::encode($payload));
                    $expectedHash ??= $hash;
                    $expectedPayload ??= $payload;
                    $this->assertSame($expectedHash, $hash, $symbol.' '.$phase.' '.$mode.' payload hash');
                    $this->assertSame($expectedPayload, $payload);
                    if ($phase === 'gex025' && $mode === 'warm') {
                        $this->assertSame(0, $metrics['market_sql']);
                    }
                    if ($mode === 'cold') {
                        $cold[] = $metrics;
                    } else {
                        $warm[] = $metrics;
                    }
                }
            }
            $results[$phase] = ['cold' => $this->summarize($cold), 'warm' => $this->summarize($warm)];
        }

        $this->assertSame(9, $results['legacy']['warm']['samples'][0]['market_sql']);
        $this->assertSame(3, $results['gex024']['warm']['samples'][0]['market_sql']);
        $this->assertSame(0, $results['gex025']['warm']['samples'][0]['market_sql']);
        fwrite(STDOUT, PHP_EOL.'gex_snapshot_phase_benchmark='.json_encode([
            'scope' => 'local_synthetic_mysql_same_fixture',
            'symbol' => $symbol,
            'expiration_count' => $expirationCount,
            'strike_count' => $strikeCount,
            'history_days' => 4,
            'raw_rows' => $expirationCount * $strikeCount * 2 * 4,
            'selected_rows' => $expirationCount * $strikeCount * 2,
            'manifest_build_ms' => $manifestBuildMs,
            'payload_sha256' => $expectedHash,
            'cpu_clock' => function_exists('getrusage') ? 'process_user_plus_system' : 'unavailable',
            'sql_logging' => 'enabled_equally_for_all_phases',
            'phases' => $results,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function fixture(string $symbol, int $expirationCount, int $strikeCount): float
    {
        $health = app(EodSnapshotHealth::class);
        $token = $health->begin($symbol, 'fixture:paired-phase');
        $expirationDates = ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14', '2026-09-15'];
        foreach (array_slice($expirationDates, 0, $expirationCount) as $expirationIndex => $expirationDate) {
            $id = DB::table('option_expirations')->insertGetId([
                'symbol' => $symbol, 'expiration_date' => $expirationDate, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['2026-08-21', '2026-08-28', '2026-09-03', '2026-09-04'] as $dayIndex => $date) {
                $rows = [];
                foreach (range(0, $strikeCount - 1) as $strikeIndex) {
                    foreach (['call', 'put'] as $side) {
                        $rows[] = [
                            'expiration_id' => $id, 'data_date' => $date,
                            'strike' => number_format(100.25 + $strikeIndex * 0.5, 2, '.', ''),
                            'option_type' => $side,
                            'open_interest' => ($side === 'call' ? 20 : 10) + $expirationIndex + $dayIndex + $strikeIndex % 7,
                            'volume' => ($side === 'call' ? 8 : 6) + $dayIndex,
                            'gamma' => $strikeIndex % 17 === 0 ? null : 0.01,
                            'underlying_price' => $strikeIndex % 19 === 0 ? null : 100,
                            'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('option_chain_data')->insert($chunk);
                }
            }
        }
        $health->complete($token);
        $version = 'paired-phase-'.$symbol;
        $issuedAt = 2000000;
        $this->assertTrue($health->certify($symbol, $version, $issuedAt));
        $head = $health->head($symbol);
        $start = hrtime(true);
        $this->assertNotNull($health->rebuild($symbol, $head['revision'], $version, $health->policy()));
        $buildMs = round((hrtime(true) - $start) / 1000000, 3);
        app(EodCacheVersion::class)->publish([$symbol], [EodCacheVersion::DOMAIN_GEX], $version, $issuedAt);

        return $buildMs;
    }

    private function measure(string $symbol, bool $refresh): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(false);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $cpuBefore = $this->cpuMilliseconds();
        $start = hrtime(true);
        try {
            $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
                'symbol' => $symbol, 'timeframe' => '30d', 'refresh' => $refresh,
            ]));
            $elapsedMs = (hrtime(true) - $start) / 1000000;
            $cpuAfter = $this->cpuMilliseconds();
            $memoryPeak = memory_get_peak_usage(false);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $marketCount = count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '`option_expirations`') || str_contains($query['query'], '`option_chain_data`')));

        return [$payload, [
            'elapsed_ms' => round($elapsedMs, 3),
            'cpu_ms' => $cpuBefore !== null && $cpuAfter !== null ? round($cpuAfter - $cpuBefore, 3) : null,
            'memory_peak_bytes' => $memoryPeak,
            'memory_peak_delta_bytes' => max(0, $memoryPeak - $memoryBefore),
            'sql' => count($queries),
            'market_sql' => $marketCount,
        ]];
    }

    private function cpuMilliseconds(): ?float
    {
        if (! function_exists('getrusage')) {
            return null;
        }
        $usage = getrusage();
        if (! is_array($usage)) {
            return null;
        }

        return 1000 * ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'])
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    }

    private function summarize(array $samples): array
    {
        $elapsed = array_column($samples, 'elapsed_ms');
        sort($elapsed, SORT_NUMERIC);
        $cpu = array_values(array_filter(array_column($samples, 'cpu_ms'), static fn ($value): bool => $value !== null));

        return [
            'sample_count' => count($samples),
            'elapsed_p50_ms' => $elapsed[(int) floor((count($elapsed) - 1) / 2)],
            'elapsed_p95_ms' => $elapsed[(int) ceil(count($elapsed) * 0.95) - 1],
            'cpu_mean_ms' => $cpu === [] ? null : round(array_sum($cpu) / count($cpu), 3),
            'memory_peak_delta_max_bytes' => max(array_column($samples, 'memory_peak_delta_bytes')),
            'samples' => $samples,
        ];
    }
}
