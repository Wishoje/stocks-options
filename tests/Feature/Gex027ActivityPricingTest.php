<?php

namespace Tests\Feature;

use App\Http\Controllers\ActivityController;
use App\Support\ActivityPricingBatch;
use App\Support\EodPublicationRepository;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MySqlTestCase;

class Gex027ActivityPricingTest extends MySqlTestCase
{
    use RefreshDatabase {
        beginDatabaseTransaction as private beginOriginalDatabaseTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $quoteTableOwned = false;
        $addedColumns = [];
        if ($this->name() === 'test_option_quote_precedence_duplicate_and_missing_rows_do_not_fall_back_to_chain' && ! Schema::hasTable('option_quotes')) {
            Schema::create('option_quotes', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 16);
                $table->date('expiration_date');
                $table->decimal('strike', 14, 4);
                $table->string('option_type', 4);
                foreach (['bid', 'ask', 'mark', 'last'] as $column) {
                    $table->decimal($column, 12, 4)->nullable();
                }
            });
            $quoteTableOwned = true;
        }
        if ($this->name() === 'test_optional_chain_price_columns_retain_precedence_without_theoretical_fallback') {
            foreach (['mid_price', 'last_price', 'close', 'bid', 'ask'] as $column) {
                if (! Schema::hasColumn('option_chain_data', $column)) {
                    Schema::table('option_chain_data', fn (Blueprint $table) => $table->decimal($column, 12, 4)->nullable());
                    $addedColumns[] = $column;
                }
            }
        }
        // Capability DDL is outside the test transaction. Unlike temporary
        // tables, real tables can be read by every UNION branch in MySQL.
        $this->beginOriginalDatabaseTransaction();
        $this->beforeApplicationDestroyed(function () use ($quoteTableOwned, $addedColumns): void {
            if ($quoteTableOwned) {
                Schema::drop('option_quotes');
            }
            if ($addedColumns !== []) {
                Schema::table('option_chain_data', fn (Blueprint $table) => $table->dropColumn($addedColumns));
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-08 16:00:00', 'UTC'));
        config()->set('cache.default', 'array');
        config()->set('activity_performance.batch_pricing_enabled', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_pricing_oracle(): void
    {
        $this->seedPricingRows(10);
        // Freeze the first-spot/last-IV inputs observed when capturing the
        // legacy oracle. Mixed historical values are covered separately below:
        // the old query has no ORDER BY and can choose either existing index.
        DB::table('option_chain_data')->whereNotNull('underlying_price')->update(['underlying_price' => 104]);
        DB::table('option_chain_data')->update(['iv' => 0.21]);
        $oracle = trim(file_get_contents(base_path('tests/Fixtures/gex027-activity-legacy.json')));
        $this->assertSame($oracle, $this->response(false, 10));
        $this->assertSame($oracle, $this->response(true, 10));
    }

    #[DataProvider('pageSizes')]
    public function test_exact_payload_and_order_with_bounded_queries(int $count): void
    {
        $this->seedPricingRows($count);
        $before = $this->measure(false, $count);
        $after = $this->measure(true, $count);
        $this->assertSame($before['payload'], $after['payload']);
        $this->assertCount($count, json_decode($after['payload'], true)['items']);
        $this->assertLessThanOrEqual(5 + (int) ceil($count / 100), count($after['queries']));
        $this->assertGreaterThanOrEqual(2 + 7 * ($count - intdiv($count, 7)), count($before['queries']));
    }

    public static function pageSizes(): array
    {
        return [[1], [10], [100], [200], [501]];
    }

    #[DataProvider('filters')]
    public function test_filters_sorting_and_intraday_overrides_are_unchanged(array $query): void
    {
        $this->seedPricingRows(20);
        DB::table('option_live_counters')->insert([
            ['symbol' => 'FIXTURE', 'trade_date' => '2026-09-08', 'strike' => 100.25, 'option_type' => 'call', 'volume' => 1, 'premium_usd' => 1234.56],
            ['symbol' => 'FIXTURE', 'trade_date' => '2026-09-08', 'strike' => 100.25, 'option_type' => 'put', 'volume' => 999, 'premium_usd' => 7654.32],
        ]);
        $this->assertSame($this->response(false, 20, $query), $this->response(true, 20, $query));
    }

    public static function filters(): array
    {
        return [
            [['sort' => 'vol_oi']], [['sort' => 'premium']], [['only_side' => 'call']], [['only_side' => 'put']],
            [['min_vol' => 100, 'min_z' => 990, 'min_vol_oi' => 1.8]], [['min_premium' => 100000]],
            [['near_spot_pct' => 8, 'per_expiry' => 2]], [['exp' => '2030-01-06']],
            [['intraday' => true, 'only_side' => 'put', 'sort' => 'premium']], [['with_premium' => false]],
        ];
    }

    public function test_missing_or_invalid_theoretical_inputs_and_chain_average_fallback_preserve_output(): void
    {
        $this->seedPricingRows(10);
        DB::table('option_chain_data')->where('strike', 100.25)->delete();
        DB::table('option_chain_data')->where('strike', 100.5)->update(['iv' => null]);
        DB::table('option_chain_data')->where('strike', 100.75)->update(['iv' => 0]);
        DB::table('underlying_prices')->where('symbol', 'FIXTURE')->update(['close' => null]);
        $this->assertSame($this->response(false, 10), $this->response(true, 10));
        $this->assertCount(7, json_decode($this->response(true, 10), true)['items']);
    }

    public function test_duplicate_pricing_keys_are_loaded_once_and_fractional_strikes_stay_distinct(): void
    {
        $this->seedPricingRows(10);
        $rows = DB::table('unusual_activity')->orderBy('strike')->limit(2)->get(['exp_date', 'strike'])->all();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $batch = ActivityPricingBatch::load('FIXTURE', [$rows[0], $rows[0], $rows[1]]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(3, $queries);
        $this->assertSame(1, substr_count(end($queries)['query'], 'union all'));
        $this->assertCount(4, $batch->rows($rows[0]->exp_date, (float) $rows[0]->strike));
        $this->assertCount(4, $batch->rows($rows[1]->exp_date, (float) $rows[1]->strike));
    }

    public function test_disabled_premiums_stored_premiums_empty_and_warm_requests_do_not_load_pricing_inputs(): void
    {
        $empty = $this->measure(true, 10);
        $this->assertCount(1, $empty['queries']);
        $this->seedPricingRows(10);
        $disabled = $this->measure(true, 10, ['with_premium' => false]);
        $this->assertCount(2, $disabled['queries']);
        DB::table('unusual_activity')->where('symbol', 'FIXTURE')->update(['meta' => json_encode(['premium_usd' => 1000])]);
        $stored = $this->measure(true, 10);
        $this->assertCount(2, $stored['queries']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame($stored['payload'], $this->response(true, 10, [], false));
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_option_quote_precedence_duplicate_and_missing_rows_do_not_fall_back_to_chain(): void
    {
        $this->seedPricingRows(10);
        $this->assertSame($this->response(false, 10), $this->response(true, 10));
        $this->assertCount(1, json_decode($this->response(true, 10), true)['items']);
        $quotes = [
            ['mark' => 2, 'bid' => 3, 'ask' => 5, 'last' => 9], ['bid' => 1, 'ask' => 3, 'last' => 9],
            ['last' => 4], ['bid' => 5], ['ask' => 6], ['mark' => 0], ['mark' => -1], [],
            ['bid' => 0, 'ask' => -2], ['mark' => 2.3456],
        ];
        foreach (DB::table('unusual_activity')->orderBy('strike')->get() as $i => $row) {
            foreach (['call', 'put'] as $side) {
                DB::table('option_quotes')->insert(array_replace(['symbol' => 'FIXTURE', 'expiration_date' => $row->exp_date,
                    'strike' => $row->strike, 'option_type' => $side], $quotes[$i]));
            }
        }
        // Last quote wins, including a later all-null quote clearing a side.
        DB::table('option_quotes')->insert(['symbol' => 'FIXTURE', 'expiration_date' => '2030-01-03', 'strike' => 100.25, 'option_type' => 'call']);
        $this->assertSame($this->response(false, 10), $this->response(true, 10));
        $first = json_decode($this->response(true, 10), true)['items'][0];
        $this->assertSame(0, $first['meta']['call_prem']);
        $this->assertSame(1400, $first['meta']['put_prem']);
    }

    public function test_candidate_and_rollback_keep_separate_warm_response_namespaces(): void
    {
        $this->seedPricingRows(10);
        $legacy = $this->response(false, 10);
        DB::table('unusual_activity')->where('symbol', 'FIXTURE')->update([
            'meta' => json_encode(['call_vol' => 11, 'put_vol' => 7, 'total_vol' => 18, 'premium_usd' => 99999]),
        ]);
        // Without clearing either namespace, enabling the candidate must run
        // its own cold read instead of returning the legacy cached payload.
        $candidate = $this->response(true, 10, [], false);
        $this->assertNotSame($legacy, $candidate);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertSame($candidate, $this->response(true, 10, [], false));
            $this->assertSame($legacy, $this->response(false, 10, [], false));
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_optional_chain_price_columns_retain_precedence_without_theoretical_fallback(): void
    {
        $this->seedPricingRows(10);
        $this->assertSame($this->response(false, 10), $this->response(true, 10));
        $this->assertCount(1, json_decode($this->response(true, 10), true)['items']);
        $prices = [['mid_price' => 2, 'last_price' => 9], ['last_price' => 3, 'close' => 8], ['close' => 4],
            ['bid' => 1, 'ask' => 3], ['bid' => 5], ['ask' => 6], ['mid_price' => 0], ['mid_price' => -1], [], ['mid_price' => 2.3456]];
        foreach ($prices as $i => $values) {
            if ($values !== []) {
                DB::table('option_chain_data')->where('strike', 100 + ($i + 1) / 4)->update($values);
            }
        }
        $this->assertSame($this->response(false, 10), $this->response(true, 10));
    }

    public function test_maximum_ui_page_paired_latency_and_sql_time_report(): void
    {
        $this->seedPricingRows(200);
        $samples = ['legacy' => [], 'batch' => []];
        $pricingPlans = [];
        for ($i = -1; $i < 7; $i++) {
            $pair = [];
            foreach ($i % 2 === 0 ? [false, true] : [true, false] as $batch) {
                $mode = $batch ? 'batch' : 'legacy';
                $pair[$mode] = $this->measure($batch, 200, [], true);
                // Isolate market-row work from the legacy schema probes and
                // optional publication metadata. Replay the exact SELECTs
                // outside the endpoint timing, using only synthetic rows.
                $pair[$mode]['pricing_reads'] = $this->pricingReads($pair[$mode]['queries']);
                if ($i === -1) {
                    $pricingPlans[$mode] = $this->pricingPlans($pair[$mode]['queries']);
                }
            }
            $this->assertSame($pair['legacy']['payload'], $pair['batch']['payload']);
            if ($i >= 0) {
                foreach ($pair as $mode => $result) {
                    $samples[$mode][] = ['wall_ms' => $result['wall_ms'], 'sql_ms' => $result['sql_ms'],
                        'queries' => count($result['queries']), 'handler_reads' => $result['handler_reads'],
                        'pricing_reads' => $result['pricing_reads']];
                }
            }
        }
        $report = ['rows' => 200, 'samples_per_mode' => 7, 'warmups_per_mode' => 1,
            'payload_sha256' => hash('sha256', $pair['legacy']['payload']), 'pricing_explain' => $pricingPlans];
        foreach ($samples as $mode => $results) {
            $wall = array_column($results, 'wall_ms');
            $sql = array_column($results, 'sql_ms');
            $reads = array_map(static fn (array $sample): int => array_sum($sample['pricing_reads']['handler_reads']), $results);
            sort($wall);
            sort($sql);
            sort($reads);
            $report[$mode] = ['median_ms' => $wall[3], 'p95_ms' => $wall[6], 'sql_median_ms' => $sql[3],
                'sql_p95_ms' => $sql[6], 'pricing_handler_reads_median' => $reads[3], 'samples' => $results];
        }
        fwrite(STDOUT, "\nGEX027_ACTIVITY_BENCHMARK ".json_encode($report, JSON_THROW_ON_ERROR)."\n");
        $this->assertSame($pair['legacy']['pricing_reads']['returned_rows'], $pair['batch']['pricing_reads']['returned_rows']);
        // This is a synthetic access-work guard, not a latency assertion or a
        // production selectivity guarantee. Fewer round trips must not hide
        // repeated table scans in these exact-key pricing lookups.
        $this->assertLessThanOrEqual($report['legacy']['pricing_handler_reads_median'],
            $report['batch']['pricing_handler_reads_median']);
    }

    public function test_measurements_include_durable_publication_metadata_queries_when_enabled(): void
    {
        $this->seedPricingRows(10);
        config()->set('eod_publications.write_enabled', false);
        config()->set('eod_publications.read_enabled', false);
        app(EodPublicationRepository::class)->prepare([], 1_700_000_000_000_000);
        $baseline = $this->measure(true, 10);
        config()->set('eod_publications.write_enabled', true);
        config()->set('eod_publications.read_enabled', true);
        $legacy = $this->measure(false, 10);
        $candidate = $this->measure(true, 10);
        $this->assertSame($legacy['payload'], $candidate['payload']);
        $this->assertCount(count($baseline['queries']) + 1, $candidate['queries']);
        foreach ([$legacy, $candidate] as $result) {
            $metadata = array_filter($result['queries'], static fn (array $query): bool => str_contains($query['query'], 'eod_cache_publication_state'));
            $this->assertCount(1, $metadata);
            $this->assertSame(round(array_sum(array_column($result['queries'], 'time')), 3), $result['sql_ms']);
        }
    }

    private function measure(bool $batch, int $limit, array $extra = [], bool $includeHandlerReads = false): array
    {
        $before = $includeHandlerReads ? $this->handlerReads() : [];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        try {
            $payload = $this->response($batch, $limit, $extra);
            $wall = (hrtime(true) - $start) / 1_000_000;
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return ['payload' => $payload, 'queries' => $queries, 'wall_ms' => round($wall, 3),
            'sql_ms' => round(array_sum(array_column($queries, 'time')), 3),
            'handler_reads' => $includeHandlerReads ? $this->handlerReadDelta($before, $this->handlerReads()) : []];
    }

    private function handlerReads(): array
    {
        $out = [];
        foreach (DB::select("SHOW SESSION STATUS WHERE Variable_name LIKE 'Handler_read_%'") as $row) {
            $out[$row->Variable_name] = (int) $row->Value;
        }

        return $out;
    }

    private function handlerReadDelta(array $before, array $after): array
    {
        foreach ($after as $key => &$value) {
            $value -= $before[$key];
        }

        return $after;
    }

    private function pricingQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from `option_chain_data` as `o`')
            && str_contains($query['query'], '`o`.`strike` = ?')));
    }

    private function pricingReads(array $queries): array
    {
        $before = $this->handlerReads();
        $rows = 0;
        $queries = $this->pricingQueries($queries);
        foreach ($queries as $query) {
            $rows += count(DB::select($query['query'], $query['bindings']));
        }

        return ['queries' => count($queries), 'returned_rows' => $rows,
            'handler_reads' => $this->handlerReadDelta($before, $this->handlerReads())];
    }

    private function pricingPlans(array $queries): array
    {
        $shapes = [];
        foreach ($this->pricingQueries($queries) as $query) {
            $key = hash('sha256', $query['query']);
            if (isset($shapes[$key])) {
                $shapes[$key]['occurrences']++;

                continue;
            }
            $steps = [];
            foreach (DB::select('EXPLAIN '.$query['query'], $query['bindings']) as $row) {
                // Group identical UNION operands to keep evidence bounded.
                // No SQL text, bindings or market values enter the report.
                $step = array_intersect_key((array) $row, array_flip(['select_type', 'table', 'type', 'key', 'rows', 'filtered', 'Extra']));
                $stepKey = json_encode($step, JSON_THROW_ON_ERROR);
                if (isset($steps[$stepKey])) {
                    $steps[$stepKey]['operands']++;
                } else {
                    $steps[$stepKey] = $step + ['operands' => 1];
                }
            }
            $shapes[$key] = ['occurrences' => 1, 'steps' => array_values($steps)];
        }

        return array_values($shapes);
    }

    private function response(bool $batch, int $limit, array $extra = [], bool $clearCache = true): string
    {
        config()->set('activity_performance.batch_pricing_enabled', $batch);
        if ($clearCache) {
            Cache::flush();
        }
        $controller = new class extends ActivityController
        {
            protected function premiumTimestamp(): int
            {
                return strtotime('2026-09-08 16:00:00 UTC');
            }

            protected function premiumSpotDate(): string
            {
                return '2026-09-08';
            }
        };

        return $controller->index(Request::create('/api/ua', 'GET', array_replace([
            'symbol' => 'FIXTURE', 'limit' => $limit, 'per_expiry' => $limit,
            'min_z' => 0, 'min_vol_oi' => 0, 'min_premium' => 1,
        ], $extra)))->getContent();
    }

    private function seedPricingRows(int $count): void
    {
        DB::table('underlying_prices')->insert(['symbol' => 'FIXTURE', 'price_date' => '2026-09-08', 'close' => 110]);
        $expirations = [];
        for ($e = 1; $e <= 5; $e++) {
            $date = '2030-01-'.str_pad((string) ($e * 3), 2, '0', STR_PAD_LEFT);
            $expirations[$date] = DB::table('option_expirations')->insertGetId(['symbol' => 'FIXTURE', 'expiration_date' => $date]);
        }
        $dates = array_keys($expirations);
        for ($i = 1; $i <= $count; $i++) {
            $date = $dates[($i - 1) % 5];
            $strike = 100 + $i / 4;
            $meta = ['call_vol' => $i * 11, 'put_vol' => $i * 7, 'total_vol' => $i * 18, 'fixture' => $i];
            if ($i % 7 === 0) {
                $meta['premium_usd'] = 12345.67;
            }
            DB::table('unusual_activity')->insert(['symbol' => 'FIXTURE', 'data_date' => '2026-09-08', 'exp_date' => $date,
                'strike' => $strike, 'z_score' => 1000 - $i, 'vol_oi' => $i / 10, 'meta' => json_encode($meta)]);
            foreach (['2026-09-08', '2026-09-04'] as $snapshot) {
                foreach (['call', 'put'] as $side) {
                    DB::table('option_chain_data')->insert(['expiration_id' => $expirations[$date], 'data_date' => $snapshot,
                        'option_type' => $side, 'strike' => $strike, 'iv' => $snapshot === '2026-09-08' ? 0.21 : 0.22,
                        'underlying_price' => $i % 3 === 0 ? null : ($snapshot === '2026-09-08' ? 105 : 104)]);
                }
            }
        }
    }
}
