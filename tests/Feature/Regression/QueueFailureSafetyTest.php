<?php

namespace Tests\Feature\Regression;

use App\Jobs\BuildAiExportJob;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PricesBackfillJob;
use App\Models\AiExport;
use App\Models\User;
use App\Services\AiExportBuilder;
use App\Support\PolygonClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\MySqlTestCase;

class QueueFailureSafetyTest extends MySqlTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-18 13:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_older_quote_cannot_replace_a_newer_source_timestamp(): void
    {
        DB::table('underlying_quotes')->insert([
            'symbol' => 'SPY',
            'source' => 'massive-v2-snapshot',
            'last_price' => 100,
            'prev_close' => 99,
            'asof' => '2026-03-18 16:55:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('underlyingQuote')->once()->with('SPY')->andReturn([
            'source' => 'massive-v2-snapshot',
            'last_price' => 90,
            'prev_close' => 89,
            'asof' => '2026-03-18 16:50:00',
        ]);
        $this->app->instance(PolygonClient::class, $client);

        (new FetchUnderlyingQuotesJob(['SPY']))->handle();

        $quote = DB::table('underlying_quotes')->where('symbol', 'SPY')->first();
        $this->assertSame(100.0, (float) $quote->last_price);
        $this->assertSame('2026-03-18 16:55:00', (string) $quote->asof);
    }

    public function test_a_new_symbol_without_source_time_remains_replaceable_by_real_provider_time(): void
    {
        $first = Mockery::mock(PolygonClient::class);
        $first->shouldReceive('underlyingQuote')->once()->with('NEW')->andReturn([
            'source' => 'massive-v2-snapshot',
            'last_price' => 25,
            'prev_close' => 24,
            'asof' => null,
        ]);
        $this->app->instance(PolygonClient::class, $first);

        (new FetchUnderlyingQuotesJob(['NEW']))->handle();

        $synthetic = DB::table('underlying_quotes')->where('symbol', 'NEW')->first();
        $this->assertSame('massive-v2-snapshot:ingested-at', $synthetic->source);
        $this->assertSame(25.0, (float) $synthetic->last_price);

        $second = Mockery::mock(PolygonClient::class);
        $second->shouldReceive('underlyingQuote')->once()->with('NEW')->andReturn([
            'source' => 'massive-v2-snapshot',
            'last_price' => 26,
            'prev_close' => 24,
            // Provider data may trail the local ingestion clock.
            'asof' => '2026-03-18 16:59:00',
        ]);
        $this->app->instance(PolygonClient::class, $second);

        (new FetchUnderlyingQuotesJob(['NEW']))->handle();

        $verified = DB::table('underlying_quotes')->where('symbol', 'NEW')->first();
        $this->assertSame('massive-v2-snapshot', $verified->source);
        $this->assertSame(26.0, (float) $verified->last_price);
        $this->assertSame('2026-03-18 16:59:00', (string) $verified->asof);
    }

    public function test_incomplete_intraday_fetch_does_not_replace_last_complete_totals(): void
    {
        DB::table('option_expirations')->insert([
            'symbol' => 'SPY',
            'expiration_date' => '2026-03-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('option_live_counters')->insert([
            'symbol' => 'SPY',
            'trade_date' => '2026-03-18',
            'exp_date' => null,
            'strike' => null,
            'option_type' => null,
            'volume' => 777,
            'premium_usd' => 12345,
            'asof' => '2026-03-18 16:55:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = Mockery::mock(PolygonClient::class);
        $client->shouldReceive('intradayOptionVolumes')
            ->once()
            ->with('SPY', '2026-03-20')
            ->andReturn([
                'asof' => null,
                'totals' => ['call_vol' => 0, 'put_vol' => 0, 'premium' => 0],
                'by_strike' => [],
                'contracts' => [],
            ]);
        $this->app->instance(PolygonClient::class, $client);

        try {
            (new FetchPolygonIntradayOptionsJob(['SPY']))->handle();
            $this->fail('An incomplete provider response must make the job retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Intraday refresh incomplete', $exception->getMessage());
        }

        $totals = DB::table('option_live_counters')
            ->where('symbol', 'SPY')
            ->whereNull('exp_date')
            ->whereNull('strike')
            ->whereNull('option_type')
            ->get();

        $this->assertCount(1, $totals);
        $this->assertSame(777, (int) $totals->first()->volume);
        $this->assertSame('2026-03-18 16:55:00', (string) $totals->first()->asof);
    }

    public function test_a_late_export_failure_cannot_regress_a_completed_export(): void
    {
        $user = User::factory()->create();
        $completed = AiExport::query()->create([
            'user_id' => $user->id,
            'status' => 'completed',
            'symbols' => ['SPY'],
            'indicators' => ['gex'],
            'completed_at' => now(),
        ]);

        $builder = Mockery::mock(AiExportBuilder::class);
        $builder->shouldNotReceive('build');
        (new BuildAiExportJob($completed->id))->handle($builder);

        $completed->refresh();
        $this->assertSame('completed', $completed->status);

        (new BuildAiExportJob($completed->id))->failed(
            new RuntimeException('provider response contained private diagnostic data')
        );

        $completed->refresh();
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->error_message);

        $processing = AiExport::query()->create([
            'user_id' => $user->id,
            'status' => 'processing',
            'symbols' => ['QQQ'],
            'indicators' => ['gex'],
        ]);

        (new BuildAiExportJob($processing->id))->failed(
            new RuntimeException('provider response contained private diagnostic data')
        );

        $processing->refresh();
        $this->assertSame('failed', $processing->status);
        $this->assertSame('Export failed (unexpected).', $processing->error_message);
        $this->assertStringNotContainsString('private diagnostic', $processing->error_message);
    }

    public function test_polygon_does_not_publish_accumulated_pages_after_a_later_http_failure(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response([
                    'status' => 'OK',
                    'request_id' => 'page-one',
                    'results' => [[
                        'details' => [
                            'ticker' => 'O:AAPL260320C00180000',
                            'contract_type' => 'call',
                            'expiration_date' => '2026-03-20',
                            'strike_price' => 180,
                        ],
                        'day' => ['volume' => 10, 'close' => 2.5],
                    ]],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL?cursor=next',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 500);
        });

        $payload = app(PolygonClient::class)->intradayOptionVolumes('AAPL', '2026-03-20');

        $this->assertFalse($payload['complete']);
        $this->assertNull($payload['asof']);
        $this->assertSame([], $payload['contracts']);
        $this->assertGreaterThanOrEqual(2, $calls);
    }

    public function test_price_backfill_uses_yahoo_after_any_finnhub_http_failure(): void
    {
        $timestamp = Carbon::parse('2026-03-17 16:00:00', 'America/New_York')->timestamp;

        Http::fake([
            'finnhub.io/*' => Http::response(['error' => 'temporary'], 500),
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [[
                        'timestamp' => [$timestamp],
                        'indicators' => [
                            'quote' => [[
                                'open' => [199.0],
                                'high' => [202.0],
                                'low' => [198.0],
                                'close' => [201.0],
                            ]],
                        ],
                    ]],
                    'error' => null,
                ],
            ], 200),
        ]);

        (new PricesBackfillJob(['AAPL'], 30))->handle();

        $this->assertDatabaseHas('prices_daily', [
            'symbol' => 'AAPL',
            'trade_date' => '2026-03-17',
            'close' => 201.0,
        ]);
    }

    public function test_price_backfill_retries_when_both_providers_fail(): void
    {
        Http::fake(fn () => Http::response(['error' => 'temporary'], 503));

        try {
            (new PricesBackfillJob(['AAPL'], 30))->handle();
            $this->fail('A failed preferred source and fallback must make the job retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Price backfill incomplete', $exception->getMessage());
        }

        $this->assertDatabaseMissing('prices_daily', ['symbol' => 'AAPL']);
    }

    public function test_eod_fetch_retries_instead_of_publishing_pages_accumulated_before_http_failure(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');
        config()->set('services.massive.base', 'https://api.massive.test');

        $snapshotCalls = 0;
        Http::fake(function ($request) use (&$snapshotCalls) {
            if (str_contains($request->url(), '/v3/reference/options/contracts')) {
                return Http::response(['results' => []], 200);
            }

            $snapshotCalls++;
            if ($snapshotCalls === 1) {
                return Http::response([
                    'results' => [[
                        'details' => [
                            'ticker' => 'O:AAPL260320C00180000',
                            'contract_type' => 'call',
                            'expiration_date' => '2026-03-20',
                            'strike_price' => 180,
                        ],
                        'day' => ['volume' => 10, 'close' => 2.5],
                        'open_interest' => 50,
                        'underlying_asset' => ['price' => 180],
                    ]],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL?cursor=next',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 500);
        });

        try {
            (new QueueFailureSafetyOptionChainJob(['AAPL'], 7, '2026-03-18'))->handle();
            $this->fail('An interrupted provider pagination run must make the job retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EOD option-chain refresh incomplete', $exception->getMessage());
        }

        $this->assertGreaterThanOrEqual(2, $snapshotCalls);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_eod_fetch_accepts_a_complete_per_expiry_repair_after_bulk_pagination_fails(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');
        config()->set('services.massive.base', 'https://api.massive.test');

        $bulkStarted = false;
        Http::fake(function ($request) use (&$bulkStarted) {
            if (str_contains($request->url(), '/v3/reference/options/contracts')) {
                return Http::response(['results' => []], 200);
            }

            if (($request['expiration_date'] ?? null) === '2026-03-20') {
                return Http::response([
                    'results' => [
                        $this->massiveContract('O:AAPL260320C00180000', 'call'),
                        $this->massiveContract('O:AAPL260320P00180000', 'put'),
                    ],
                ], 200);
            }

            if (! $bulkStarted) {
                $bulkStarted = true;

                return Http::response([
                    'results' => [$this->massiveContract('O:AAPL260320C00180000', 'call')],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL?cursor=next',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 500);
        });

        $job = new QueueFailureSafetyOptionChainJob(['AAPL'], 7, '2026-03-18');
        [, $sets, $meta] = $job->fetchChainForTest(
            'AAPL',
            Carbon::parse('2026-03-18', 'America/New_York'),
            Carbon::parse('2026-03-25', 'America/New_York')
        );

        $this->assertSame('massive', $meta['provider']);
        $this->assertTrue($meta['provider_complete']);
        $this->assertCount(1, $sets);
    }

    public function test_eod_fetch_drops_partial_bulk_rows_when_complete_expiry_repair_is_empty(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');
        config()->set('services.massive.base', 'https://api.massive.test');

        $bulkStarted = false;
        Http::fake(function ($request) use (&$bulkStarted) {
            if (str_contains($request->url(), '/v3/reference/options/contracts')) {
                return Http::response(['results' => []], 200);
            }

            if (($request['expiration_date'] ?? null) === '2026-03-20') {
                return Http::response(['results' => []], 200);
            }

            if (! $bulkStarted) {
                $bulkStarted = true;

                return Http::response([
                    'results' => [$this->massiveContract('O:AAPL260320C00180000', 'call')],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL?cursor=next',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 500);
        });

        try {
            (new QueueFailureSafetyOptionChainJob(['AAPL'], 7, '2026-03-18'))->handle();
            $this->fail('An empty repair must not leave a partial broad-page expiry published.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('EOD option-chain refresh incomplete', $exception->getMessage());
        }

        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_sparse_finnhub_data_is_not_complete_when_massive_cannot_confirm_coverage(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');
        config()->set('services.massive.base', 'https://api.massive.test');
        Http::fake(fn () => Http::response(['status' => 'ERROR'], 500));

        $job = new QueueFailureSafetySparseFinnhubJob(['AAPL'], 7, '2026-03-18');
        [, $sets, $meta] = $job->fetchChainForTest(
            'AAPL',
            Carbon::parse('2026-03-18', 'America/New_York'),
            Carbon::parse('2026-03-25', 'America/New_York')
        );

        $this->assertCount(1, $sets);
        $this->assertSame('finnhub', $meta['provider']);
        $this->assertFalse($meta['provider_complete']);
        $this->assertSame('incomplete_sparse_fallback', $meta['provider_status']);
    }

    public function test_one_sided_expiry_repair_cannot_mark_failed_bulk_pagination_complete(): void
    {
        config()->set('services.massive.key', 'regression-provider-key');
        config()->set('services.massive.mode', 'header');
        config()->set('services.massive.base', 'https://api.massive.test');

        $bulkStarted = false;
        Http::fake(function ($request) use (&$bulkStarted) {
            if (str_contains($request->url(), '/v3/reference/options/contracts')) {
                return Http::response(['results' => []], 200);
            }

            if (($request['expiration_date'] ?? null) === '2026-03-20') {
                $results = ($request['contract_type'] ?? null) === 'put'
                    ? []
                    : [$this->massiveContract('O:AAPL260320C00180000', 'call')];

                return Http::response(['results' => $results], 200);
            }

            if (! $bulkStarted) {
                $bulkStarted = true;

                return Http::response([
                    'results' => [$this->massiveContract('O:AAPL260320C00180000', 'call')],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL?cursor=next',
                ], 200);
            }

            return Http::response(['status' => 'ERROR'], 500);
        });

        $job = new QueueFailureSafetyOptionChainJob(['AAPL'], 7, '2026-03-18');
        [, , $meta] = $job->fetchChainForTest(
            'AAPL',
            Carbon::parse('2026-03-18', 'America/New_York'),
            Carbon::parse('2026-03-25', 'America/New_York')
        );

        $this->assertSame('massive', $meta['provider']);
        $this->assertFalse($meta['provider_complete']);
        $this->assertSame('incomplete', $meta['provider_status']);
    }

    /** @return array<string,mixed> */
    private function massiveContract(string $ticker, string $type): array
    {
        return [
            'details' => [
                'ticker' => $ticker,
                'contract_type' => $type,
                'expiration_date' => '2026-03-20',
                'strike_price' => 180,
            ],
            'day' => ['volume' => 10, 'close' => 2.5],
            'open_interest' => 50,
            'underlying_asset' => ['price' => 180],
        ];
    }
}

class QueueFailureSafetyOptionChainJob extends FetchOptionChainDataJob
{
    protected function fetchFinnhubChain(string $symbol): array
    {
        return [null, ['status' => 'disabled_for_regression_test']];
    }

    /** @return array{0:float,1:array,2:array<string,mixed>} */
    public function fetchChainForTest(string $symbol, Carbon $windowStart, Carbon $windowEnd): array
    {
        return $this->fetchChain($symbol, $windowStart, $windowEnd);
    }
}

class QueueFailureSafetySparseFinnhubJob extends QueueFailureSafetyOptionChainJob
{
    protected function fetchFinnhubChain(string $symbol): array
    {
        return [[180.0, [[
            'expirationDate' => '2026-03-20',
            'options' => ['CALL' => [], 'PUT' => []],
        ]]], [
            'status' => 'ok',
            'set_count' => 1,
        ]];
    }
}
