<?php

namespace Tests\Feature;

use App\Services\HistoricalEodArchiveReader;
use App\Services\HistoricalEodRecoveryProvider;
use App\Services\HistoricalEodRecoveryService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class HistoricalEodRecoveryServiceTest extends TestCase
{
    private const BASE = 'https://api.massive.test';

    private const SYMBOL = 'SPY';

    private const TARGET_DATE = '2026-07-17';

    private const FUTURE_EXPIRY = '2026-07-24';

    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRecoverySchema();

        Carbon::setTestNow(Carbon::parse('2026-07-19 02:00:00', 'America/New_York'));

        config()->set([
            'services.massive.key' => 'massive-test',
            'services.massive.mode' => 'header',
            'services.massive.header' => 'X-API-Key',
            'services.massive.base' => self::BASE,
            'services.massive.eod_chain_page_limit' => 250,
            'services.massive.eod_chain_max_pages_per_partition' => 4,
            'services.massive.eod_chain_reference_max_pages' => 4,
            'services.massive.concurrency.enabled' => false,
            'services.massive.recovery_min_expirations' => 2,
            'services.massive.recovery_min_strikes' => 1,
        ]);

        $this->temporaryRoot = storage_path(
            'framework/testing/historical-eod-recovery-'.bin2hex(random_bytes(6))
        );
        File::ensureDirectoryExists($this->temporaryRoot);

        DB::table('prices_daily')->insert([
            'symbol' => self::SYMBOL,
            'trade_date' => self::TARGET_DATE,
            'open' => 499.0,
            'high' => 503.0,
            'low' => 497.0,
            'close' => 500.0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);
        Schema::dropIfExists('option_chain_data');
        Schema::dropIfExists('option_expirations');
        Schema::dropIfExists('prices_daily');
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createRecoverySchema(): void
    {
        Schema::dropIfExists('option_chain_data');
        Schema::dropIfExists('option_expirations');
        Schema::dropIfExists('prices_daily');

        Schema::create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('expiration_date');
            $table->timestamps();
            $table->unique(['symbol', 'expiration_date']);
        });
        Schema::create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->date('data_date');
            $table->timestamp('data_timestamp')->nullable();
            $table->string('option_type', 4);
            $table->decimal('strike', 8, 2);
            $table->bigInteger('open_interest')->nullable();
            $table->bigInteger('volume')->nullable();
            $table->decimal('gamma', 12, 8)->nullable();
            $table->decimal('delta', 12, 8)->nullable();
            $table->float('vega')->nullable();
            $table->decimal('iv', 12, 8)->nullable();
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->timestamps();
            $table->unique(['expiration_id', 'data_date', 'option_type', 'strike']);
        });
        Schema::create('prices_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 16);
            $table->date('trade_date');
            $table->decimal('open', 12, 4)->nullable();
            $table->decimal('high', 12, 4)->nullable();
            $table->decimal('low', 12, 4)->nullable();
            $table->decimal('close', 12, 4)->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'trade_date']);
        });
    }

    public function test_capture_and_validate_build_an_immutable_hybrid_candidate_without_canonical_writes(): void
    {
        $requests = [];
        $this->fakeProvider($requests);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );

        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));
        $this->assertSame(realpath($runDirectory), $capture['run_directory'] ?? null);
        $this->assertDatabaseCount('option_chain_data', 0);

        $captureHashes = array_filter(
            $this->artifactHashes($runDirectory),
            static fn (string $path): bool => str_ends_with($path, '.gz'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertNotEmpty($captureHashes, 'Capture must persist immutable recovery artifacts.');

        $validation = $this->service()->validate($runDirectory);

        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));
        $this->assertSame(4, $this->candidateRowCount($validation));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($validation['candidate_sha256'] ?? ''),
        );
        $this->assertSame(
            [
                self::TARGET_DATE => 'archive',
                self::FUTURE_EXPIRY => 'current_snapshot',
            ],
            $validation['symbols'][self::SYMBOL]['expiry_sources'] ?? null,
        );
        $this->assertDatabaseCount('option_chain_data', 0);

        $validatedHashes = $this->artifactHashes($runDirectory);
        foreach ($captureHashes as $path => $hash) {
            $this->assertSame($hash, $validatedHashes[$path] ?? null, "Artifact was mutated: {$path}");
        }

        $referenceRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['kind'] === 'reference',
        ));
        $snapshotRequests = array_values(array_filter(
            $requests,
            static fn (array $request): bool => $request['kind'] === 'snapshot',
        ));

        $this->assertCount(2, $referenceRequests);
        foreach ($referenceRequests as $referenceRequest) {
            $this->assertTrue($referenceRequest['expired_present']);
            $this->assertSame(self::SYMBOL, $referenceRequest['underlying_ticker']);
            $this->assertSame(self::TARGET_DATE, $referenceRequest['as_of']);
            $this->assertSame(self::TARGET_DATE, $referenceRequest['gte']);
            $this->assertSame('2026-10-15', $referenceRequest['lte']);
        }
        $this->assertSame(
            [false, true],
            collect($referenceRequests)
                ->map(fn (array $request): bool => $this->booleanParameter($request['expired']))
                ->sort()->values()->all(),
        );

        $this->assertCount(2, $snapshotRequests);
        $this->assertSame(
            [
                self::FUTURE_EXPIRY.'|call',
                self::FUTURE_EXPIRY.'|put',
            ],
            collect($snapshotRequests)
                ->map(fn (array $request): string => $request['expiry'].'|'.$request['side'])
                ->sort()->values()->all(),
        );
        $this->assertNotContains(
            self::TARGET_DATE,
            array_column($snapshotRequests, 'expiry'),
            'The expired target-date partition must come only from the archive.',
        );
    }

    public function test_capture_rejects_archive_checksum_mismatch_before_calling_the_provider(): void
    {
        Http::fake();
        [$archivePath] = $this->writeArchive($this->validArchiveRows());

        $result = $this->rejectionResult(fn (): array => $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            str_repeat('0', 64),
            $this->runDirectory(),
        ));

        $this->assertRejected($result);
        Http::assertNothingSent();
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_capture_rechecks_the_archive_checksum_after_streaming(): void
    {
        $requests = [];
        $this->fakeProvider($requests);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $mutatingReader = new class extends HistoricalEodArchiveReader
        {
            public function latestExpirationGroups(
                string $path,
                string $expectedSha256,
                string $targetDate,
                string $endDate,
                array $symbols,
            ): array {
                $groups = parent::latestExpirationGroups(
                    $path,
                    $expectedSha256,
                    $targetDate,
                    $endDate,
                    $symbols,
                );
                File::append($path, PHP_EOL);

                return $groups;
            }
        };
        $service = new HistoricalEodRecoveryService(
            app(HistoricalEodRecoveryProvider::class),
            $mutatingReader,
        );

        $result = $this->rejectionResult(fn (): array => $service->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $this->runDirectory(),
        ));

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    #[DataProvider('invalidArchiveProvider')]
    public function test_stage_rejects_invalid_archive_partitions(string $case): void
    {
        $requests = [];
        $this->fakeProvider($requests);
        $rows = $this->invalidArchiveRows($case);
        [$archivePath, $archiveSha] = $this->writeArchive($rows);

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidArchiveProvider(): array
    {
        return [
            'empty archive' => ['empty'],
            'missing put side' => ['missing_side'],
            'wrong capture day' => ['wrong_capture_day'],
            'empty capture timestamp' => ['empty_capture_timestamp'],
            'pre-close capture group' => ['pre_close_capture'],
            'no exact target expiry' => ['wrong_expiry'],
            'wrong underlying symbol' => ['wrong_symbol'],
            'invalid option side' => ['wrong_side'],
            'duplicate canonical key collision' => ['collision'],
            'strike precision would be lost' => ['strike_precision'],
            'archive call and put spots disagree' => ['archive_spot_mismatch'],
        ];
    }

    public function test_stage_rejects_a_reference_catalog_without_the_exact_target_expiry(): void
    {
        $requests = [];
        $this->fakeProvider($requests, [self::FUTURE_EXPIRY]);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_reference_catalogs_are_unioned_by_ticker_across_active_and_expired_queries(): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: 'cross_catalog_overlap');
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertTrue((bool) ($result['ok'] ?? false), json_encode($result));
        $this->assertSame(4, $this->candidateRowCount($result));
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_reference_cursor_pages_retain_the_original_symbol_date_and_catalog_scope(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $params = $this->requestParameters($request);
            $requests[] = $params;
            $cursor = (string) ($params['cursor'] ?? '');
            $expired = $cursor !== ''
                ? $cursor === 'expired-next'
                : $this->booleanParameter($params['expired'] ?? false);
            $expiry = $expired ? self::TARGET_DATE : self::FUTURE_EXPIRY;
            $contracts = $this->referenceContracts([$expiry]);

            return Http::response([
                'status' => 'OK',
                'results' => [$contracts[$cursor === '' ? 0 : 1]],
                ...($cursor === '' ? [
                    'next_url' => self::BASE.'/v3/reference/options/contracts?cursor='.
                        ($expired ? 'expired-next' : 'active-next'),
                ] : []),
            ]);
        });

        $result = app(HistoricalEodRecoveryProvider::class)->referenceContracts(
            self::SYMBOL,
            self::TARGET_DATE,
            '2026-10-15',
        );

        $this->assertCount(4, $result['contracts']);
        $this->assertCount(4, $requests);
        foreach ($requests as $index => $params) {
            $expectedExpired = $index < 2;
            $this->assertSame(self::SYMBOL, (string) ($params['underlying_ticker'] ?? ''));
            $this->assertSame(self::TARGET_DATE, (string) ($params['expiration_date.gte'] ?? ''));
            $this->assertSame('2026-10-15', (string) ($params['expiration_date.lte'] ?? ''));
            $this->assertSame(self::TARGET_DATE, (string) ($params['as_of'] ?? ''));
            $this->assertSame('asc', (string) ($params['order'] ?? ''));
            $this->assertSame('ticker', (string) ($params['sort'] ?? ''));
            $this->assertSame(1000, (int) ($params['limit'] ?? 0));
            $this->assertSame(
                $expectedExpired,
                $this->booleanParameter($params['expired'] ?? null),
            );
            if ($index % 2 === 1) {
                $this->assertSame(
                    $expectedExpired ? 'expired-next' : 'active-next',
                    (string) ($params['cursor'] ?? ''),
                );
            }
        }
    }

    public function test_snapshot_cursor_pages_retain_the_original_expiry_and_side_scope(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $params = $this->requestParameters($request);
            $requests[] = $params;
            $contract = $this->currentContract(self::FUTURE_EXPIRY, 'call');
            $cursor = (string) ($params['cursor'] ?? '');
            if ($cursor !== '') {
                $contract['details']['strike_price'] = 501.0;
                $contract['details']['ticker'] = $this->optionTicker(
                    self::SYMBOL,
                    self::FUTURE_EXPIRY,
                    'call',
                    501.0,
                );
            }

            return Http::response([
                'status' => 'OK',
                'results' => [$contract],
                ...($cursor === '' ? [
                    'next_url' => self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?cursor=snapshot-next',
                ] : []),
            ]);
        });

        $result = app(HistoricalEodRecoveryProvider::class)->snapshotPartition(
            self::SYMBOL,
            self::FUTURE_EXPIRY,
            'call',
        );

        $this->assertCount(2, $result['contracts']);
        $this->assertCount(2, $requests);
        foreach ($requests as $params) {
            $this->assertSame(self::FUTURE_EXPIRY, (string) ($params['expiration_date'] ?? ''));
            $this->assertSame('call', (string) ($params['contract_type'] ?? ''));
            $this->assertSame(250, (int) ($params['limit'] ?? 0));
        }
        $this->assertSame('snapshot-next', (string) ($requests[1]['cursor'] ?? ''));
    }

    public function test_snapshot_partition_allows_an_explicit_identity_without_a_provider_spot(): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: 'priceless_day_marker');

        $partition = app(HistoricalEodRecoveryProvider::class)->snapshotPartition(
            self::SYMBOL,
            self::FUTURE_EXPIRY,
            'call',
        );

        $this->assertCount(1, $partition['contracts']);
        $this->assertArrayHasKey('spot', $partition);
        $this->assertNull($partition['spot']);
        $this->assertSame(self::SYMBOL, $partition['contracts'][0]['underlying_asset']['ticker'] ?? null);
        $this->assertArrayNotHasKey('price', $partition['contracts'][0]['underlying_asset'] ?? []);
    }

    #[DataProvider('pricelessSnapshotMarkerProvider')]
    public function test_capture_uses_exact_close_and_normalizes_target_day_snapshot_markers(
        string $markerField,
    ): void {
        $requests = [];
        $this->fakeProvider($requests, failureMode: "priceless_{$markerField}_marker");
        $archiveRows = array_map(function (array $row): array {
            $row['underlying_price'] = 499.0;

            return $row;
        }, $this->validArchiveRows());
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows);
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );
        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));

        $validation = $this->service()->validate($runDirectory);
        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));
        $published = $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        );
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->whereDate('e.expiration_date', self::FUTURE_EXPIRY)
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->orderBy('o.option_type')
            ->get(['o.option_type', 'o.underlying_price', 'o.data_timestamp']);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                500.0,
                (float) $row->underlying_price,
                'A missing live provider spot must use the exact target-session close.',
            );
            $this->assertSame(
                '2026-07-17 20:00:00',
                Carbon::parse((string) $row->data_timestamp, 'UTC')->utc()->format('Y-m-d H:i:s'),
                'A target-day daily/session marker must normalize to the 16:00 ET close.',
            );
        }
    }

    /** @return array<string,array{0:string}> */
    public static function pricelessSnapshotMarkerProvider(): array
    {
        return [
            'daily bar marker' => ['day'],
            'session marker' => ['session'],
        ];
    }

    public function test_reference_cursor_page_with_an_explicit_wrong_underlying_still_rejects(): void
    {
        $page = 0;
        Http::fake(function () use (&$page) {
            $page++;
            $symbol = $page === 1 ? self::SYMBOL : 'A';
            $contracts = $this->referenceContracts([self::TARGET_DATE], $symbol);

            return Http::response([
                'status' => 'OK',
                'results' => [$contracts[$page === 1 ? 0 : 1]],
                ...($page === 1 ? [
                    'next_url' => self::BASE.'/v3/reference/options/contracts?cursor=wrong-underlying',
                ] : []),
            ]);
        });

        $result = $this->rejectionResult(
            fn (): array => app(HistoricalEodRecoveryProvider::class)->referenceContracts(
                self::SYMBOL,
                self::TARGET_DATE,
                '2026-10-15',
            ),
        );

        $this->assertRejected($result);
        $this->assertStringContainsString('wrong_underlying', (string) ($result['error'] ?? ''));
    }

    #[DataProvider('cursorFailureProvider')]
    public function test_cursor_only_pagination_still_rejects_cycles_and_no_progress(
        string $failureMode,
        string $expectedError,
    ): void {
        $page = 0;
        Http::fake(function () use (&$page, $failureMode) {
            $page++;
            if ($page === 1) {
                return Http::response([
                    'status' => 'OK',
                    'results' => [$this->currentContract(self::FUTURE_EXPIRY, 'put')],
                    'next_url' => self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?cursor=repeat',
                ]);
            }
            if ($failureMode === 'no_progress') {
                return Http::response([
                    'status' => 'OK',
                    'results' => [],
                    'next_url' => self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?cursor=after-empty',
                ]);
            }

            $contract = $this->currentContract(self::FUTURE_EXPIRY, 'put');
            $contract['details']['strike_price'] = 501.0;
            $contract['details']['ticker'] = $this->optionTicker(
                self::SYMBOL,
                self::FUTURE_EXPIRY,
                'put',
                501.0,
            );

            return Http::response([
                'status' => 'OK',
                'results' => [$contract],
                'next_url' => self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?cursor=repeat',
            ]);
        });

        $result = $this->rejectionResult(
            fn (): array => app(HistoricalEodRecoveryProvider::class)->snapshotPartition(
                self::SYMBOL,
                self::FUTURE_EXPIRY,
                'put',
            ),
        );

        $this->assertRejected($result);
        $this->assertStringContainsString($expectedError, (string) ($result['error'] ?? ''));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function cursorFailureProvider(): array
    {
        return [
            'cursor cycle' => ['cycle', 'cursor cycle'],
            'empty page with next cursor' => ['no_progress', 'made no progress'],
        ];
    }

    public function test_stage_requires_the_exact_target_daily_close(): void
    {
        DB::table('prices_daily')
            ->where('symbol', self::SYMBOL)
            ->whereDate('trade_date', self::TARGET_DATE)
            ->delete();
        $requests = [];
        $this->fakeProvider($requests);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    public function test_stage_rejects_a_reference_matched_strike_that_loses_canonical_precision(): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: 'fractional_target_strike');
        $archiveRows = array_map(function (array $row): array {
            $row['strike_price'] = 500.125;
            $row['contract_symbol'] = $this->optionTicker(
                self::SYMBOL,
                self::TARGET_DATE,
                (string) $row['contract_type'],
                500.125,
            );

            return $row;
        }, $this->validArchiveRows());
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows);

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    #[DataProvider('invalidProviderBoundaryProvider')]
    public function test_stage_rejects_invalid_provider_contracts_and_metadata(string $failureMode): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: $failureMode);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidProviderBoundaryProvider(): array
    {
        return [
            'provider status is not OK' => ['bad_status'],
            'duplicate contract in one catalog response' => ['duplicate_reference_contract'],
            'duplicate contract in one snapshot partition' => ['duplicate_snapshot_contract'],
            'missing underlying identity' => ['missing_underlying'],
            'adjusted contract has additional underlyings' => ['additional_underlying'],
            'contract multiplier is not one hundred' => ['wrong_multiplier'],
            'source timestamp is after the target session' => ['future_source_timestamp'],
            'daily marker is from the following Monday' => ['future_daily_marker'],
            'snapshot has no usable provider timestamp' => ['missing_source_timestamp'],
            'current call and put spots disagree' => ['side_spot_mismatch'],
            'hybrid spot differs by more than three percent' => ['hybrid_spot_difference'],
        ];
    }

    #[DataProvider('incompleteProviderPartitionProvider')]
    public function test_stage_fails_closed_when_any_current_provider_partition_is_incomplete(
        string $failureMode,
    ): void {
        $requests = [];
        $this->fakeProvider($requests, failureMode: $failureMode);
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /** @return array<string,array{0:string}> */
    public static function incompleteProviderPartitionProvider(): array
    {
        return [
            'HTTP failure' => ['http_error'],
            'empty put partition' => ['empty_partition'],
            'pagination cursor cycle' => ['pagination_cycle'],
            'partition leaks the archived expiry' => ['archive_collision'],
        ];
    }

    public function test_publish_rejects_a_bad_confirmation_hash_and_any_existing_target_rows(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();

        $badHashResult = $this->rejectionResult(
            fn (): array => $this->service()->publish($runDirectory, str_repeat('f', 64)),
        );
        $this->assertRejected($badHashResult);
        $this->assertDatabaseCount('option_chain_data', 0);

        $expirationId = $this->expirationId(self::SYMBOL, self::TARGET_DATE);
        DB::table('option_chain_data')->insert([
            'expiration_id' => $expirationId,
            'data_date' => self::TARGET_DATE,
            'data_timestamp' => '2026-07-17 21:00:00',
            'option_type' => 'call',
            'strike' => 500.0,
            'open_interest' => 999999,
        ]);
        $before = DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $result = $this->rejectionResult(fn (): array => $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        ));

        $this->assertRejected($result);
        $this->assertSame(
            $before,
            DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'Publish must not overwrite or augment an existing symbol/date slice.',
        );
    }

    public function test_publish_inserts_the_validated_hybrid_candidate_without_overwriting(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();

        $result = $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        );

        $this->assertTrue((bool) ($result['ok'] ?? false), json_encode($result));
        $this->assertSame(
            4,
            (int) ($result['inserted_rows'] ?? array_sum((array) ($result['published'] ?? []))),
        );

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->orderBy('e.expiration_date')->orderBy('o.option_type')
            ->get([
                'e.expiration_date',
                'o.option_type',
                'o.open_interest',
                'o.underlying_price',
            ])
            ->map(fn ($row): array => [
                'expiry' => (string) $row->expiration_date,
                'side' => (string) $row->option_type,
                'oi' => (int) $row->open_interest,
                'spot' => (float) $row->underlying_price,
            ])
            ->all();

        $this->assertSame([
            ['expiry' => self::TARGET_DATE, 'side' => 'call', 'oi' => 1111, 'spot' => 500.0],
            ['expiry' => self::TARGET_DATE, 'side' => 'put', 'oi' => 2222, 'spot' => 500.0],
            ['expiry' => self::FUTURE_EXPIRY, 'side' => 'call', 'oi' => 3333, 'spot' => 500.0],
            ['expiry' => self::FUTURE_EXPIRY, 'side' => 'put', 'oi' => 4444, 'spot' => 500.0],
        ], $rows);

        $receipt = json_decode(
            File::get($runDirectory.DIRECTORY_SEPARATOR.'publish-receipt.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($receipt['persisted_sha256'] ?? ''),
        );
        $this->assertSame(4, (int) ($receipt['persisted_symbols'][self::SYMBOL]['rows'] ?? 0));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($receipt['persisted_symbols'][self::SYMBOL]['sha256'] ?? ''),
        );

        $before = DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $secondPublish = $this->rejectionResult(fn (): array => $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        ));

        $this->assertRejected($secondPublish);
        $this->assertSame(
            $before,
            DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        );
    }

    public function test_publish_resumes_a_prepared_intent_when_exact_candidate_rows_already_exist(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();
        $candidateSha = (string) $validation['candidate_sha256'];

        $published = $this->service()->publish($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));
        $this->assertDatabaseCount('option_chain_data', 4);

        $intentPath = $runDirectory.DIRECTORY_SEPARATOR.'publish-intent.json';
        $intent = $this->assertPreparedIntent($intentPath, 'publish', $candidateSha, 4);
        $intentFileSha = hash_file('sha256', $intentPath);
        $rowsBeforeResume = DB::table('option_chain_data')
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => (array) $row)
            ->all();

        // Simulate a process crash after the canonical transaction committed
        // but before the publication receipt and manifest were finalized.
        File::delete($runDirectory.DIRECTORY_SEPARATOR.'publish-receipt.json');
        $this->setManifestStatus($runDirectory, 'validated', ['published_at']);

        $resumed = $this->service()->publish($runDirectory, $candidateSha);

        $this->assertTrue((bool) ($resumed['ok'] ?? false), json_encode($resumed));
        $this->assertSame(
            $rowsBeforeResume,
            DB::table('option_chain_data')
                ->orderBy('id')
                ->get()
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'Resume must finalize the exact committed slices without inserting duplicates.',
        );
        $this->assertSame($intentFileSha, hash_file('sha256', $intentPath));
        $this->assertSame($intent, $this->readJsonArtifact($intentPath));
        $this->assertFileExists($runDirectory.DIRECTORY_SEPARATOR.'publish-receipt.json');
        $this->assertSame(
            'published',
            $this->readJsonArtifact($runDirectory.DIRECTORY_SEPARATOR.'manifest.json')['status'] ?? null,
        );
    }

    public function test_publish_rebinds_the_loaded_candidate_hash_before_any_insert(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();
        $candidateSha = (string) $validation['candidate_sha256'];

        $service = new class(app(HistoricalEodRecoveryProvider::class), app(HistoricalEodArchiveReader::class)) extends HistoricalEodRecoveryService
        {
            private bool $mutated = false;

            public function validate(string $runDirectory): array
            {
                $validation = parent::validate($runDirectory);
                if ($this->mutated || ! (bool) ($validation['ok'] ?? false)) {
                    return $validation;
                }

                $manifestPath = $runDirectory.DIRECTORY_SEPARATOR.'manifest.json';
                $manifest = json_decode(
                    File::get($manifestPath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
                $symbol = (string) ($manifest['symbols'][0] ?? '');
                $candidateRelative = (string) (
                    $manifest['symbol_results'][$symbol]['candidate_file'] ?? ''
                );
                $candidatePath = $runDirectory.DIRECTORY_SEPARATOR.str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $candidateRelative,
                );
                $json = gzdecode(File::get($candidatePath));
                if (! is_string($json)) {
                    throw new \RuntimeException('Unable to decode candidate fixture.');
                }
                $candidate = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $candidate['rows'][0]['open_interest'] = (int) $candidate['rows'][0]['open_interest'] + 1;
                $encoded = json_encode(
                    $candidate,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                $gzip = gzencode($encoded, 9);
                if (! is_string($gzip)) {
                    throw new \RuntimeException('Unable to encode candidate fixture.');
                }
                File::put($candidatePath, $gzip);
                // Keep the compressed-file checksum internally consistent so
                // only a content-hash rebind catches this post-validation swap.
                $manifest['symbol_results'][$symbol]['candidate_file_sha256'] = hash_file(
                    'sha256',
                    $candidatePath,
                );
                File::put($manifestPath, json_encode(
                    $manifest,
                    JSON_THROW_ON_ERROR
                        | JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE,
                ).PHP_EOL);
                $this->mutated = true;

                return $validation;
            }
        };

        $result = $this->rejectionResult(
            fn (): array => $service->publish($runDirectory, $candidateSha),
        );

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
        $this->assertFileDoesNotExist($runDirectory.DIRECTORY_SEPARATOR.'publish-receipt.json');
    }

    public function test_recovery_preserves_finite_negative_provider_gamma_and_vega(): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: 'negative_snapshot_greeks');
        $archiveRows = $this->validArchiveRows();
        $archiveRows[0]['gamma'] = -0.0000004497;
        $archiveRows[0]['vega'] = -0.125;
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows);
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );
        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));
        $validation = $this->service()->validate($runDirectory);
        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));
        $published = $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        );
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->where('o.option_type', 'call')
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->orderBy('e.expiration_date')
            ->get(['e.expiration_date', 'o.gamma', 'o.vega']);

        $this->assertCount(2, $rows);
        $this->assertSame(self::TARGET_DATE, (string) $rows[0]->expiration_date);
        $this->assertEqualsWithDelta(-0.00000045, (float) $rows[0]->gamma, 0.000000001);
        $this->assertEqualsWithDelta(-0.125, (float) $rows[0]->vega, 0.00000001);
        $this->assertSame(self::FUTURE_EXPIRY, (string) $rows[1]->expiration_date);
        $this->assertEqualsWithDelta(-0.00000056, (float) $rows[1]->gamma, 0.000000001);
        $this->assertEqualsWithDelta(-0.25, (float) $rows[1]->vega, 0.00000001);
    }

    public function test_recovery_accepts_a_lower_corrected_final_volume_and_records_the_difference(): void
    {
        $requests = [];
        $this->fakeProvider($requests);
        $futureRows = array_map(function (array $row): array {
            $side = (string) $row['contract_type'];
            $row['expiration_date'] = self::FUTURE_EXPIRY;
            $row['contract_symbol'] = $this->optionTicker(
                self::SYMBOL,
                self::FUTURE_EXPIRY,
                $side,
                500.0,
            );
            $row['request_id'] = 'archive-request-future-expiry';
            $row['volume'] = $side === 'call' ? 600 : 400;

            return $row;
        }, $this->validArchiveRows());
        [$archivePath, $archiveSha] = $this->writeArchive([
            ...$this->validArchiveRows(),
            ...$futureRows,
        ]);
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );
        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));

        $manifest = $this->readJsonArtifact($runDirectory.DIRECTORY_SEPARATOR.'manifest.json');
        $candidateFile = (string) ($manifest['symbol_results'][self::SYMBOL]['candidate_file'] ?? '');
        $candidateJson = gzdecode(File::get($runDirectory.DIRECTORY_SEPARATOR.$candidateFile));
        $this->assertIsString($candidateJson);
        $candidate = json_decode($candidateJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, (int) ($candidate['archive_future_overlap_checks'] ?? -1));
        $this->assertSame(2, (int) ($candidate['archive_future_volume_differences'] ?? -1));
        $this->assertSame(1, (int) ($candidate['archive_future_current_lower'] ?? -1));

        $validation = $this->service()->validate($runDirectory);
        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));
        $published = $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        );
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));

        $volumes = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->whereDate('e.expiration_date', self::FUTURE_EXPIRY)
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->pluck('o.volume', 'o.option_type')
            ->map(fn ($volume): int => (int) $volume)
            ->all();

        $this->assertSame(['call' => 333, 'put' => 444], $volumes);
    }

    public function test_recovery_rejects_a_snapshot_with_no_numeric_volume(): void
    {
        $requests = [];
        $this->fakeProvider($requests, failureMode: 'missing_snapshot_volume');
        [$archivePath, $archiveSha] = $this->writeArchive($this->validArchiveRows());

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertStringContainsString('volume is missing', json_encode($result));
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    #[DataProvider('invalidGreekAndIvProvider')]
    public function test_recovery_rejects_nonfinite_out_of_range_metrics_and_invalid_iv(
        string $field,
        mixed $value,
    ): void {
        $requests = [];
        $this->fakeProvider($requests);
        $archiveRows = $this->validArchiveRows();
        $archiveRows[0][$field] = $value;
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows);

        $result = $this->captureThenValidate($archivePath, $archiveSha, $this->runDirectory());

        $this->assertRejected($result);
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public static function invalidGreekAndIvProvider(): array
    {
        return [
            'nonfinite gamma' => ['gamma', '1e309'],
            'nonfinite vega' => ['vega', '-1e309'],
            'positive gamma overflow' => ['gamma', 10000.0],
            'negative gamma overflow' => ['gamma', -10000.0],
            'positive vega overflow' => ['vega', 1.0e30],
            'negative vega overflow' => ['vega', -1.0e30],
            'nonfinite IV' => ['implied_volatility', '1e309'],
            'normalized IV overflow' => ['implied_volatility', 1000000.0],
        ];
    }

    public function test_archive_normalization_matches_eod_null_zero_and_percent_iv_rules(): void
    {
        $archiveRows = $this->validArchiveRows();
        $archiveRows[0]['open_interest'] = null;
        $archiveRows[0]['volume'] = 1;
        $archiveRows[0]['implied_volatility'] = 20.0;
        $archiveRows[0]['delta'] = 0.0;
        $archiveRows[0]['gamma'] = 0.0;
        $archiveRows[0]['vega'] = 0.0;
        $archiveRows[1]['open_interest'] = 1;
        $archiveRows[1]['volume'] = null;
        $archiveRows[1]['implied_volatility'] = 0.0;

        [$runDirectory, $validation] = $this->validatedRun($archiveRows);
        $published = $this->service()->publish(
            $runDirectory,
            (string) $validation['candidate_sha256'],
        );
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', self::SYMBOL)
            ->whereDate('e.expiration_date', self::TARGET_DATE)
            ->whereDate('o.data_date', self::TARGET_DATE)
            ->orderBy('o.option_type')
            ->get(['o.option_type', 'o.open_interest', 'o.volume', 'o.iv', 'o.delta', 'o.gamma', 'o.vega']);

        $this->assertCount(2, $rows);
        $call = $rows->firstWhere('option_type', 'call');
        $put = $rows->firstWhere('option_type', 'put');
        $this->assertSame(0, (int) $call->open_interest);
        $this->assertSame(1, (int) $call->volume);
        $this->assertEqualsWithDelta(0.2, (float) $call->iv, 0.00000001);
        $this->assertSame(0.0, (float) $call->delta);
        $this->assertSame(0.0, (float) $call->gamma);
        $this->assertSame(0.0, (float) $call->vega);
        $this->assertSame(1, (int) $put->open_interest);
        $this->assertSame(0, (int) $put->volume);
        $this->assertNull($put->iv);
    }

    public function test_publish_rolls_back_the_whole_symbol_when_an_insert_fails(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();

        $this->installFailingPutInsertTrigger();
        try {
            $failed = false;
            try {
                $result = $this->service()->publish(
                    $runDirectory,
                    (string) $validation['candidate_sha256'],
                );
                $failed = ! (bool) ($result['ok'] ?? false);
            } catch (Throwable) {
                $failed = true;
            }

            $this->assertTrue($failed, 'The forced insert error must fail publication.');
            $this->assertDatabaseCount('option_chain_data', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS historical_recovery_fail_put');
        }
    }

    public function test_multi_symbol_publish_is_atomic_when_a_later_symbol_fails(): void
    {
        DB::table('prices_daily')->insert([
            'symbol' => 'QQQ',
            'trade_date' => self::TARGET_DATE,
            'open' => 499.0,
            'high' => 503.0,
            'low' => 497.0,
            'close' => 500.0,
        ]);
        $requests = [];
        $this->fakeMultiSymbolProvider($requests, ['QQQ', self::SYMBOL]);
        $archiveRows = [
            ...$this->validArchiveRows('QQQ'),
            ...$this->validArchiveRows(self::SYMBOL),
        ];
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows);
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            ['QQQ', self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );
        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));
        $validation = $this->service()->validate($runDirectory);
        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));

        $failingExpirationIds = [
            $this->expirationId(self::SYMBOL, self::TARGET_DATE),
            $this->expirationId(self::SYMBOL, self::FUTURE_EXPIRY),
        ];
        $this->installFailingExpirationPutInsertTrigger($failingExpirationIds);
        try {
            $result = $this->rejectionResult(fn (): array => $this->service()->publish(
                $runDirectory,
                (string) $validation['candidate_sha256'],
            ));

            $this->assertRejected($result);
            $this->assertSame(
                0,
                DB::table('option_chain_data')->count(),
                'A failed later symbol must roll back rows inserted for every earlier symbol.',
            );
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS historical_recovery_fail_put');
        }
    }

    public function test_rollback_requires_the_exact_run_hash_and_preserves_unrelated_rows(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();
        $candidateSha = (string) $validation['candidate_sha256'];

        $unrelatedExpirationId = $this->expirationId('QQQ', self::TARGET_DATE);
        DB::table('option_chain_data')->insert([
            'expiration_id' => $unrelatedExpirationId,
            'data_date' => self::TARGET_DATE,
            'data_timestamp' => '2026-07-17 21:00:00',
            'option_type' => 'call',
            'strike' => 450.0,
            'open_interest' => 77,
        ]);

        $published = $this->service()->publish($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));
        $this->assertDatabaseCount('option_chain_data', 5);

        $wrongHash = $this->rejectionResult(
            fn (): array => $this->service()->rollback($runDirectory, str_repeat('a', 64)),
        );
        $this->assertRejected($wrongHash);
        $this->assertDatabaseCount('option_chain_data', 5);

        $rolledBack = $this->service()->rollback($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($rolledBack['ok'] ?? false), json_encode($rolledBack));
        $this->assertDatabaseCount('option_chain_data', 1);
        $this->assertDatabaseHas('option_chain_data', [
            'expiration_id' => $unrelatedExpirationId,
            'data_date' => self::TARGET_DATE,
            'option_type' => 'call',
            'strike' => 450.0,
            'open_interest' => 77,
        ]);
    }

    public function test_rollback_resumes_a_prepared_intent_when_exact_receipt_rows_are_already_absent(): void
    {
        [$runDirectory, $validation] = $this->validatedRun();
        $candidateSha = (string) $validation['candidate_sha256'];

        $published = $this->service()->publish($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));
        $this->assertDatabaseCount('option_chain_data', 4);

        $rolledBack = $this->service()->rollback($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($rolledBack['ok'] ?? false), json_encode($rolledBack));
        $this->assertDatabaseCount('option_chain_data', 0);

        $intentPath = $runDirectory.DIRECTORY_SEPARATOR.'rollback-intent.json';
        $intent = $this->assertPreparedIntent($intentPath, 'rollback', $candidateSha, 4);
        $intentFileSha = hash_file('sha256', $intentPath);

        // Simulate a process crash after every receipt-owned slice was deleted
        // but before the rollback receipt and manifest were finalized.
        File::delete($runDirectory.DIRECTORY_SEPARATOR.'rollback-receipt.json');
        $this->setManifestStatus($runDirectory, 'published', ['rolled_back_at']);

        $resumed = $this->service()->rollback($runDirectory, $candidateSha);

        $this->assertTrue((bool) ($resumed['ok'] ?? false), json_encode($resumed));
        $this->assertDatabaseCount('option_chain_data', 0);
        $this->assertSame($intentFileSha, hash_file('sha256', $intentPath));
        $this->assertSame($intent, $this->readJsonArtifact($intentPath));
        $this->assertFileExists($runDirectory.DIRECTORY_SEPARATOR.'rollback-receipt.json');
        $this->assertSame(
            'rolled_back',
            $this->readJsonArtifact($runDirectory.DIRECTORY_SEPARATOR.'manifest.json')['status'] ?? null,
        );
    }

    #[DataProvider('rollbackMutationProvider')]
    public function test_rollback_rejects_a_same_count_slice_with_any_modified_field_or_key(
        string $mutation,
    ): void {
        [$runDirectory, $validation] = $this->validatedRun();
        $candidateSha = (string) $validation['candidate_sha256'];
        $published = $this->service()->publish($runDirectory, $candidateSha);
        $this->assertTrue((bool) ($published['ok'] ?? false), json_encode($published));

        $row = DB::table('option_chain_data')->orderBy('id')->first(['id', 'open_interest', 'strike']);
        DB::table('option_chain_data')->where('id', $row->id)->update($mutation === 'key'
            ? ['strike' => (float) $row->strike + 0.01]
            : ['open_interest' => (int) $row->open_interest + 1]);
        $before = DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $result = $this->rejectionResult(
            fn (): array => $this->service()->rollback($runDirectory, $candidateSha),
        );

        $this->assertRejected($result);
        $this->assertSame(
            $before,
            DB::table('option_chain_data')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'Rollback must verify the persisted field hash before deleting anything.',
        );
    }

    /** @return array<string,array{0:string}> */
    public static function rollbackMutationProvider(): array
    {
        return [
            'field changed' => ['field'],
            'natural key changed' => ['key'],
        ];
    }

    public function test_command_is_read_only_by_default_and_publish_requires_confirmation(): void
    {
        [$runDirectory] = $this->validatedRun();

        $this->artisan('eod:recover-session', ['--run' => $runDirectory])
            ->expectsOutputToContain('"ok":true')
            ->assertSuccessful();
        $this->assertDatabaseCount('option_chain_data', 0);

        $this->artisan('eod:recover-session', [
            '--run' => $runDirectory,
            '--publish' => true,
        ])->expectsOutputToContain('"ok":false')
            ->assertFailed();
        $this->assertDatabaseCount('option_chain_data', 0);
    }

    /**
     * @param  array<int,string>  $expectedExpiries
     * @param  array<int,array<string,mixed>>  $requests
     */
    private function fakeProvider(
        array &$requests,
        array $expectedExpiries = [self::TARGET_DATE, self::FUTURE_EXPIRY],
        string $failureMode = '',
    ): void {
        Http::fake(function (Request $request) use (&$requests, $expectedExpiries, $failureMode) {
            $url = (string) $request->url();
            $params = $this->requestParameters($request);

            if (str_contains($url, '/v3/reference/options/contracts')) {
                $expired = $this->booleanParameter($params['expired'] ?? false);
                $requests[] = [
                    'kind' => 'reference',
                    'underlying_ticker' => (string) ($params['underlying_ticker'] ?? ''),
                    'gte' => (string) ($params['expiration_date.gte'] ?? ''),
                    'lte' => (string) ($params['expiration_date.lte'] ?? ''),
                    'as_of' => (string) ($params['as_of'] ?? ''),
                    'expired' => $params['expired'] ?? null,
                    'expired_present' => array_key_exists('expired', $params),
                ];

                $catalogExpiries = $expired
                    ? array_values(array_intersect($expectedExpiries, [self::TARGET_DATE]))
                    : array_values(array_diff($expectedExpiries, [self::TARGET_DATE]));
                $contracts = $this->referenceContracts($catalogExpiries);

                if ($failureMode === 'cross_catalog_overlap' && $expired) {
                    $contracts = [
                        ...$contracts,
                        ...$this->referenceContracts([self::FUTURE_EXPIRY]),
                    ];
                }
                if ($failureMode === 'duplicate_reference_contract' && ! $expired && $contracts !== []) {
                    $contracts[] = $contracts[0];
                }
                if ($failureMode === 'additional_underlying' && ! $expired && $contracts !== []) {
                    $contracts[0]['additional_underlyings'] = [[
                        'underlying' => 'SPY1',
                        'amount' => 1,
                    ]];
                }
                if ($failureMode === 'wrong_multiplier' && ! $expired && $contracts !== []) {
                    $contracts[0]['shares_per_contract'] = 10;
                }
                if ($failureMode === 'fractional_target_strike' && $expired) {
                    $contracts = array_map(function (array $contract): array {
                        $contract['strike_price'] = 500.125;
                        $contract['ticker'] = $this->optionTicker(
                            self::SYMBOL,
                            self::TARGET_DATE,
                            (string) $contract['contract_type'],
                            500.125,
                        );

                        return $contract;
                    }, $contracts);
                }

                return Http::response([
                    'status' => $failureMode === 'bad_status' ? 'ERROR' : 'OK',
                    'results' => $contracts,
                ]);
            }

            if (str_contains($url, '/v3/snapshot/options/'.self::SYMBOL)) {
                $expiry = (string) ($params['expiration_date'] ?? '');
                $side = strtolower((string) ($params['contract_type'] ?? ''));
                $requests[] = [
                    'kind' => 'snapshot',
                    'expiry' => $expiry,
                    'side' => $side,
                ];

                if ($expiry === '' || ! in_array($side, ['call', 'put'], true)) {
                    return Http::response(['error' => 'unpartitioned request'], 422);
                }

                if ($failureMode === 'http_error' && $expiry === self::FUTURE_EXPIRY && $side === 'put') {
                    return Http::response(['error' => 'provider unavailable'], 503);
                }

                if ($failureMode === 'empty_partition' && $expiry === self::FUTURE_EXPIRY && $side === 'put') {
                    return Http::response(['status' => 'OK', 'results' => []]);
                }

                if ($failureMode === 'pagination_cycle' && $expiry === self::FUTURE_EXPIRY && $side === 'put') {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [$this->currentContract($expiry, $side)],
                        'next_url' => self::BASE.'/v3/snapshot/options/'.self::SYMBOL.'?'.http_build_query([
                            'expiration_date' => $expiry,
                            'contract_type' => $side,
                            'cursor' => 'repeat',
                        ]),
                    ]);
                }

                if ($failureMode === 'archive_collision' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    return Http::response([
                        'status' => 'OK',
                        'results' => [$this->currentContract(self::TARGET_DATE, $side)],
                    ]);
                }

                $contract = $this->currentContract($expiry, $side);
                if ($failureMode === 'missing_underlying' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    unset($contract['underlying_asset']['ticker']);
                    unset($contract['details']['underlying_ticker']);
                }
                if ($failureMode === 'negative_snapshot_greeks' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    $contract['greeks']['gamma'] = -0.0000005597;
                    $contract['greeks']['vega'] = -0.25;
                }
                if ($failureMode === 'missing_snapshot_volume' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    unset($contract['session']['volume'], $contract['day']['volume']);
                }
                if ($failureMode === 'future_source_timestamp' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    $contract['underlying_asset']['last_updated'] =
                        Carbon::parse('2026-07-20 14:00:00', 'UTC')->timestamp * 1_000_000_000;
                }
                if (
                    in_array($failureMode, ['priceless_day_marker', 'priceless_session_marker'], true)
                    && $expiry === self::FUTURE_EXPIRY
                ) {
                    unset(
                        $contract['underlying_asset']['price'],
                        $contract['underlying_asset']['last_updated']
                    );
                    $markerField = $failureMode === 'priceless_day_marker' ? 'day' : 'session';
                    $contract[$markerField]['last_updated'] =
                        Carbon::parse('2026-07-17 00:00:00', 'America/New_York')
                            ->utc()->timestamp * 1_000_000_000;
                }
                if ($failureMode === 'future_daily_marker' && $expiry === self::FUTURE_EXPIRY) {
                    unset($contract['underlying_asset']['last_updated']);
                    $contract['day']['last_updated'] =
                        Carbon::parse('2026-07-20 00:00:00', 'America/New_York')
                            ->utc()->timestamp * 1_000_000_000;
                }
                if ($failureMode === 'missing_source_timestamp' && $expiry === self::FUTURE_EXPIRY) {
                    unset(
                        $contract['underlying_asset']['last_updated'],
                        $contract['day']['last_updated'],
                        $contract['session']['last_updated']
                    );
                }
                if ($failureMode === 'side_spot_mismatch' && $expiry === self::FUTURE_EXPIRY && $side === 'put') {
                    $contract['underlying_asset']['price'] = 502.0;
                }
                if ($failureMode === 'hybrid_spot_difference' && $expiry === self::FUTURE_EXPIRY) {
                    $contract['underlying_asset']['price'] = 520.0;
                }
                $contracts = [$contract];
                if ($failureMode === 'duplicate_snapshot_contract' && $expiry === self::FUTURE_EXPIRY && $side === 'call') {
                    $contracts[] = $contract;
                }

                return Http::response([
                    'status' => $failureMode === 'bad_status' ? 'ERROR' : 'OK',
                    'results' => $contracts,
                ]);
            }

            return Http::response([], 404);
        });
    }

    /** @param array<int,array<string,mixed>> $requests @param array<int,string> $symbols */
    private function fakeMultiSymbolProvider(array &$requests, array $symbols): void
    {
        Http::fake(function (Request $request) use (&$requests, $symbols) {
            $url = (string) $request->url();
            $params = $this->requestParameters($request);

            if (str_contains($url, '/v3/reference/options/contracts')) {
                $symbol = strtoupper((string) ($params['underlying_ticker'] ?? ''));
                if (! in_array($symbol, $symbols, true)) {
                    return Http::response(['error' => 'unexpected symbol'], 422);
                }
                $expired = $this->booleanParameter($params['expired'] ?? false);
                $requests[] = [
                    'kind' => 'reference',
                    'symbol' => $symbol,
                    'expired' => $expired,
                ];

                return Http::response([
                    'status' => 'OK',
                    'results' => $this->referenceContracts(
                        [$expired ? self::TARGET_DATE : self::FUTURE_EXPIRY],
                        $symbol,
                    ),
                ]);
            }

            if (preg_match('#/v3/snapshot/options/([^/?]+)#', $url, $matches) === 1) {
                $symbol = strtoupper(rawurldecode($matches[1]));
                $expiry = (string) ($params['expiration_date'] ?? '');
                $side = strtolower((string) ($params['contract_type'] ?? ''));
                if (
                    ! in_array($symbol, $symbols, true)
                    || $expiry !== self::FUTURE_EXPIRY
                    || ! in_array($side, ['call', 'put'], true)
                ) {
                    return Http::response(['error' => 'invalid partition'], 422);
                }
                $requests[] = [
                    'kind' => 'snapshot',
                    'symbol' => $symbol,
                    'expiry' => $expiry,
                    'side' => $side,
                ];

                return Http::response([
                    'status' => 'OK',
                    'results' => [$this->currentContract($expiry, $side, $symbol)],
                ]);
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

    private function booleanParameter(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<int,string> $expiries */
    private function referenceContracts(array $expiries, string $symbol = self::SYMBOL): array
    {
        $rows = [];
        foreach ($expiries as $expiry) {
            foreach (['call', 'put'] as $side) {
                $rows[] = [
                    'ticker' => $this->optionTicker($symbol, $expiry, $side, 500.0),
                    'underlying_ticker' => $symbol,
                    'contract_type' => $side,
                    'expiration_date' => $expiry,
                    'strike_price' => 500.0,
                    'shares_per_contract' => 100,
                ];
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function currentContract(string $expiry, string $side, string $symbol = self::SYMBOL): array
    {
        $openInterest = $side === 'call' ? 3333 : 4444;
        $volume = $side === 'call' ? 333 : 444;

        return [
            'details' => [
                'ticker' => $this->optionTicker($symbol, $expiry, $side, 500.0),
                'underlying_ticker' => $symbol,
                'contract_type' => $side,
                'expiration_date' => $expiry,
                'strike_price' => 500.0,
                'shares_per_contract' => 100,
            ],
            'underlying_asset' => [
                'ticker' => $symbol,
                'price' => 501.0,
                'last_updated' => 1784328000000000000,
            ],
            'open_interest' => $openInterest,
            'day' => ['volume' => $volume, 'close' => 12.5],
            'session' => ['volume' => $volume, 'close' => 12.5],
            'implied_volatility' => 0.21,
            'greeks' => [
                'delta' => $side === 'call' ? 0.52 : -0.48,
                'gamma' => 0.01,
                'vega' => 0.2,
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function validArchiveRows(string $symbol = self::SYMBOL): array
    {
        return [
            $this->archiveRow('call', 1111, 111, $symbol),
            $this->archiveRow('put', 2222, 222, $symbol),
        ];
    }

    /** @return array<string,mixed> */
    private function archiveRow(
        string $side,
        int $openInterest,
        int $volume,
        string $symbol = self::SYMBOL,
    ): array {
        return [
            'symbol' => $symbol,
            'contract_symbol' => $this->optionTicker(
                $symbol,
                self::TARGET_DATE,
                $side,
                500.0,
            ),
            'contract_type' => $side,
            'expiration_date' => self::TARGET_DATE,
            'strike_price' => 500.0,
            'volume' => $volume,
            'open_interest' => $openInterest,
            'implied_volatility' => 0.2,
            'delta' => $side === 'call' ? 0.5 : -0.5,
            'gamma' => 0.01,
            'theta' => -0.02,
            'vega' => 0.2,
            'last_price' => 10.0,
            'change' => 1.0,
            'change_percent' => 10.0,
            'request_id' => 'archive-request-target-expiry',
            'captured_at' => '2026-07-17T20:18:00+00:00',
            'underlying_price' => 500.0,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function invalidArchiveRows(string $case): array
    {
        $rows = $this->validArchiveRows();

        return match ($case) {
            'empty' => [],
            'missing_side' => [$rows[0]],
            'wrong_capture_day' => array_map(function (array $row): array {
                $row['captured_at'] = '2026-07-18T12:00:00+00:00';

                return $row;
            }, $rows),
            'empty_capture_timestamp' => array_map(function (array $row): array {
                $row['captured_at'] = '';

                return $row;
            }, $rows),
            'pre_close_capture' => array_map(function (array $row): array {
                $row['captured_at'] = '2026-07-17T19:59:00+00:00';

                return $row;
            }, $rows),
            'wrong_expiry' => array_map(function (array $row): array {
                $row['expiration_date'] = self::FUTURE_EXPIRY;
                $row['contract_symbol'] = $this->optionTicker(
                    self::SYMBOL,
                    self::FUTURE_EXPIRY,
                    (string) $row['contract_type'],
                    (float) $row['strike_price'],
                );

                return $row;
            }, $rows),
            'wrong_symbol' => array_map(function (array $row): array {
                $row['symbol'] = 'QQQ';

                return $row;
            }, $rows),
            'wrong_side' => array_map(function (array $row): array {
                $row['contract_type'] = 'other';

                return $row;
            }, $rows),
            'collision' => [...$rows, array_merge($rows[0], [
                'contract_symbol' => 'O:SPY260717C00500001',
            ])],
            'strike_precision' => array_map(function (array $row): array {
                $row['strike_price'] = 500.125;
                $row['contract_symbol'] = $this->optionTicker(
                    self::SYMBOL,
                    self::TARGET_DATE,
                    (string) $row['contract_type'],
                    500.125,
                );

                return $row;
            }, $rows),
            'archive_spot_mismatch' => array_map(function (array $row): array {
                if ($row['contract_type'] === 'put') {
                    $row['underlying_price'] = 501.0;
                }

                return $row;
            }, $rows),
            default => throw new \InvalidArgumentException("Unknown archive test case: {$case}"),
        };
    }

    /** @param array<int,array<string,mixed>> $rows @return array{0:string,1:string} */
    private function writeArchive(array $rows): array
    {
        $path = $this->temporaryRoot.DIRECTORY_SEPARATOR.'archive-'.bin2hex(random_bytes(4)).'.ndjson';
        $payload = collect($rows)
            ->map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->implode(PHP_EOL);
        File::put($path, $payload === '' ? '' : $payload.PHP_EOL);

        return [$path, hash_file('sha256', $path)];
    }

    /** @return array<string,mixed> */
    private function captureThenValidate(string $archivePath, string $archiveSha, string $runDirectory): array
    {
        try {
            $capture = $this->service()->capture(
                self::TARGET_DATE,
                [self::SYMBOL],
                $archivePath,
                $archiveSha,
                $runDirectory,
            );

            if (! (bool) ($capture['ok'] ?? false)) {
                return $capture;
            }

            return $this->service()->validate($runDirectory);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /** @param null|array<int,array<string,mixed>> $archiveRows @return array{0:string,1:array<string,mixed>} */
    private function validatedRun(?array $archiveRows = null): array
    {
        $requests = [];
        $this->fakeProvider($requests);
        [$archivePath, $archiveSha] = $this->writeArchive($archiveRows ?? $this->validArchiveRows());
        $runDirectory = $this->runDirectory();

        $capture = $this->service()->capture(
            self::TARGET_DATE,
            [self::SYMBOL],
            $archivePath,
            $archiveSha,
            $runDirectory,
        );
        $this->assertTrue((bool) ($capture['ok'] ?? false), json_encode($capture));

        $validation = $this->service()->validate($runDirectory);
        $this->assertTrue((bool) ($validation['ok'] ?? false), json_encode($validation));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) ($validation['candidate_sha256'] ?? ''),
        );
        $this->assertDatabaseCount('option_chain_data', 0);

        return [$runDirectory, $validation];
    }

    private function service(): HistoricalEodRecoveryService
    {
        return app(HistoricalEodRecoveryService::class);
    }

    private function runDirectory(): string
    {
        return $this->temporaryRoot.DIRECTORY_SEPARATOR.'run-'.bin2hex(random_bytes(4));
    }

    /** @return array<string,string> */
    private function artifactHashes(string $directory): array
    {
        return collect(File::allFiles($directory))
            ->mapWithKeys(static fn ($file): array => [
                $file->getPathname() => hash_file('sha256', $file->getPathname()),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    private function assertPreparedIntent(
        string $path,
        string $type,
        string $candidateSha,
        int $expectedRows,
    ): array {
        $this->assertFileExists($path);
        $intent = $this->readJsonArtifact($path);

        $this->assertSame(1, (int) ($intent['version'] ?? 0));
        $this->assertSame($type, $intent['type'] ?? null);
        $this->assertSame('prepared', $intent['status'] ?? null);
        $this->assertSame(self::TARGET_DATE, $intent['date'] ?? null);
        $this->assertSame($candidateSha, $intent['candidate_sha256'] ?? null);
        $this->assertNotEmpty($intent['prepared_at'] ?? null);

        $symbols = array_values((array) ($intent['symbols'] ?? []));
        $sortedSymbols = $symbols;
        sort($sortedSymbols);
        $this->assertSame($sortedSymbols, $symbols, 'Intent symbols must be deterministically sorted.');
        $this->assertSame([self::SYMBOL], $symbols);

        $expectations = (array) ($intent['expectations'] ?? []);
        $this->assertSame($symbols, array_keys($expectations));
        $expectation = (array) ($expectations[self::SYMBOL] ?? []);
        $this->assertSame($expectedRows, (int) ($expectation['rows'] ?? -1));
        foreach (['natural_key_sha256', 'full_slice_sha256'] as $field) {
            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/',
                (string) ($expectation[$field] ?? ''),
            );
        }

        $originalIntent = $intent;
        $intentSha = strtolower((string) ($intent['intent_sha256'] ?? ''));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $intentSha);
        unset($intent['intent_sha256']);
        $this->assertSame(
            hash('sha256', $this->canonicalArtifactJson($intent)),
            $intentSha,
            'Prepared intent self-hash must bind every operation expectation.',
        );

        return $originalIntent;
    }

    /** @return array<string,mixed> */
    private function readJsonArtifact(string $path): array
    {
        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<int,string> $removeFields */
    private function setManifestStatus(string $runDirectory, string $status, array $removeFields): void
    {
        $path = $runDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = $this->readJsonArtifact($path);
        $manifest['status'] = $status;
        foreach ($removeFields as $field) {
            unset($manifest[$field]);
        }
        File::put($path, json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ).PHP_EOL);
    }

    private function canonicalArtifactJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalArtifactValue($value),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalArtifactValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalArtifactValue($item),
                $value,
            );
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalArtifactValue($item);
        }

        return $value;
    }

    private function assertRejected(array $result): void
    {
        $this->assertFalse((bool) ($result['ok'] ?? true), json_encode($result));
        $this->assertNotEmpty($result['errors'] ?? $result['error'] ?? null, json_encode($result));
    }

    /** @param array<string,mixed> $validation */
    private function candidateRowCount(array $validation): int
    {
        return (int) collect((array) ($validation['symbols'] ?? []))
            ->sum(fn (array $symbol): int => (int) ($symbol['candidate_rows'] ?? 0));
    }

    /** @param callable():array<string,mixed> $operation @return array<string,mixed> */
    private function rejectionResult(callable $operation): array
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function expirationId(string $symbol, string $expirationDate): int
    {
        return (int) DB::table('option_expirations')->insertGetId([
            'symbol' => $symbol,
            'expiration_date' => $expirationDate,
        ]);
    }

    private function installFailingPutInsertTrigger(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER historical_recovery_fail_put
                BEFORE INSERT ON option_chain_data
                WHEN NEW.option_type = 'put'
                BEGIN
                    SELECT RAISE(ABORT, 'forced recovery publish failure');
                END
                SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER historical_recovery_fail_put
                BEFORE INSERT ON option_chain_data
                FOR EACH ROW
                BEGIN
                    IF NEW.option_type = 'put' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced recovery publish failure';
                    END IF;
                END
                SQL);

            return;
        }

        $this->markTestSkipped("No failure trigger fixture for database driver [{$driver}].");
    }

    /** @param array<int,int> $expirationIds */
    private function installFailingExpirationPutInsertTrigger(array $expirationIds): void
    {
        $ids = implode(',', array_map(static fn (int $id): int => $id, $expirationIds));
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared(<<<SQL
                CREATE TRIGGER historical_recovery_fail_put
                BEFORE INSERT ON option_chain_data
                WHEN NEW.option_type = 'put' AND NEW.expiration_id IN ({$ids})
                BEGIN
                    SELECT RAISE(ABORT, 'forced recovery publish failure');
                END
                SQL);

            return;
        }

        if ($driver === 'mysql') {
            DB::unprepared(<<<SQL
                CREATE TRIGGER historical_recovery_fail_put
                BEFORE INSERT ON option_chain_data
                FOR EACH ROW
                BEGIN
                    IF NEW.option_type = 'put' AND NEW.expiration_id IN ({$ids}) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced recovery publish failure';
                    END IF;
                END
                SQL);

            return;
        }

        $this->markTestSkipped("No failure trigger fixture for database driver [{$driver}].");
    }

    private function optionTicker(
        string $symbol,
        string $expirationDate,
        string $side,
        float $strike,
    ): string {
        return sprintf(
            'O:%s%s%s%08d',
            $symbol,
            Carbon::parse($expirationDate)->format('ymd'),
            $side === 'call' ? 'C' : 'P',
            (int) round($strike * 1000),
        );
    }
}
