<?php

namespace Tests\Feature;

use App\Jobs\FetchCalculatorChainJob;
use App\Support\CalculatorPublicationRepository;
use App\Support\CalculatorRefreshState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FetchCalculatorChainJobStateTest extends TestCase
{
    use DatabaseTransactions;

    private CarbonImmutable $now;

    private CalculatorRefreshState $states;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-12 10:00:00', 'America/New_York');
        Carbon::setTestNow($this->now);
        Cache::flush();

        if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('option_snapshots')) {
            Schema::create('option_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol');
                $table->string('ticker');
                $table->string('type');
                $table->decimal('strike', 12, 2);
                $table->date('expiry');
                $table->decimal('bid', 10, 2);
                $table->decimal('ask', 10, 2);
                $table->decimal('mid', 10, 2);
                $table->decimal('implied_volatility', 10, 6)->nullable();
                $table->decimal('underlying_price', 12, 2)->nullable();
                $table->timestamp('fetched_at');
                $table->unique(
                    ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
                    'option_snapshots_contract_fetch_unique'
                );
            });
        }

        if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('underlying_quotes')) {
            Schema::create('underlying_quotes', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol')->unique();
                $table->string('source')->nullable();
                $table->decimal('last_price', 14, 6);
                $table->decimal('prev_close', 14, 6)->nullable();
                $table->timestamp('asof');
                $table->timestamps();
            });
        }

        if (DB::getDriverName() === 'sqlite' && ! Schema::hasTable('option_expirations')) {
            Schema::create('option_expirations', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 32);
                $table->date('expiration_date');
                $table->timestamps();
                $table->unique(['symbol', 'expiration_date']);
            });
        }

        if (! Schema::hasTable('calculator_publication_runs')) {
            $migration = require database_path(
                'migrations/2026_08_16_000003_create_calculator_publication_tables.php'
            );
            $migration->up();
        }

        config()->set('queue_lanes.isolated', false);
        config()->set('services.massive.concurrency.enabled', false);
        config()->set('services.massive.key', 'massive-test');
        config()->set('services.massive.base', 'https://api.massive.test');
        config()->set('services.massive.mode', 'header');

        $this->states = app(CalculatorRefreshState::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_scheduled_job_moves_from_pending_to_started_to_completed_after_rows_commit(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-success');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                $this->assertSame(
                    CalculatorRefreshState::STATUS_STARTED,
                    $this->states->get('AAPL')['status'] ?? null
                );

                return Http::response([
                    'results' => [[
                        'ticker' => 'O:AAPL260821C00150000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 2.0, 'ask' => 2.2],
                    ]],
                ]);
            }

            return Http::response([
                'results' => [[
                    'last_quote' => ['midpoint' => 150.0],
                ]],
            ]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $state['status'] ?? null);
        $this->assertSame($generation, $state['last_success_generation'] ?? null);
        $this->assertNotNull($state['completed_at'] ?? null);
        $this->assertSame(1, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $this->assertNull(DB::table('option_snapshots')->where('symbol', 'AAPL')->value('underlying_price'));
        $catalog = app(CalculatorPublicationRepository::class)->authoritativeCatalog('AAPL');
        $this->assertSame('complete', $catalog['state']);
        $this->assertSame(1, $catalog['expected_count']);
        $this->assertSame(1, $catalog['completed_count']);
        $this->assertSame('2026-08-21', $catalog['expirations'][0]['expiration']);
        $this->assertSame(1, $catalog['expirations'][0]['row_count']);
    }

    public function test_terminal_empty_discovery_publishes_a_truthful_empty_catalog(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-empty');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $state['status'] ?? null);
        $this->assertNotNull($state['completed_at'] ?? null);
        $this->assertNotNull($state['last_success_at'] ?? null);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $run = DB::table('calculator_publication_runs')->where('symbol', 'AAPL')->first();
        $this->assertSame('complete', $run->status);
        $this->assertSame(0, (int) $run->expected_count);
        $this->assertDatabaseHas('calculator_catalog_heads', [
            'symbol' => 'AAPL',
            'current_run_id' => $run->id,
        ]);
    }

    public function test_terminal_empty_discovery_cannot_replace_a_nonempty_last_known_good_catalog(): void
    {
        $publications = app(CalculatorPublicationRepository::class);
        $baselineAt = $this->now->subMinute();
        $baseline = $publications->startCatalogRun(
            'AAPL',
            ownerKey: 'test:nonempty-baseline',
            at: $baselineAt
        );
        $publications->freezeCatalog(
            (string) $baseline['id'],
            ['2026-08-21'],
            'test',
            $baselineAt,
            terminalCursorReached: true,
            at: $baselineAt
        );
        $baselinePublication = $publications->stageAndPublishExpiry(
            (string) $baseline['id'],
            '2026-08-21',
            'test',
            $baselineAt,
            $baselineAt,
            [[
                'ticker' => 'CANONICAL-LKG',
                'type' => 'call',
                'strike' => 150,
                'bid' => 2,
                'ask' => 2.2,
                'mid' => 2.1,
                'implied_volatility' => 0.2,
            ]],
            $baselineAt
        );
        $publications->completeCatalog((string) $baseline['id'], $baselineAt);
        DB::table('option_snapshots')->insert([
            'symbol' => 'AAPL',
            'ticker' => 'LEGACY-LKG',
            'type' => 'call',
            'strike' => 150,
            'expiry' => '2026-08-21',
            'bid' => 2,
            'ask' => 2.2,
            'mid' => 2.1,
            'implied_volatility' => 0.2,
            'underlying_price' => 150,
            'fetched_at' => $baselineAt,
        ]);

        [$generation, $token] = $this->claim('AAPL', 'generation-empty-after-nonempty');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 151.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $candidate = $publications->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame('provider_empty_after_nonempty', $candidate['run']['failure_code']);
        $this->assertSame($baseline['id'], $publications->catalogHead('AAPL')['current_run_id']);
        $this->assertSame(
            $baselinePublication['publication_id'],
            $publications->expiryHead('AAPL', '2026-08-21')['current_publication_id']
        );
        $this->assertSame('CANONICAL-LKG', $publications->publishedExpiry(
            'AAPL',
            '2026-08-21'
        )['rows'][0]['ticker']);
        $this->assertSame(1, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(1, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame(1, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $this->assertSame(
            'LEGACY-LKG',
            DB::table('option_snapshots')->where('symbol', 'AAPL')->value('ticker')
        );
    }

    public function test_first_canonical_empty_discovery_cannot_hide_legacy_last_known_good_rows(): void
    {
        $legacyAt = $this->now->subMinute();
        DB::table('option_snapshots')->insert([
            'symbol' => 'AAPL',
            'ticker' => 'LEGACY-ONLY-LKG',
            'type' => 'call',
            'strike' => 150,
            'expiry' => '2026-08-21',
            'bid' => 2,
            'ask' => 2.2,
            'mid' => 2.1,
            'implied_volatility' => 0.2,
            'underlying_price' => 150,
            'fetched_at' => $legacyAt,
        ]);
        [$generation, $token] = $this->claim('AAPL', 'generation-empty-over-legacy');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => []]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 151.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $candidate = app(CalculatorPublicationRepository::class)->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame('provider_empty_after_nonempty', $candidate['run']['failure_code']);
        $this->assertNull(app(CalculatorPublicationRepository::class)->catalogHead('AAPL'));
        $this->assertSame(0, DB::table('calculator_expiry_publications')->count());
        $this->assertSame(0, DB::table('calculator_expiry_publication_rows')->count());
        $this->assertSame(1, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $this->assertSame(
            'LEGACY-ONLY-LKG',
            DB::table('option_snapshots')->where('symbol', 'AAPL')->value('ticker')
        );
    }

    public function test_selected_expiry_publication_does_not_create_a_partial_legacy_symbol_cohort(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [[
                    'ticker' => 'O:AAPL260821C00150000',
                    'details' => [
                        'contract_type' => 'call',
                        'strike_price' => 150,
                        'expiration_date' => '2026-08-21',
                    ],
                    'last_quote' => ['bid' => 2, 'ask' => 2.2],
                ]]]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', '2026-08-21'))->handle();

        $publications = app(CalculatorPublicationRepository::class);
        $published = $publications->publishedExpiry('AAPL', '2026-08-21');
        $this->assertSame('O:AAPL260821C00150000', $published['rows'][0]['ticker']);
        $this->assertSame('complete', $publications->run($published['run_id'])['status']);
        $this->assertNull($publications->catalogHead('AAPL'));
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_adjusted_same_strike_contracts_publish_without_collapsing_identity(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-adjusted-contracts');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [
                    [
                        'ticker' => 'O:AAPL260821C00150000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 2, 'ask' => 2.2],
                    ],
                    [
                        'ticker' => 'O:AAPL1260821C00150000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 3, 'ask' => 3.2],
                    ],
                ]]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
        $published = app(CalculatorPublicationRepository::class)
            ->publishedExpiry('AAPL', '2026-08-21');
        $this->assertSame(2, $published['row_count']);
        $this->assertSame(
            ['O:AAPL1260821C00150000', 'O:AAPL260821C00150000'],
            collect($published['rows'])->pluck('ticker')->sort()->values()->all()
        );
        $this->assertCount(2, collect($published['rows'])->pluck('contract_key')->unique());
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_discovered_expiry_with_no_usable_rows_blocks_complete_catalog(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-unusable-expiry');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [
                    [
                        'ticker' => 'O:AAPL260821C00150000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 2, 'ask' => 2.2],
                    ],
                    [
                        'ticker' => 'O:AAPL260828X00150000',
                        'details' => [
                            'contract_type' => 'unknown',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-28',
                        ],
                        'last_quote' => ['bid' => 1, 'ask' => 1.2],
                    ],
                ]]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $publications = app(CalculatorPublicationRepository::class);
        $candidate = $publications->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame(2, (int) $candidate['run']['expected_count']);
        $this->assertSame(1, (int) $candidate['run']['completed_count']);
        $this->assertSame(1, (int) $candidate['run']['failed_count']);
        $this->assertSame('expiration_failed', $candidate['run']['failure_code']);
        $this->assertNotNull($candidate['run']['completed_at']);
        $failed = collect($candidate['expirations'])->firstWhere('expiration', '2026-08-28');
        $this->assertSame('failed', $failed['readiness']);
        $this->assertSame('expected_expiry_missing', $failed['failure_code']);
        $this->assertNull($publications->catalogHead('AAPL'));
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_catalog_membership_preserves_zero_dte_and_excludes_noncanonical_or_expired_dates(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-date-membership');

        $contract = static fn (string $ticker, string $expiration, float $strike): array => [
            'ticker' => $ticker,
            'details' => [
                'contract_type' => 'call',
                'strike_price' => $strike,
                'expiration_date' => $expiration,
            ],
            'last_quote' => ['bid' => 1, 'ask' => 1.2],
        ];

        Http::fake(function (Request $request) use ($contract) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [
                    $contract('ZERO-DTE', '2026-08-12', 150),
                    $contract('FUTURE', '2026-08-21', 151),
                    $contract('EXPIRED', '2026-08-11', 152),
                    $contract('NONCANONICAL', '2026-08-28T00:00:00Z', 153),
                    $contract('INVALID-DATE', '2026-02-30', 154),
                ]]);
            }

            return Http::response(['results' => [['last_quote' => ['midpoint' => 150.0]]]]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
        $catalog = app(CalculatorPublicationRepository::class)->authoritativeCatalog('AAPL');
        $this->assertSame('complete', $catalog['state']);
        $this->assertSame(
            ['2026-08-12', '2026-08-21'],
            collect($catalog['expirations'])->pluck('expiration')->all()
        );
        $this->assertSame(
            ['FUTURE', 'ZERO-DTE'],
            DB::table('option_snapshots')->where('symbol', 'AAPL')->orderBy('ticker')->pluck('ticker')->all()
        );
    }

    public function test_a_timestamped_real_one_hundred_dollar_quote_and_provider_iv_are_persisted(): void
    {
        [$generation, $token] = $this->claim('EXACT', 'generation-exact-price');
        $asofNanoseconds = $this->now->utc()->format('U').'000000000';

        Http::fake(function (Request $request) use ($asofNanoseconds) {
            if (str_contains($request->url(), '/v3/snapshot/options/EXACT')) {
                return Http::response([
                    'results' => [[
                        'ticker' => 'O:EXACT260821C00100000',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 100,
                            'expiration_date' => '2026-08-21',
                        ],
                        'implied_volatility' => 0.25,
                        'last_quote' => ['bid' => 2.0, 'ask' => 2.2],
                    ]],
                ]);
            }

            return Http::response([
                'results' => [[
                    'updated' => $asofNanoseconds,
                    'last_quote' => ['midpoint' => 100.0],
                ]],
            ]);
        });

        (new FetchCalculatorChainJob('EXACT', null, $generation, $token))->handle();

        $quote = DB::table('underlying_quotes')->where('symbol', 'EXACT')->first();
        $snapshot = DB::table('option_snapshots')->where('symbol', 'EXACT')->first();
        $this->assertSame(100.0, (float) $quote->last_price);
        $this->assertSame('massive-v3-snapshot', $quote->source);
        $this->assertSame(100.0, (float) $snapshot->underlying_price);
        $this->assertSame(0.25, (float) $snapshot->implied_volatility);
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('EXACT')['status']);
        $published = app(CalculatorPublicationRepository::class)
            ->publishedExpiry('EXACT', '2026-08-21');
        $this->assertSame(0.25, (float) $published['rows'][0]['implied_volatility']);
        $this->assertSame('O:EXACT260821C00100000', $published['rows'][0]['ticker']);
    }

    public function test_http_failure_after_a_page_cannot_replace_the_complete_catalog(): void
    {
        $publications = app(CalculatorPublicationRepository::class);
        $baselineAt = $this->now->subMinute();
        $baseline = $publications->startCatalogRun(
            'AAPL',
            ownerKey: 'test:baseline',
            at: $baselineAt
        );
        $publications->freezeCatalog(
            (string) $baseline['id'],
            ['2026-08-21'],
            'test',
            $baselineAt,
            terminalCursorReached: true,
            at: $baselineAt
        );
        $publication = $publications->stageAndPublishExpiry(
            (string) $baseline['id'],
            '2026-08-21',
            'test',
            $baselineAt,
            $baselineAt,
            [[
                'ticker' => 'BASELINE',
                'type' => 'call',
                'strike' => 150,
                'bid' => 2,
                'ask' => 2.2,
                'mid' => 2.1,
                'implied_volatility' => 0.2,
            ]],
            $baselineAt
        );
        $publications->completeCatalog((string) $baseline['id'], $baselineAt);

        [$generation, $token] = $this->claim('AAPL', 'generation-http-failure');
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/page-2')) {
                return Http::response(['error' => 'provider unavailable'], 503);
            }
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response([
                    'results' => [[
                        'ticker' => 'NEW-PARTIAL',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 151,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 1.9, 'ask' => 2.1],
                    ]],
                    'next_url' => 'https://api.massive.test/page-2',
                ]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(
            CalculatorRefreshState::STATUS_FAILED,
            $this->states->get('AAPL')['status'] ?? null
        );
        $head = $publications->catalogHead('AAPL');
        $this->assertSame($baseline['id'], $head['current_run_id']);
        $expiry = $publications->publishedExpiry('AAPL', '2026-08-21');
        $this->assertSame($publication['publication_id'], $expiry['publication_id']);
        $this->assertSame('BASELINE', $expiry['rows'][0]['ticker']);
        $candidate = $publications->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame('provider_http_error', $candidate['run']['failure_code']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_empty_intermediate_page_follows_its_next_cursor_before_publishing(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-empty-intermediate');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/empty-page-2')) {
                return Http::response(['results' => [[
                    'ticker' => 'O:AAPL260821C00150000',
                    'details' => [
                        'contract_type' => 'call',
                        'strike_price' => 150,
                        'expiration_date' => '2026-08-21',
                    ],
                    'last_quote' => ['bid' => 2, 'ask' => 2.2],
                ]]]);
            }
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response([
                    'results' => [],
                    'next_url' => 'https://api.massive.test/empty-page-2',
                ]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
        $this->assertSame(1, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $catalog = app(CalculatorPublicationRepository::class)->authoritativeCatalog('AAPL');
        $this->assertSame('complete', $catalog['state']);
        $this->assertSame(['2026-08-21'], collect($catalog['expirations'])->pluck('expiration')->all());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/empty-page-2'));
    }

    public function test_malformed_success_payload_cannot_replace_the_last_complete_catalog(): void
    {
        $publications = app(CalculatorPublicationRepository::class);
        $baselineAt = $this->now->subMinute();
        $baseline = $publications->startCatalogRun('AAPL', ownerKey: 'test:malformed-baseline', at: $baselineAt);
        $publications->freezeCatalog(
            (string) $baseline['id'],
            ['2026-08-21'],
            'test',
            $baselineAt,
            terminalCursorReached: true,
            at: $baselineAt
        );
        $baselinePublication = $publications->stageAndPublishExpiry(
            (string) $baseline['id'],
            '2026-08-21',
            'test',
            $baselineAt,
            $baselineAt,
            [[
                'ticker' => 'BASELINE',
                'type' => 'call',
                'strike' => 150,
                'bid' => 2,
                'ask' => 2.2,
                'mid' => 2.1,
            ]],
            $baselineAt
        );
        $publications->completeCatalog((string) $baseline['id'], $baselineAt);
        [$generation, $token] = $this->claim('AAPL', 'generation-invalid-payload');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['status' => 'OK']);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $this->assertSame($baseline['id'], $publications->catalogHead('AAPL')['current_run_id']);
        $this->assertSame(
            $baselinePublication['publication_id'],
            $publications->expiryHead('AAPL', '2026-08-21')['current_publication_id']
        );
        $candidate = $publications->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame('provider_invalid_payload', $candidate['run']['failure_code']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_pagination_cap_does_not_write_partial_legacy_snapshots(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-pagination-cap');
        $page = 0;

        Http::fake(function (Request $request) use (&$page) {
            if (
                str_contains($request->url(), '/v3/snapshot/options/AAPL')
                || str_contains($request->url(), '/calculator-cap-page-')
            ) {
                $page++;

                return Http::response([
                    'results' => [[
                        'ticker' => 'O:AAPL260821C'.str_pad((string) $page, 8, '0', STR_PAD_LEFT),
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 100 + $page,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 1, 'ask' => 1.2],
                    ]],
                    'next_url' => 'https://api.massive.test/calculator-cap-page-'.($page + 1),
                ]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $expectedPages = max(50, (int) env('CALC_CHAIN_MAX_PAGES', 150));
        $this->assertSame($expectedPages, $page);
        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $this->assertNull(app(CalculatorPublicationRepository::class)->catalogHead('AAPL'));
        $candidate = app(CalculatorPublicationRepository::class)->latestRunForSymbol('AAPL');
        $this->assertSame('capped', $candidate['run']['status']);
        $this->assertSame('discovery_capped', $candidate['run']['failure_code']);
    }

    public function test_repeated_pagination_cursor_fails_closed_without_legacy_rows(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-pagination-cycle');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response([
                    'results' => [[
                        'ticker' => 'CYCLE-PARTIAL',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 1, 'ask' => 1.2],
                    ]],
                    'next_url' => 'https://api.massive.test/v3/snapshot/options/AAPL',
                ]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $this->states->get('AAPL')['status']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
        $this->assertNull(app(CalculatorPublicationRepository::class)->catalogHead('AAPL'));
        $candidate = app(CalculatorPublicationRepository::class)->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $candidate['run']['status']);
        $this->assertSame('provider_pagination_cycle', $candidate['run']['failure_code']);
    }

    public function test_older_bulk_candidate_cannot_write_legacy_rows_over_a_newer_expiry_publication(): void
    {
        $publications = app(CalculatorPublicationRepository::class);
        $generation = 'generation-older-bulk';
        $olderAt = $this->now->subMinutes(2);
        $olderRun = $publications->startCatalogRun(
            'AAPL',
            ownerKey: 'scheduler:'.$generation,
            purpose: 'scheduled_catalog',
            at: $olderAt
        );
        $newerAt = $this->now->subMinute();
        $newer = $publications->startSelectedExpiryRun(
            'AAPL',
            '2026-08-21',
            ownerKey: 'test:newer-interactive',
            at: $newerAt
        );
        $newerPublication = $publications->stageAndPublishExpiry(
            (string) $newer['id'],
            '2026-08-21',
            'test',
            $newerAt,
            $newerAt,
            [[
                'ticker' => 'NEWER-INTERACTIVE',
                'type' => 'call',
                'strike' => 150,
                'bid' => 3,
                'ask' => 3.2,
                'mid' => 3.1,
            ]],
            $newerAt
        );
        [, $token] = $this->claim('AAPL', $generation);
        $olderTimestamp = $olderAt->utc()->format('U').'000000000';

        Http::fake(function (Request $request) use ($olderTimestamp) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [[
                    'ticker' => 'OLDER-BULK',
                    'updated' => $olderTimestamp,
                    'details' => [
                        'contract_type' => 'call',
                        'strike_price' => 150,
                        'expiration_date' => '2026-08-21',
                    ],
                    'last_quote' => ['bid' => 1, 'ask' => 1.2],
                ]]]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $this->assertSame('complete', $publications->run((string) $olderRun['id'])['status']);
        $this->assertSame(
            $newerPublication['publication_id'],
            $publications->expiryHead('AAPL', '2026-08-21')['current_publication_id']
        );
        $this->assertSame('NEWER-INTERACTIVE', $publications
            ->publishedExpiry('AAPL', '2026-08-21')['rows'][0]['ticker']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_retry_resumes_the_frozen_run_without_replacing_an_already_ready_expiry(): void
    {
        $generation = 'generation-resume-frozen';
        $publications = app(CalculatorPublicationRepository::class);
        $baselineAt = $this->now->subMinute();
        $run = $publications->startCatalogRun(
            'AAPL',
            ownerKey: 'scheduler:'.$generation,
            purpose: 'scheduled_catalog',
            at: $baselineAt
        );
        $publications->freezeCatalog(
            (string) $run['id'],
            ['2026-08-21', '2026-08-28'],
            'massive-options-snapshot',
            $baselineAt,
            terminalCursorReached: true,
            discoveryHorizon: '2026-08-28',
            at: $baselineAt
        );
        $first = $publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-08-21',
            'massive-options-snapshot',
            $baselineAt,
            $baselineAt,
            [[
                'ticker' => 'READY-FIRST',
                'type' => 'call',
                'strike' => 150,
                'bid' => 2,
                'ask' => 2.2,
                'mid' => 2.1,
            ]],
            $baselineAt
        );
        [, $token] = $this->claim('AAPL', $generation);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/v3/snapshot/options/AAPL')) {
                return Http::response(['results' => [
                    [
                        'ticker' => 'CHANGED-FIRST',
                        'details' => [
                            'contract_type' => 'call',
                            'strike_price' => 150,
                            'expiration_date' => '2026-08-21',
                        ],
                        'last_quote' => ['bid' => 3, 'ask' => 3.2],
                    ],
                    [
                        'ticker' => 'READY-SECOND',
                        'details' => [
                            'contract_type' => 'put',
                            'strike_price' => 151,
                            'expiration_date' => '2026-08-28',
                        ],
                        'last_quote' => ['bid' => 2.5, 'ask' => 2.7],
                    ],
                ]]);
            }

            return Http::response(['results' => []]);
        });

        (new FetchCalculatorChainJob('AAPL', null, $generation, $token))->handle();

        $manifest = $publications->runManifest((string) $run['id']);
        $this->assertSame('complete', $manifest['run']['status']);
        $this->assertSame(2, (int) $manifest['run']['completed_count']);
        $this->assertSame(2, DB::table('calculator_expiry_publications')
            ->where('run_id', $run['id'])->count());
        $stillFirst = $publications->publishedExpiry('AAPL', '2026-08-21');
        $this->assertSame($first['publication_id'], $stillFirst['publication_id']);
        $this->assertSame('READY-FIRST', $stillFirst['rows'][0]['ticker']);
        $second = $publications->publishedExpiry('AAPL', '2026-08-28');
        $this->assertSame('READY-SECOND', $second['rows'][0]['ticker']);
        $this->assertSame(0, DB::table('option_snapshots')->where('symbol', 'AAPL')->count());
    }

    public function test_terminal_exception_marks_only_the_current_claim_failed(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-terminal');
        $job = new FetchCalculatorChainJob('AAPL', null, $generation, $token);

        $job->failed(new RuntimeException('provider failed'));

        $state = $this->states->get('AAPL');
        $this->assertSame(CalculatorRefreshState::STATUS_FAILED, $state['status'] ?? null);
        $this->assertSame('terminal_exception:RuntimeException', $state['failure_reason'] ?? null);
        $run = app(CalculatorPublicationRepository::class)->latestRunForSymbol('AAPL');
        $this->assertSame('failed', $run['run']['status']);
        $this->assertSame('terminal_exception', $run['run']['failure_code']);
    }

    public function test_scheduler_identity_uses_generation_but_not_random_claim_token(): void
    {
        $first = new FetchCalculatorChainJob('AAPL', null, 'generation-a', 'token-a');
        $sameGeneration = new FetchCalculatorChainJob('AAPL', null, 'generation-a', 'token-b');
        $nextGeneration = new FetchCalculatorChainJob('AAPL', null, 'generation-b', 'token-c');

        $this->assertSame($first->idempotencyKey(), $sameGeneration->idempotencyKey());
        $this->assertNotSame($first->idempotencyKey(), $nextGeneration->idempotencyKey());
    }

    public function test_completed_generation_cannot_regress_to_started_after_redelivery(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-completed');
        $this->assertTrue($this->states->markStarted('AAPL', $generation, $token, 1, $this->now));
        $this->assertTrue($this->states->markCompleted('AAPL', $generation, $token, $this->now));

        Cache::put('calculator:refresh-active:v1:catalog:AAPL', [
            'generation' => $generation,
            'claim_token' => $token,
        ], $this->now->addHour());

        $this->assertFalse($this->states->markStarted(
            'AAPL',
            $generation,
            $token,
            2,
            $this->now->addMinute()
        ));
        $this->assertSame(CalculatorRefreshState::STATUS_COMPLETED, $this->states->get('AAPL')['status']);
    }

    public function test_claim_rechecks_freshness_atomically_after_scheduler_bulk_read(): void
    {
        [$generation, $token] = $this->claim('AAPL', 'generation-race-winner');
        $this->states->markStarted('AAPL', $generation, $token, 1, $this->now);
        $this->states->markCompleted('AAPL', $generation, $token, $this->now);

        $losingClaim = $this->states->claim(
            'AAPL',
            'generation-race-loser',
            'calculator',
            $this->now,
            $this->now->subMinutes(10)
        );

        $this->assertNull($losingClaim);
        $this->assertSame('generation-race-winner', $this->states->get('AAPL')['generation']);
    }

    public function test_repeated_failures_back_off_exponentially_and_success_resets_the_count(): void
    {
        [$firstGeneration, $firstToken] = $this->claim('AAPL', 'generation-failure-one');
        $this->states->markStarted('AAPL', $firstGeneration, $firstToken, 1, $this->now);
        $this->states->markFailed('AAPL', $firstGeneration, $firstToken, 'no_contracts', $this->now);

        $secondAt = $this->now->addMinutes(5);
        $secondToken = $this->states->claim('AAPL', 'generation-failure-two', 'calculator', $secondAt);
        $this->assertNotNull($secondToken);
        $this->states->markStarted('AAPL', 'generation-failure-two', $secondToken, 1, $secondAt);
        $this->states->markFailed('AAPL', 'generation-failure-two', $secondToken, 'no_contracts', $secondAt);

        $failed = $this->states->get('AAPL');
        $this->assertSame(2, $failed['failure_count'] ?? null);
        $this->assertSame(
            $secondAt->addMinutes(10)->timestamp,
            CarbonImmutable::parse($failed['next_eligible_at'])->timestamp
        );

        $recoveryAt = $secondAt->addMinutes(10);
        $recoveryToken = $this->states->claim('AAPL', 'generation-recovered', 'calculator', $recoveryAt);
        $this->assertNotNull($recoveryToken);
        $this->states->markStarted('AAPL', 'generation-recovered', $recoveryToken, 1, $recoveryAt);
        $this->states->markCompleted('AAPL', 'generation-recovered', $recoveryToken, $recoveryAt);

        $this->assertSame(0, $this->states->get('AAPL')['failure_count'] ?? null);
    }

    /** @return array{string, string} */
    private function claim(string $symbol, string $generation): array
    {
        $token = $this->states->claim($symbol, $generation, 'calculator', $this->now);
        $this->assertNotNull($token);

        return [$generation, $token];
    }
}
