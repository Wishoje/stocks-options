<?php

namespace Tests\Feature;

use App\Jobs\FetchOptionChainDataJob;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FetchOptionChainDataPartitionedTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api.massive.test';

    private const SYMBOL = 'SPY';

    private const TARGET_DATE = '2026-05-18';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'queue_lanes.isolated' => false,
            'services.massive.key' => 'massive-test',
            'services.massive.mode' => 'header',
            'services.massive.base' => self::BASE,
            'services.massive.concurrency.enabled' => false,
            'services.massive.eod_chain_partitioned_fetch_enabled' => true,
            // An empty list is the steady-state configuration: every symbol
            // uses partitioned fetching. A non-empty list is canary-only.
            'services.massive.eod_chain_partitioned_canary_symbols' => [],
            'services.massive.eod_chain_reference_probe_max_pages' => 4,
            'services.massive.eod_chain_max_pages_per_partition' => 80,
            'services.massive.eod_min_keep_oi' => 0,
            'services.massive.eod_min_keep_vol' => 0,
        ]);
    }

    public function test_empty_canary_list_enables_partitioned_fetch_for_an_arbitrary_symbol(): void
    {
        $job = new PartitionedFetchOptionChainDataJob(['TSLA'], 1, self::TARGET_DATE);

        $this->assertTrue($job->usesPartitionedMassiveFetchForTest('TSLA'));

        config()->set('services.massive.eod_chain_partitioned_canary_symbols', [self::SYMBOL]);

        $this->assertFalse($job->usesPartitionedMassiveFetchForTest('TSLA'));
        $this->assertTrue($job->usesPartitionedMassiveFetchForTest(self::SYMBOL));
    }

    public function test_capped_legacy_fetch_for_an_arbitrary_non_canary_symbol_escalates_to_partitions(): void
    {
        $symbol = 'MSTR';
        $legacyBulkRequests = 0;
        $legacyExpiryRepairRequests = 0;
        $partitionRequests = [];

        config()->set([
            'services.massive.eod_chain_partitioned_canary_symbols' => ['CVX'],
            'services.massive.eod_chain_max_pages' => 50,
        ]);

        Http::fake(function (Request $request) use (
            $symbol,
            &$legacyBulkRequests,
            &$legacyExpiryRepairRequests,
            &$partitionRequests
        ) {
            $url = (string) $request->url();
            $params = $this->requestParameters($request);

            if (str_contains($url, '/v3/reference/options/contracts')) {
                return Http::response([
                    'results' => [[
                        'underlying_ticker' => $symbol,
                        'expiration_date' => self::TARGET_DATE,
                    ]],
                ]);
            }

            if (! str_contains($url, '/v3/snapshot/options/'.$symbol)) {
                return Http::response([], 404);
            }

            $expiry = (string) ($params['expiration_date'] ?? '');
            $side = strtolower((string) ($params['contract_type'] ?? ''));
            if ($expiry !== '' && in_array($side, ['call', 'put'], true)) {
                $partitionRequests[] = compact('expiry', 'side');

                return Http::response([
                    'results' => [$this->contract($expiry, $side, 500.0, $symbol)],
                ]);
            }

            // The old expiry-only repair path is not enough for a contract-dense
            // symbol. It must be replaced by exact expiration/side partitions.
            if ($expiry !== '') {
                $legacyExpiryRepairRequests++;

                return Http::response(['error' => 'expiry-only repair is still too broad'], 422);
            }

            $legacyBulkRequests++;
            $page = max(1, (int) ($params['cursor'] ?? 1));

            return Http::response([
                'results' => [$this->contract(self::TARGET_DATE, 'call', 400.0 + $page, $symbol)],
                'next_url' => self::BASE.'/v3/snapshot/options/'.$symbol.'?cursor='.($page + 1),
            ]);
        });

        $job = new PartitionedFetchOptionChainDataJob([$symbol], 1, self::TARGET_DATE);
        $job->handle();

        $this->assertSame(50, $legacyBulkRequests);
        $this->assertSame(0, $legacyExpiryRepairRequests);
        $this->assertEqualsCanonicalizing([
            ['expiry' => self::TARGET_DATE, 'side' => 'call'],
            ['expiry' => self::TARGET_DATE, 'side' => 'put'],
        ], $partitionRequests);
        $this->assertDatabaseCount('option_chain_data', 2);

        $meta = Cache::get('eod:fetch-meta:'.$symbol.':'.self::TARGET_DATE);
        $this->assertSame('ok', $meta['status'] ?? null);
        $this->assertSame('partitioned_expiry_side', $meta['chain_fetch_strategy'] ?? null);
        $this->assertSame('legacy_bulk_pagination_capped', $meta['partition_trigger'] ?? null);
        $this->assertSame(54, $meta['provider_pages_total'] ?? null);
        $this->assertTrue((bool) ($meta['provider_complete'] ?? false));
    }

    public function test_partitioned_fetch_persists_complete_multipage_union_without_unfiltered_snapshot_request(): void
    {
        $expiries = ['2026-05-18', '2026-05-20'];
        $referenceRequests = [];
        $snapshotRequests = [];

        $this->fakePartitionedProvider(
            $expiries,
            function (string $expiry, string $side, string $cursor) {
                if ($cursor === '') {
                    return Http::response([
                        'results' => [
                            $this->contract($expiry, $side, 490.0),
                            $this->contract($expiry, $side, 500.0),
                        ],
                        'next_url' => $this->snapshotUrl('page-2'),
                    ]);
                }

                $this->assertSame('page-2', $cursor);

                return Http::response([
                    'results' => [
                        // Deliberately overlap a page boundary. The persisted
                        // result must still be the exact natural-key union.
                        $this->contract($expiry, $side, 500.0),
                        $this->contract($expiry, $side, 510.0),
                    ],
                ]);
            },
            $referenceRequests,
            $snapshotRequests,
        );

        $job = new PartitionedFetchOptionChainDataJob([self::SYMBOL], 2, self::TARGET_DATE);
        $job->handle();

        $this->assertNotNull($job->massiveResult);
        [$massiveChain, $massiveMeta] = $job->massiveResult;
        $this->assertTrue((bool) ($massiveMeta['complete'] ?? false));
        $this->assertSame('bounded_catalog', $massiveMeta['reference_strategy'] ?? null);
        $this->assertSame(1, $massiveMeta['reference_probe_pages'] ?? null);
        $this->assertSame(16, $massiveMeta['contracts_seen'] ?? null);
        $this->assertSame(12, $massiveMeta['contracts_unique'] ?? null);
        foreach (($massiveChain[1] ?? []) as $set) {
            foreach (['CALL', 'PUT'] as $side) {
                $strikes = array_column($set['options'][$side] ?? [], 'strike');
                sort($strikes);
                $this->assertSame([490.0, 500.0, 510.0], $strikes);
            }
        }

        $keys = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->orderBy('e.expiration_date')
            ->orderBy('o.option_type')
            ->orderBy('o.strike')
            ->get(['e.expiration_date', 'o.option_type', 'o.strike'])
            ->map(static fn ($row): string => implode('|', [
                (string) $row->expiration_date,
                (string) $row->option_type,
                number_format((float) $row->strike, 2, '.', ''),
            ]))
            ->all();

        $expected = [];
        foreach ($expiries as $expiry) {
            foreach (['call', 'put'] as $side) {
                foreach ([490.0, 500.0, 510.0] as $strike) {
                    $expected[] = implode('|', [
                        $expiry,
                        $side,
                        number_format($strike, 2, '.', ''),
                    ]);
                }
            }
        }
        sort($expected);
        sort($keys);

        $this->assertSame($expected, $keys);
        $this->assertCount(1, $referenceRequests);
        $this->assertSame('bounded_catalog', $referenceRequests[0]['strategy']);
        $this->assertSame(self::TARGET_DATE, $referenceRequests[0]['gte']);
        $this->assertSame('2026-05-20', $referenceRequests[0]['lte']);
        $this->assertSame(self::TARGET_DATE, $referenceRequests[0]['as_of']);
        $this->assertSame(self::SYMBOL, $referenceRequests[0]['underlying_ticker']);
        $this->assertSame(1000, $referenceRequests[0]['limit']);
        $this->assertNull($referenceRequests[0]['expired']);

        $this->assertCount(8, $snapshotRequests);
        foreach ($snapshotRequests as $request) {
            $this->assertContains($request['expiry'], $expiries);
            $this->assertContains($request['side'], ['call', 'put']);
        }

        $meta = Cache::get('eod:fetch-meta:'.self::SYMBOL.':'.self::TARGET_DATE);
        $this->assertSame('ok', $meta['status'] ?? null);
        $this->assertSame('massive', $meta['provider'] ?? null);
        $this->assertTrue((bool) ($meta['provider_complete'] ?? false));
    }

    public function test_capped_bounded_catalog_falls_back_to_exact_date_discovery(): void
    {
        $expiries = ['2026-05-18', '2026-05-20'];
        $referenceRequests = [];
        $snapshotRequests = [];

        $this->fakePartitionedProvider(
            $expiries,
            fn (string $expiry, string $side) => Http::response([
                'results' => [$this->contract($expiry, $side, 500.0)],
            ]),
            $referenceRequests,
            $snapshotRequests,
            true,
        );

        $job = new PartitionedFetchOptionChainDataJob([self::SYMBOL], 2, self::TARGET_DATE);
        $job->handle();

        $this->assertNotNull($job->massiveResult);
        [, $massiveMeta] = $job->massiveResult;
        $this->assertTrue((bool) ($massiveMeta['complete'] ?? false));
        $this->assertSame('exact_date_fallback', $massiveMeta['reference_strategy'] ?? null);
        $this->assertSame(4, $massiveMeta['reference_probe_pages'] ?? null);
        $this->assertTrue((bool) ($massiveMeta['reference_probe_pagination_capped'] ?? false));
        $this->assertSame(3, $massiveMeta['partition_dates_scanned'] ?? null);
        $this->assertSame(7, $massiveMeta['reference_pages'] ?? null);

        $catalogRequests = array_values(array_filter(
            $referenceRequests,
            static fn (array $request): bool => $request['strategy'] === 'bounded_catalog'
        ));
        $exactDateRequests = array_values(array_filter(
            $referenceRequests,
            static fn (array $request): bool => $request['strategy'] === 'exact_date'
        ));

        $this->assertCount(4, $catalogRequests);
        $this->assertCount(3, $exactDateRequests);
        foreach ($catalogRequests as $request) {
            $this->assertSame(self::SYMBOL, $request['underlying_ticker']);
            $this->assertSame(self::TARGET_DATE, $request['gte']);
            $this->assertSame('2026-05-20', $request['lte']);
            $this->assertSame(self::TARGET_DATE, $request['as_of']);
            $this->assertSame('asc', $request['order']);
            $this->assertSame('expiration_date', $request['sort']);
            $this->assertSame(1000, $request['limit']);
        }
        $this->assertSame(
            ['2026-05-18', '2026-05-19', '2026-05-20'],
            array_column($exactDateRequests, 'gte'),
        );
        foreach ($exactDateRequests as $request) {
            $this->assertSame($request['gte'], $request['lte']);
            $this->assertSame(1, $request['limit']);
            $this->assertSame(self::TARGET_DATE, $request['as_of']);
            $this->assertNull($request['expired']);
        }

        $this->assertCount(4, $snapshotRequests);
        $this->assertDatabaseCount('option_chain_data', 4);
    }

    public function test_partition_cursor_cycle_fails_without_writing_rows(): void
    {
        $referenceRequests = [];
        $snapshotRequests = [];

        $this->fakePartitionedProvider(
            [self::TARGET_DATE],
            function (string $expiry, string $side, string $cursor) {
                if ($side === 'put') {
                    return Http::response([
                        'results' => [$this->contract($expiry, $side, 500.0)],
                    ]);
                }

                return Http::response([
                    'results' => [$this->contract($expiry, $side, $cursor === '' ? 490.0 : 500.0)],
                    'next_url' => $this->snapshotUrl('repeat'),
                ]);
            },
            $referenceRequests,
            $snapshotRequests,
        );

        $this->assertIncompleteJob(
            new PartitionedFetchOptionChainDataJob([self::SYMBOL], 1, self::TARGET_DATE)
        );

        $this->assertDatabaseCount('option_chain_data', 0);
        $callRequests = array_values(array_filter(
            $snapshotRequests,
            static fn (array $request): bool => $request['side'] === 'call'
        ));
        $this->assertLessThanOrEqual(2, count($callRequests));
    }

    #[DataProvider('invalidPartitionContractProvider')]
    public function test_wrong_partition_scope_fails_without_writing_rows(
        string $returnedExpiry,
        string $returnedSide,
        string $returnedUnderlying,
    ): void {
        $referenceRequests = [];
        $snapshotRequests = [];

        $this->fakePartitionedProvider(
            [self::TARGET_DATE],
            function (string $expiry, string $side) use ($returnedExpiry, $returnedSide, $returnedUnderlying) {
                $contract = $side === 'call'
                    ? $this->contract($returnedExpiry, $returnedSide, 500.0, $returnedUnderlying)
                    : $this->contract($expiry, $side, 500.0);

                return Http::response(['results' => [$contract]]);
            },
            $referenceRequests,
            $snapshotRequests,
        );

        $this->assertIncompleteJob(
            new PartitionedFetchOptionChainDataJob([self::SYMBOL], 1, self::TARGET_DATE)
        );

        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function invalidPartitionContractProvider(): array
    {
        return [
            'wrong expiry' => ['2026-05-19', 'call', self::SYMBOL],
            'wrong side' => [self::TARGET_DATE, 'put', self::SYMBOL],
            'wrong underlying' => [self::TARGET_DATE, 'call', 'QQQ'],
        ];
    }

    public function test_capped_partition_fails_and_preserves_preexisting_rows(): void
    {
        config()->set('services.massive.eod_chain_max_pages_per_partition', 2);

        $this->seedExistingRows();
        $before = $this->persistedRows();
        $referenceRequests = [];
        $snapshotRequests = [];

        $this->fakePartitionedProvider(
            [self::TARGET_DATE],
            function (string $expiry, string $side, string $cursor) {
                if ($side === 'put') {
                    return Http::response([
                        'results' => [$this->contract($expiry, $side, 505.0)],
                    ]);
                }

                $page = $cursor === '' ? 1 : ((int) $cursor + 1);

                return Http::response([
                    'results' => [$this->contract($expiry, $side, 510.0 + $page)],
                    'next_url' => $this->snapshotUrl((string) $page),
                ]);
            },
            $referenceRequests,
            $snapshotRequests,
        );

        $this->assertIncompleteJob(
            new PartitionedFetchOptionChainDataJob([self::SYMBOL], 1, self::TARGET_DATE)
        );

        $this->assertSame($before, $this->persistedRows());
        $callRequests = array_values(array_filter(
            $snapshotRequests,
            static fn (array $request): bool => $request['side'] === 'call'
        ));
        $this->assertCount(2, $callRequests);
    }

    /**
     * @param  string[]  $discoveredExpiries
     * @param  array<int,array<string,mixed>>  $referenceRequests
     * @param  array<int,array<string,mixed>>  $snapshotRequests
     */
    private function fakePartitionedProvider(
        array $discoveredExpiries,
        Closure $snapshotResponder,
        array &$referenceRequests,
        array &$snapshotRequests,
        bool $forceCatalogCap = false,
    ): void {
        Http::fake(function (Request $request) use (
            $discoveredExpiries,
            $snapshotResponder,
            &$referenceRequests,
            &$snapshotRequests,
            $forceCatalogCap,
        ) {
            $url = (string) $request->url();
            $params = $this->requestParameters($request);

            if (str_contains($url, '/v3/reference/options/contracts')) {
                $gte = (string) ($params['expiration_date.gte'] ?? '');
                $lte = (string) ($params['expiration_date.lte'] ?? '');
                $limit = (int) ($params['limit'] ?? 0);
                $isExactDateCheck = $gte !== '' && $gte === $lte && $limit === 1;
                $referenceRequests[] = [
                    'strategy' => $isExactDateCheck ? 'exact_date' : 'bounded_catalog',
                    'underlying_ticker' => (string) ($params['underlying_ticker'] ?? ''),
                    'gte' => $gte,
                    'lte' => $lte,
                    'as_of' => (string) ($params['as_of'] ?? ''),
                    'order' => (string) ($params['order'] ?? ''),
                    'sort' => (string) ($params['sort'] ?? ''),
                    'expired' => $params['expired'] ?? null,
                    'limit' => $limit,
                    'cursor' => (string) ($params['cursor'] ?? ''),
                ];

                if (! $isExactDateCheck) {
                    $results = array_map(
                        static fn (string $expiry): array => [
                            'underlying_ticker' => self::SYMBOL,
                            'expiration_date' => $expiry,
                        ],
                        $discoveredExpiries,
                    );

                    if ($forceCatalogCap) {
                        $cursorPage = (int) ($params['cursor'] ?? 0);

                        return Http::response([
                            'results' => $results,
                            'next_url' => $this->referenceCatalogUrl((string) ($cursorPage + 1)),
                        ]);
                    }

                    return Http::response(['results' => $results]);
                }

                return Http::response([
                    'results' => in_array($gte, $discoveredExpiries, true)
                        ? [[
                            'underlying_ticker' => self::SYMBOL,
                            'expiration_date' => $gte,
                        ]]
                        : [],
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/'.self::SYMBOL)) {
                $expiry = (string) ($params['expiration_date'] ?? '');
                $side = strtolower((string) ($params['contract_type'] ?? ''));
                $cursor = (string) ($params['cursor'] ?? '');
                $snapshotRequests[] = [
                    'expiry' => $expiry,
                    'side' => $side,
                    'cursor' => $cursor,
                    'url' => $url,
                ];

                if ($expiry === '' || ! in_array($side, ['call', 'put'], true)) {
                    return Http::response(['error' => 'unpartitioned snapshot request'], 422);
                }

                return $snapshotResponder($expiry, $side, $cursor, $request);
            }

            return Http::response([], 404);
        });
    }

    /** @return array<string,mixed> */
    private function requestParameters(Request $request): array
    {
        $params = $request->data();
        $query = (string) (parse_url((string) $request->url(), PHP_URL_QUERY) ?? '');

        foreach (array_filter(explode('&', $query), static fn (string $part): bool => $part !== '') as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $params[rawurldecode($rawKey)] = rawurldecode($rawValue);
        }

        return $params;
    }

    private function assertIncompleteJob(FetchOptionChainDataJob $job): void
    {
        try {
            $job->handle();
            $this->fail('An incomplete partitioned fetch must make the job retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'EOD option-chain refresh incomplete',
                $exception->getMessage(),
            );
        }
    }

    /** @return array<string,mixed> */
    private function contract(
        string $expiry,
        string $side,
        float $strike,
        string $underlying = self::SYMBOL,
    ): array {
        return [
            'details' => [
                'ticker' => sprintf(
                    'O:%s%s%s%08d',
                    $underlying,
                    str_replace('-', '', $expiry),
                    $side === 'call' ? 'C' : 'P',
                    (int) round($strike * 1000),
                ),
                'expiration_date' => $expiry,
                'strike_price' => $strike,
                'contract_type' => $side,
            ],
            'underlying_asset' => [
                'price' => 501.0,
                'ticker' => $underlying,
            ],
            'open_interest' => (int) $strike,
            'session' => ['volume' => 10],
            'implied_volatility' => 0.2,
            'greeks' => [
                'gamma' => 0.01,
                'delta' => $side === 'call' ? 0.5 : -0.5,
                'vega' => 0.2,
            ],
        ];
    }

    private function snapshotUrl(string $cursor): string
    {
        return self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?'.http_build_query([
            'cursor' => $cursor,
        ]);
    }

    private function referenceCatalogUrl(string $cursor): string
    {
        return self::BASE.'/v3/reference/options/contracts?'.http_build_query([
            'cursor' => $cursor,
        ]);
    }

    private function seedExistingRows(): void
    {
        $expirationId = DB::table('option_expirations')->insertGetId([
            'symbol' => self::SYMBOL,
            'expiration_date' => self::TARGET_DATE,
            'created_at' => '2026-05-18 21:00:00',
            'updated_at' => '2026-05-18 21:00:00',
        ]);

        foreach (['call' => 111, 'put' => 222] as $side => $openInterest) {
            DB::table('option_chain_data')->insert([
                'expiration_id' => $expirationId,
                'data_date' => self::TARGET_DATE,
                'data_timestamp' => '2026-05-18 21:00:00',
                'option_type' => $side,
                'strike' => 500.0,
                'open_interest' => $openInterest,
                'volume' => 12,
                'gamma' => 0.01,
                'delta' => $side === 'call' ? 0.5 : -0.5,
                'vega' => 0.2,
                'iv' => 0.2,
                'underlying_price' => 500.0,
                'created_at' => '2026-05-18 21:00:00',
                'updated_at' => '2026-05-18 21:00:00',
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function persistedRows(): array
    {
        return DB::table('option_chain_data')
            ->orderBy('id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }
}

class PartitionedFetchOptionChainDataJob extends FetchOptionChainDataJob
{
    /** @var array{0:?array{0:float,1:array},1:array<string,mixed>}|null */
    public ?array $massiveResult = null;

    protected function fetchFinnhubChain(string $symbol): array
    {
        return [null, ['status' => 'disabled_for_partitioned_fetch_test']];
    }

    public function usesPartitionedMassiveFetchForTest(string $symbol): bool
    {
        return $this->usesPartitionedMassiveFetch($symbol);
    }

    protected function fetchMassiveChain(
        string $symbol,
        ?Carbon $windowStart = null,
        ?Carbon $windowEnd = null,
    ): array {
        $this->massiveResult = parent::fetchMassiveChain($symbol, $windowStart, $windowEnd);

        return $this->massiveResult;
    }
}
