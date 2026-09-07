<?php

namespace Tests\Feature;

use App\Http\Controllers\ExpiryController;
use App\Support\EodCacheVersion;
use App\Support\EodPublicationRepository;
use App\Support\ExpiryPressureBatch;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Gex028LegacyExpiryController;
use Tests\MySqlTestCase;

class Gex028ExpiryBatchTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('expiry_performance.batch_enabled', false);
        $this->travelTo(CarbonImmutable::parse('2026-09-06T15:00:00Z'));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->travelBack();
        parent::tearDown();
    }

    public static function sizes(): array
    {
        // The 250 provider-start budget is not a read endpoint limit. Also
        // prove the 500-symbol query unit and an accepted cross-unit request.
        return [[1], [15], [250], [500], [501]];
    }

    #[DataProvider('sizes')]
    public function test_frozen_oracle_and_rollback_match_with_bounded_queries_and_no_symbol_cap(int $size): void
    {
        $symbols = $this->fixture($size);
        $request = array_merge(array_reverse($symbols), [strtolower($symbols[0]), ' '.$symbols[0].' ']);
        [$legacy, $legacyQueries] = $this->measure(fn () => $this->response($request, 3, legacy: true));
        $this->assertCount(1 + 2 * $size, $this->marketQueries($legacyQueries));
        Cache::store('array')->flush();
        config()->set('expiry_performance.batch_enabled', true);

        [$candidate, $candidateQueries] = $this->measure(fn () => $this->response($request));
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertSame(200, $candidate->getStatusCode());
        $items = $this->payload($candidate)['items'];
        $this->assertCount($size, $items);
        $this->assertSame(array_reverse($symbols), array_keys($items));
        $this->assertCount(3 * (int) ceil($size / 500), $this->marketQueries($candidateQueries));
        $this->assertCount($size > 1 ? 1 : 0, $this->capabilityQueries($candidateQueries));
        foreach ($items as $item) {
            $this->assertSame(['data_date' => '2026-09-04', 'headline_pin' => 75], $item);
        }
        [$warm, $warmQueries] = $this->measure(fn () => $this->response($request));
        $this->assertSame($candidate->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($warmQueries));

        config()->set('expiry_performance.batch_enabled', false);
        [$rollback, $rollbackQueries] = $this->measure(fn () => $this->response($request));
        $this->assertSame($legacy->getContent(), $rollback->getContent());
        $this->assertSame(array_column($this->marketQueries($legacyQueries), 'query'), array_column($this->marketQueries($rollbackQueries), 'query'));
        Bus::assertNothingDispatched();
    }

    public function test_mixed_missing_stale_zero_spot_and_future_only_symbols_keep_exact_per_symbol_states(): void
    {
        $symbols = $this->fixture(15, mixed: true);
        $request = array_merge([$symbols[4], strtolower($symbols[0]), 'BAD SYMBOL', '', null, '??'], array_reverse($symbols));
        $legacy = $this->response($request, legacy: true);
        config()->set('expiry_performance.batch_enabled', true);
        $candidate = $this->response($request);
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $items = $this->payload($candidate)['items'];
        $this->assertSame(['data_date' => null, 'headline_pin' => null], $items[$symbols[2]]);
        $this->assertSame(['data_date' => null, 'headline_pin' => null], $items[$symbols[3]], 'Future-only stays missing in a mixed anchored batch.');
        $this->assertSame(['data_date' => '2026-09-03', 'headline_pin' => 12], $items[$symbols[1]]);
        $this->assertSame(['data_date' => '2026-09-07', 'headline_pin' => 88], $items[$symbols[4]], 'A spot on another date does not validate the anchored snapshot.');
        $this->assertSame(['data_date' => '2026-09-04', 'headline_pin' => null], $items[$symbols[5]]);
        $this->assertSame(['data_date' => null, 'headline_pin' => null], $items['BAD SYMBOL']);
        $this->assertSame(['data_date' => null, 'headline_pin' => null], $items['']);
        $this->assertSame(array_values(array_unique(array_map([Symbols::class, 'canon'], $request))), array_keys($items));
    }

    public function test_case_insensitive_stored_rows_fold_into_requested_keys_only(): void
    {
        $symbols = $this->fixture(2);
        DB::table('expiry_pressure')->where('symbol', $symbols[0])->where('data_date', '2026-09-04')
            ->where('exp_date', '2026-09-07')->update(['symbol' => strtolower($symbols[0])]);
        DB::table('expiry_pressure')->where('symbol', $symbols[0])->where('data_date', '2026-09-04')
            ->where('exp_date', '2026-09-09')->update(['symbol' => strtolower($symbols[0]).' ']);
        $legacy = $this->response($symbols, legacy: true);
        $this->assertSame(75, $this->payload($legacy)['items'][$symbols[0]]['headline_pin']);
        config()->set('expiry_performance.batch_enabled', true);
        $candidate = $this->response($symbols);
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertSame($symbols, array_keys($this->payload($candidate)['items']));
    }

    public function test_global_empty_anchor_rule_is_preserved_across_internal_chunks(): void
    {
        $symbols = $this->fixture(501);
        DB::table('expiry_pressure')->whereIn('symbol', array_slice($symbols, 0, 500))->where('data_date', '<=', '2026-09-04')->delete();
        $legacy = $this->response($symbols, legacy: true);
        config()->set('expiry_performance.batch_enabled', true);
        $candidate = $this->response($symbols);
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $items = $this->payload($candidate)['items'];
        $this->assertSame(['data_date' => null, 'headline_pin' => null], $items[$symbols[0]]);
        $this->assertSame(['data_date' => '2026-09-04', 'headline_pin' => 75], $items[$symbols[500]]);

        DB::table('expiry_pressure')->where('data_date', '<=', '2026-09-04')->delete();
        Cache::store('array')->flush();
        $legacy = $this->response($symbols, legacy: true);
        $candidate = $this->response($symbols);
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        foreach ($this->payload($candidate)['items'] as $item) {
            $this->assertSame(['data_date' => '2026-09-07', 'headline_pin' => 88], $item);
        }
    }

    public static function calendars(): array
    {
        return [
            ['2026-09-04T20:14:59Z', 3, '2026-09-03', 12],
            ['2026-09-04T20:15:00Z', 3, '2026-09-04', 75],
            ['2026-09-05T15:00:00Z', 3, '2026-09-04', 75],
            ['2026-09-06T15:00:00Z', 1, '2026-09-04', 50],
            ['2026-09-06T15:00:00Z', 0, '2026-09-04', 0],
            ['2026-09-06T15:00:00Z', -4, '2026-09-04', 0],
            ['2026-09-06T15:00:00Z', 'nonnumeric', '2026-09-04', 0],
            ['2026-09-06T15:00:00Z', '1.9', '2026-09-04', 50],
        ];
    }

    #[DataProvider('calendars')]
    public function test_cutoff_weekend_inclusive_weekday_and_days_coercion_are_unchanged(string $clock, mixed $days, string $date, int $headline): void
    {
        $symbols = $this->fixture(1);
        $this->travelTo(CarbonImmutable::parse($clock));
        $legacy = $this->response($symbols, $days, legacy: true);
        config()->set('expiry_performance.batch_enabled', true);
        $candidate = $this->response($symbols, $days);
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertSame(['data_date' => $date, 'headline_pin' => $headline], $this->payload($candidate)['items'][$symbols[0]]);
    }

    public function test_cache_ttl_publication_invalidation_and_requested_order_remain_compatible(): void
    {
        $symbols = $this->fixture(2);
        config()->set('expiry_performance.batch_enabled', true);
        $original = $this->response($symbols);
        DB::table('expiry_pressure')->where('symbol', $symbols[0])->where('data_date', '2026-09-04')->where('exp_date', '2026-09-09')->update(['pin_score' => 91]);
        $this->assertSame($original->getContent(), $this->response($symbols)->getContent());
        app(EodCacheVersion::class)->publish([$symbols[0]], [EodCacheVersion::DOMAIN_EXPIRY_PRESSURE]);
        $updated = $this->response($symbols);
        $this->assertSame(91, $this->payload($updated)['items'][$symbols[0]]['headline_pin']);
        $this->assertSame(array_reverse($symbols), array_keys($this->payload($this->response(array_reverse($symbols)))['items']));
        $this->travel(3599)->seconds();
        [, $queries] = $this->measure(fn () => $this->response($symbols));
        $this->assertSame([], $this->marketQueries($queries));
        $this->travel(2)->seconds();
        [, $queries] = $this->measure(fn () => $this->response($symbols));
        $this->assertCount(3, $this->marketQueries($queries));
    }

    public function test_empty_missing_scalar_and_malformed_inputs_keep_the_legacy_contract(): void
    {
        $this->fixture(1);
        foreach ([[], ['MISSING'], ['bad symbol', '', null, '??'], 'S0000', ['0', '00', '0']] as $input) {
            $legacy = $this->response($input, legacy: true);
            config()->set('expiry_performance.batch_enabled', true);
            $candidate = $this->response($input);
            $this->assertSame($legacy->getContent(), $candidate->getContent());
        }
        [, $queries] = $this->measure(fn () => $this->response([]));
        $this->assertSame([], $this->marketQueries($queries));
        foreach ([false, true] as $enabled) {
            config()->set('expiry_performance.batch_enabled', $enabled);
            try {
                $this->response([['nested-symbol']]);
                $this->fail('The legacy malformed nested input must not be silently accepted.');
            } catch (\TypeError $exception) {
                $this->assertStringContainsString('Symbols::canon()', $exception->getMessage());
            }
        }
        Bus::assertNothingDispatched();
    }

    public function test_durable_publication_reads_preserve_mixed_invalid_symbol_and_batch_contracts(): void
    {
        $symbols = $this->fixture(15, mixed: true);
        config()->set(['eod_publications.write_enabled' => false, 'eod_publications.read_enabled' => false]);
        app(EodPublicationRepository::class)->prepare([], 1000000);
        config()->set(['eod_publications.write_enabled' => true, 'eod_publications.read_enabled' => true]);
        $input = array_merge(['BAD SYMBOL', '??', '', null], array_reverse($symbols), [strtolower($symbols[0])]);
        $legacy = $this->response($input, legacy: true);
        config()->set('expiry_performance.batch_enabled', true);
        [$candidate, $queries] = $this->measure(fn () => $this->response($input));
        $this->assertSame($legacy->getContent(), $candidate->getContent());
        $this->assertCount(3, $this->marketQueries($queries));
        $this->assertCount(1, $this->capabilityQueries($queries));
        $this->assertCount(5, $queries, 'Three market statements, one schema capability check, and one durable version query.');
        [$warm, $queries] = $this->measure(fn () => $this->response($input));
        $this->assertSame($legacy->getContent(), $warm->getContent());
        $this->assertSame([], $this->marketQueries($queries));
        $this->assertCount(1, $queries, 'A warm response still validates its durable publication version.');
    }

    public function test_paired_full_watchlist_query_plans_row_reads_and_latency_keep_the_same_payload(): void
    {
        $symbols = $this->fixture(500);
        $expected = null;
        $phases = [];
        foreach (['legacy', 'set_based'] as $phase) {
            config()->set('expiry_performance.batch_enabled', $phase === 'set_based');
            $cold = [];
            $warm = [];
            $plans = [];
            $workByCategory = [];
            foreach (['cold' => 5, 'warm' => 10] as $mode => $count) {
                for ($sample = 0; $sample < $count; $sample++) {
                    if ($mode === 'cold') {
                        Cache::store('array')->flush();
                    }
                    $before = $this->readCounters();
                    $started = hrtime(true);
                    [$response, $queries] = $this->measure(fn () => $this->response($symbols, legacy: $phase === 'legacy'));
                    $elapsed = (hrtime(true) - $started) / 1_000_000;
                    $after = $this->readCounters();
                    $expected ??= $response->getContent();
                    $this->assertSame($expected, $response->getContent());
                    $marketQueries = $this->marketQueries($queries);
                    $metric = [
                        'elapsed_ms' => round($elapsed, 3), 'market_sql' => count($marketQueries),
                        'total_sql' => count($queries), 'schema_capability_sql' => count($this->capabilityQueries($queries)),
                        'handler_read_operations' => $after['handler_reads'] - $before['handler_reads'],
                        'statement_rows_examined_delta' => $before['rows_examined'] !== null && $after['rows_examined'] !== null
                            ? $after['rows_examined'] - $before['rows_examined'] : null,
                    ];
                    if ($mode === 'cold') {
                        $cold[] = $metric;
                        $this->assertCount($phase === 'legacy' ? 1001 : 3, $marketQueries);
                        if ($sample === 0) {
                            $workByCategory = $this->replayWorkByCategory($queries);
                            foreach (array_slice($marketQueries, 0, 3) as $query) {
                                $plans[] = $this->queryPlan($query);
                            }
                        }
                    } else {
                        $warm[] = $metric;
                        $this->assertSame([], $marketQueries);
                    }
                }
            }
            $phases[$phase] = ['cold' => $this->sampleSummary($cold), 'warm' => $this->sampleSummary($warm),
                'work_by_query_category' => $workByCategory, 'representative_plans' => $plans];
        }
        fwrite(STDOUT, PHP_EOL.'gex028_expiry_batch_benchmark='.json_encode([
            'scope' => 'local_synthetic_mysql_array_cache', 'symbols' => 500,
            'pressure_rows' => 3000, 'chain_rows' => 3000,
            'legacy_source_sha256' => Gex028LegacyExpiryController::SOURCE_SHA256,
            'payload_sha256' => hash('sha256', $expected),
            'samples' => ['cold_per_phase' => 5, 'warm_per_phase' => 10],
            'measurement_notes' => 'SQL logging is enabled equally. Small-sample p95 uses nearest rank. Handler counters are read operations, not rows examined. Optional performance-schema rows include counter-query overhead. Plan rows are optimizer estimates.',
            'phases' => $phases,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $legacyReads = array_column($phases['legacy']['cold']['samples'], 'handler_read_operations');
        $candidateReads = array_column($phases['set_based']['cold']['samples'], 'handler_read_operations');
        sort($legacyReads, SORT_NUMERIC);
        sort($candidateReads, SORT_NUMERIC);
        $this->assertLessThan($legacyReads[2], $candidateReads[2],
            'Batching must reduce measured median database read work, not only SQL statement count.');
    }

    public function test_missing_or_concurrently_removed_compatible_index_uses_exact_legacy_spot_fallback(): void
    {
        $symbols = $this->fixture(15, mixed: true);
        $legacy = $this->response($symbols, legacy: true);
        foreach ([null, 'index_removed_after_capability_check'] as $index) {
            $this->app->instance(ExpiryPressureBatch::class, new class($index) extends ExpiryPressureBatch
            {
                public function __construct(private ?string $index) {}

                protected function compatibleSpotIndex(): ?string
                {
                    return $this->index;
                }
            });
            config()->set('expiry_performance.batch_enabled', true);
            Cache::store('array')->flush();
            [$candidate, $queries] = $this->measure(fn () => $this->response($symbols));
            $this->assertSame($legacy->getContent(), $candidate->getContent());
            $this->assertGreaterThan(3, count($this->marketQueries($queries)), 'Legacy fallback remains correct but is not the optimized path.');
            $this->assertNotEmpty(array_filter($queries, static fn ($query): bool => str_contains($query['query'], 'select exists(')));
        }
    }

    public function test_heavy_historical_chains_keep_early_exit_work_bounded(): void
    {
        $symbols = $this->fixture(15);
        $chainRows = [];
        $inserted = 0;
        $timestamp = now()->toDateTimeString();
        foreach (['2026-09-25', '2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23', '2026-10-30'] as $expiration) {
            $id = DB::table('option_expirations')->insertGetId(['symbol' => $symbols[0],
                'expiration_date' => $expiration, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['2026-08-26', '2026-08-28', '2026-09-01', '2026-09-04'] as $date) {
                foreach (range(200, 599) as $strike) {
                    foreach (['call', 'put'] as $side) {
                        $chainRows[] = ['expiration_id' => $id, 'data_date' => $date,
                            'strike' => $strike + 0.25, 'option_type' => $side,
                            'open_interest' => 100000, 'underlying_price' => 0,
                            'created_at' => $timestamp, 'updated_at' => $timestamp];
                        if (count($chainRows) === 500) {
                            DB::table('option_chain_data')->insert($chainRows);
                            $inserted += count($chainRows);
                            $chainRows = [];
                        }
                    }
                }
            }
        }
        if ($chainRows !== []) {
            DB::table('option_chain_data')->insert($chainRows);
            $inserted += count($chainRows);
        }
        $metrics = [];
        foreach (['one_heavy' => [$symbols[0]], 'mixed_15' => $symbols] as $shape => $input) {
            $expected = null;
            foreach (['legacy', 'set_based'] as $phase) {
                config()->set('expiry_performance.batch_enabled', $phase === 'set_based');
                Cache::store('array')->flush();
                $before = $this->readCounters();
                $started = hrtime(true);
                [$response, $queries] = $this->measure(fn () => $this->response($input, legacy: $phase === 'legacy'));
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                $after = $this->readCounters();
                $expected ??= $response->getContent();
                $this->assertSame($expected, $response->getContent());
                $metrics[$shape][$phase] = ['elapsed_ms' => round($elapsed, 3),
                    'market_sql' => count($this->marketQueries($queries)),
                    'total_sql' => count($queries), 'schema_capability_sql' => count($this->capabilityQueries($queries)),
                    'handler_read_operations' => $after['handler_reads'] - $before['handler_reads']];
                if ($shape === 'one_heavy') {
                    $this->assertSame([], $this->capabilityQueries($queries));
                }
            }
        }
        fwrite(STDOUT, PHP_EOL.'gex028_heavy_chain_probe='.json_encode([
            'scope' => 'local_synthetic_mysql_array_cache', 'additional_historical_chain_rows' => $inserted,
            'additional_expirations' => 6, 'additional_history_dates' => 4, 'measurements' => $metrics,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        foreach ($metrics as $measurement) {
            $this->assertLessThan(1000, $measurement['set_based']['handler_read_operations'],
                'Positive spot existence must not scan the additional 19,200 historical contract rows.');
        }
    }

    private function replayWorkByCategory(array $queries): array
    {
        $groups = [];
        foreach ($queries as $query) {
            $sql = $query['query'];
            $category = str_contains($sql, 'information_schema') ? 'capability'
                : (str_contains($sql, '`option_chain_data`') ? 'spot'
                    : (str_contains($sql, 'MAX(data_date)') || str_contains($sql, 'MAX(CASE') ? 'dates' : 'headlines'));
            $groups[$category][] = $query;
        }
        $result = [];
        foreach ($groups as $category => $statements) {
            $before = $this->readCounters();
            $returned = 0;
            foreach ($statements as $statement) {
                $returned += count(DB::select($statement['query'], $statement['bindings']));
            }
            $after = $this->readCounters();
            $result[$category] = ['sql' => count($statements), 'returned_rows' => $returned,
                'handler_read_operations' => $after['handler_reads'] - $before['handler_reads']];
        }

        return $result;
    }

    private function readCounters(): array
    {
        $handlers = DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'");
        $reads = array_sum(array_map(static fn ($row): int => (int) $row->Value, $handlers));
        $rows = null;
        try {
            $counter = DB::selectOne("SELECT SUM(SUM_ROWS_EXAMINED) AS examined FROM performance_schema.events_statements_summary_by_thread_by_event_name WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()) AND EVENT_NAME = 'statement/sql/select'");
            $rows = $counter?->examined !== null && (int) $counter->examined > 0 ? (int) $counter->examined : null;
        } catch (\Throwable) {
            // Some local MySQL installations do not enable these counters.
        }

        return ['handler_reads' => $reads, 'rows_examined' => $rows];
    }

    private function sampleSummary(array $samples): array
    {
        $times = array_column($samples, 'elapsed_ms');
        sort($times, SORT_NUMERIC);

        return ['p50_ms' => $times[(int) ceil(count($times) * 0.5) - 1],
            'p95_ms' => $times[(int) ceil(count($times) * 0.95) - 1], 'samples' => $samples];
    }

    private function queryPlan(array $query): array
    {
        $raw = DB::selectOne('EXPLAIN FORMAT=JSON '.$query['query'], $query['bindings']);
        $decoded = json_decode((string) array_values((array) $raw)[0], true, flags: JSON_THROW_ON_ERROR);
        $tables = [];
        $walk = function (array $node) use (&$walk, &$tables): void {
            if (isset($node['table_name'])) {
                $table = preg_replace('/^<derived[0-9]+>$/', '<derived>', $node['table_name']);
                $identity = $table.'|'.($node['access_type'] ?? '').'|'.($node['key'] ?? '');
                $tables[$identity] ??= ['table' => $table, 'access' => $node['access_type'] ?? null,
                    'index' => $node['key'] ?? null, 'join_buffer' => $node['using_join_buffer'] ?? null,
                    'nodes' => 0, 'estimated_rows_per_scan_sum' => 0];
                $tables[$identity]['nodes']++;
                $tables[$identity]['estimated_rows_per_scan_sum'] += $node['rows_examined_per_scan'] ?? 0;
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($decoded);

        return ['sql_sha256' => hash('sha256', $query['query']), 'bindings' => count($query['bindings']), 'tables' => array_values($tables)];
    }

    /** All data is synthetic; no production symbol snapshots or metadata. */
    private function fixture(int $count, bool $mixed = false): array
    {
        $symbols = array_map(static fn (int $i): string => 'S'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(0, $count - 1));
        DB::table('option_expirations')->insert(array_map(static fn (string $symbol): array => [
            'symbol' => $symbol, 'expiration_date' => '2026-09-18', 'created_at' => now(), 'updated_at' => now(),
        ], $symbols));
        $ids = DB::table('option_expirations')->whereIn('symbol', $symbols)->pluck('id', 'symbol');
        $pressure = [];
        $chains = [];
        foreach ($symbols as $index => $symbol) {
            $shape = $mixed ? $index % 7 : 0;
            $dates = [
                ['2026-09-03', '2026-09-03', 12], ['2026-09-04', '2026-09-04', 0],
                ['2026-09-04', '2026-09-07', 50], ['2026-09-04', '2026-09-09', 75],
                ['2026-09-04', '2026-09-10', 99], ['2026-09-07', '2026-09-07', 88],
            ];
            if ($shape === 1) {
                $dates = [$dates[0]];
            }
            if ($shape === 2) {
                $dates = [];
            }
            if ($shape === 3) {
                $dates = [$dates[5]];
            }
            if ($shape === 5) {
                $dates = [['2026-09-04', '2026-12-18', 100]];
            }
            foreach ($dates as [$date, $expiration, $score]) {
                $pressure[] = ['symbol' => $symbol, 'data_date' => $date, 'exp_date' => $expiration,
                    'pin_score' => $score, 'clusters_json' => '[]', 'max_pain' => null, 'source_chain_date' => $date,
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (['2026-09-03', '2026-09-04'] as $date) {
                $chains[] = ['expiration_id' => $ids[$symbol], 'data_date' => $date,
                    'strike' => '100.25', 'option_type' => 'call', 'open_interest' => 10,
                    'underlying_price' => $shape === 4 && $date === '2026-09-04' ? 0 : 100,
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        // Positive spot in the first row: the heavy fixture should not require
        // reading thousands of contracts just to establish spot availability.
        for ($index = 0; $index < 2000; $index++) {
            $chains[] = ['expiration_id' => $ids[$symbols[0]], 'data_date' => '2026-09-04',
                'strike' => 200 + $index, 'option_type' => 'put', 'open_interest' => 100000,
                'underlying_price' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($pressure, 500) as $chunk) {
            DB::table('expiry_pressure')->insert($chunk);
        }
        foreach (array_chunk($chains, 500) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }

        return $symbols;
    }

    private function response(mixed $symbols, mixed $days = 3, bool $legacy = false)
    {
        return ($legacy ? new Gex028LegacyExpiryController : new ExpiryController)->pressureBatch(
            Request::create('/api/expiry-pressure/batch', 'GET', compact('symbols', 'days'))
        );
    }

    private function payload($response): array
    {
        $this->assertSame(200, $response->getStatusCode());

        return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function measure(callable $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $callback();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$response, $queries];
    }

    private function marketQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], '`expiry_pressure`') || str_contains($query['query'], '`option_expirations`') || str_contains($query['query'], '`option_chain_data`')));
    }

    private function capabilityQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'information_schema')));
    }
}
