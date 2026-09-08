<?php

namespace Tests\Feature;

use App\Http\Controllers\IntradayController;
use App\Services\WallService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Tests\Fixtures\Gex029LegacyWallService;
use Tests\MySqlTestCase;

class Gex029WallSpotTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('intraday_freshness.enabled', false);
        config()->set('symbol_bootstrap.enabled', false);
        $this->travelTo(CarbonImmutable::parse('2026-09-04T15:00:00Z'));
        Bus::fake();
        Http::preventStrayRequests();
        Log::swap(new NullLogger);
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->travelBack();
        parent::tearDown();
    }

    public static function quoteCases(): array
    {
        // These are the existing WallService rules, not a new session/source
        // policy: stale is allowed when maxAge is null; future and ingested-at
        // quotes are currently accepted. Keep those behaviors explicit.
        return [
            'live quote preferred' => [101.25, -1, 95.5, -1, 30, 101.25, 1],
            'exact age boundary' => [101.25, -30, null, null, 30, 101.25, 1],
            'stale quote, fresh fallback' => [101.25, -31, 95.5, -1, 30, 95.5, 2],
            'both stale' => [101.25, -31, 95.5, -31, 30, null, 2],
            'stale allowed without age limit' => [101.25, -5000, 95.5, -1, null, 101.25, 1],
            'missing quote, fresh fallback' => [null, null, 95.5, -1, 30, 95.5, 2],
            'missing everything' => [null, null, null, null, 30, null, 2],
            'zero quote' => [0.0, -1, 95.5, -1, 30, 95.5, 2],
            'negative quote' => [-1.0, -1, 95.5, -1, 30, 95.5, 2],
            'decimal zero fallback remains zero' => [null, null, 0.0, -1, 30, 0.0, 2],
            'legacy negative fallback unchanged' => [null, null, -1.0, -1, 30, -1.0, 2],
            'future quote remains accepted' => [101.25, 2, null, null, 30, 101.25, 1],
            'zero age accepts current instant' => [101.25, 0, null, null, 0, 101.25, 1],
        ];
    }

    #[DataProvider('quoteCases')]
    public function test_real_composite_oracle_and_direct_spot_preserve_existing_freshness_fallback(?float $quote, ?int $quoteMinutes, ?float $fallback, ?int $fallbackMinutes, ?int $age, ?float $expected, int $queryCount): void
    {
        if ($quote !== null) {
            $this->quote($quote, $quoteMinutes);
        }
        if ($fallback !== null) {
            $this->snapshot($fallback, $fallbackMinutes);
        }
        $legacy = (new Gex029LegacyWallService)->currentPrice(' spy ', $age);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $actual = (new WallService)->currentPrice(' spy ', $age);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame($expected, $legacy);
        $this->assertSame($legacy, $actual);
        $this->assertCount($queryCount, $queries);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/option_live_counters|option_chain_data|option_expirations|intraday_option_volumes/i', $query['query']);
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_latest_eligible_fallback_is_used_and_the_service_does_not_cache_concurrent_publications(): void
    {
        $this->snapshot(91.5, -3);
        $this->snapshot(92.5, -2);
        $service = new WallService;
        $this->assertSame(92.5, $service->currentPrice('SPY', 30));
        $this->quote(101.25, -1);
        $this->assertSame(101.25, $service->currentPrice('SPY', 30));

        // Deterministically interleave a committed-to-this-test-transaction
        // publication between reads. This proves no per-instance stale spot
        // cache; it does not claim a cross-process transaction race test.
        DB::table('underlying_quotes')->where('symbol', 'SPY')->update(['last_price' => 102.5, 'asof' => now('UTC')]);
        $this->assertSame(102.5, $service->currentPrice('spy', 30));
        DB::table('underlying_quotes')->where('symbol', 'SPY')->update(['last_price' => 0]);
        $this->assertSame(92.5, $service->currentPrice('SPY', 30));
    }

    public static function freshnessModes(): array
    {
        return [[false, false], [true, false], [true, true]];
    }

    #[DataProvider('freshnessModes')]
    public function test_actual_composite_has_no_spot_and_all_intraday_wall_values_remain_identical(bool $freshnessEnabled, bool $knownSource): void
    {
        config()->set('intraday_freshness.enabled', $freshnessEnabled);
        $this->seedChain(3, 1, 2);
        if ($knownSource) {
            DB::table('intraday_refresh_states')->insert(['symbol' => 'SPY', 'trade_date' => '2026-09-04', 'source_asof' => now('UTC')->subMinute()]);
        }
        $response = app(IntradayController::class)->strikesComposite(Request::create('/api/intraday/strikes', 'GET', ['symbol' => 'SPY']));
        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        foreach (['spot', 'underlying_price', 'underlying', 'last'] as $field) {
            $this->assertArrayNotHasKey($field, $payload);
        }
        $this->assertNotEmpty($payload['items']);
        $legacy = new Gex029LegacyWallService;
        $candidate = new WallService;
        $this->assertSame($legacy->currentPrice('SPY', 30), $candidate->currentPrice('SPY', 30));
        $this->assertSame($legacy->intradayWalls('SPY', 30), $candidate->intradayWalls('SPY', 30));
        if ($freshnessEnabled && ! $knownSource) {
            $this->assertSame([], $candidate->intradayWalls('SPY', 30), 'Unknown provider time still suppresses age-limited intraday walls.');
        } else {
            $this->assertNotEmpty($candidate->intradayWalls('SPY', 30));
        }
    }

    public static function benchmarkSizes(): array
    {
        return ['ordinary' => [12, 1, 2], 'heavy' => [400, 4, 3]];
    }

    #[DataProvider('benchmarkSizes')]
    public function test_service_only_paired_cold_and_warm_query_rowwork_and_latency_proof(int $strikes, int $expirations, int $days): void
    {
        $this->seedChain($strikes, $expirations, $days);
        $samples = [];
        foreach (['cold' => 3, 'warm' => 10] as $state => $count) {
            for ($i = 0; $i < $count; $i++) {
                if ($state === 'cold') {
                    Cache::store('array')->flush();
                }
                foreach (['legacy', 'candidate'] as $mode) {
                    $sample = $this->measureSpot($mode === 'legacy');
                    $this->assertSame(100.0, $sample['spot']);
                    if ($mode === 'candidate') {
                        $this->assertSame(1, $sample['sql_count']);
                        $this->assertSame(0, $sample['option_sql_count']);
                    }
                    $samples[$state][$mode][] = $sample;
                }
                if ($state === 'cold') {
                    $this->assertLessThan($samples[$state]['legacy'][$i]['sql_count'], $samples[$state]['candidate'][$i]['sql_count']);
                    $this->assertLessThan($samples[$state]['legacy'][$i]['handler_reads'], $samples[$state]['candidate'][$i]['handler_reads']);
                } else {
                    $this->assertLessThanOrEqual($samples[$state]['legacy'][$i]['sql_count'], $samples[$state]['candidate'][$i]['sql_count']);
                    $this->assertLessThanOrEqual($samples[$state]['legacy'][$i]['handler_reads'], $samples[$state]['candidate'][$i]['handler_reads']);
                }
            }
        }
        fwrite(STDOUT, PHP_EOL.'gex029_wall_spot_benchmark='.json_encode([
            'scope' => 'WallService::currentPrice only; no repository caller or scanner route speedup claim',
            'environment' => 'local guarded MySQL; PHP '.PHP_VERSION.'; process-local array cache; optional intraday freshness metadata disabled',
            'fixture' => compact('strikes', 'expirations', 'days'),
            'samples' => $samples,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function quote(float $price, int $minutes): void
    {
        DB::table('underlying_quotes')->insert(['symbol' => 'SPY', 'last_price' => $price, 'source' => 'massive:ingested-at', 'asof' => now('UTC')->addMinutes($minutes), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function snapshot(float $price, int $minutes): void
    {
        DB::table('option_snapshots')->insert(['symbol' => 'SPY', 'ticker' => 'O:SPY260911C00100000', 'type' => 'call', 'strike' => 100, 'expiry' => '2026-09-11', 'bid' => 1, 'ask' => 2, 'mid' => 1.5, 'underlying_price' => $price, 'fetched_at' => now()->addMinutes($minutes)]);
    }

    private function seedChain(int $strikes, int $expirations, int $days): void
    {
        $this->quote(100, -1);
        $rows = $counters = [];
        for ($e = 0; $e < $expirations; $e++) {
            $expiry = now()->addDays(7 + $e * 7)->toDateString();
            $id = DB::table('option_expirations')->insertGetId(['symbol' => 'SPY', 'expiration_date' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
            for ($k = 0; $k < $strikes; $k++) {
                $strike = 99.5 + $k / 4;
                foreach (['call', 'put'] as $side) {
                    $oi = ($k % 2 === 0) === ($side === 'call') ? 1000 : 100;
                    $counters[] = ['symbol' => 'SPY', 'trade_date' => '2026-09-04', 'exp_date' => $expiry, 'strike' => $strike, 'option_type' => $side, 'volume' => 10 + $k, 'premium_usd' => 100, 'asof' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()];
                    for ($d = 1; $d <= $days; $d++) {
                        $rows[] = ['expiration_id' => $id, 'data_date' => now()->subDays($d)->toDateString(), 'strike' => $strike, 'option_type' => $side, 'open_interest' => $oi, 'volume' => 10, 'gamma' => .01, 'delta' => $side === 'call' ? .5 : -.5, 'iv' => .25, 'underlying_price' => 100, 'created_at' => now(), 'updated_at' => now()];
                    }
                }
            }
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('option_chain_data')->insert($chunk);
        }
        foreach (array_chunk($counters, 400) as $chunk) {
            DB::table('option_live_counters')->insert($chunk);
        }
    }

    private function measureSpot(bool $legacy): array
    {
        DB::disableQueryLog();
        $before = $this->handlerReads();
        DB::flushQueryLog();
        DB::enableQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $start = hrtime(true);
        $spot = ($legacy ? new Gex029LegacyWallService : new WallService)->currentPrice('SPY', 30);
        $elapsed = (hrtime(true) - $start) / 1e6;
        $peak = memory_get_peak_usage() - $memory;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return ['spot' => $spot, 'elapsed_ms' => round($elapsed, 4), 'peak_extra_bytes' => $peak, 'sql_count' => count($queries), 'option_sql_count' => count(array_filter($queries, fn ($q) => preg_match('/option_live_counters|option_chain_data|option_expirations|intraday_option_volumes/i', $q['query']))), 'handler_reads' => $this->handlerReads() - $before];
    }

    private function handlerReads(): int
    {
        return array_sum(array_map(fn ($row) => (int) $row->Value, DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'")));
    }
}
