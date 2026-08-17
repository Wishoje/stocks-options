<?php

namespace Tests\Feature;

use App\Jobs\BootstrapUserSymbolJob;
use App\Jobs\FetchCalculatorChainJob;
use App\Jobs\FetchPolygonIntradayOptionsJob;
use App\Models\User;
use App\Support\CalculatorPublicationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Gex011WorkEndpointContractTest extends TestCase
{
    private const CONNECTION = 'gex011-contract-test';

    private string $originalDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabaseConnection = DB::getDefaultConnection();
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);

        $this->createContractTables();
        $publicationMigration = require database_path(
            'migrations/2026_08_16_000003_create_calculator_publication_tables.php'
        );
        $publicationMigration->up();
        $this->configureEntitledPlan();

        config()->set('queue_lanes.isolated', true);
        config()->set('services.massive.concurrency.enabled', true);
        config()->set('services.massive.concurrency.limit', 4);
        config()->set('work_runs.rate_limits.user_per_minute', 100);
        config()->set('work_runs.rate_limits.ip_per_minute', 100);
        config()->set('work_runs.rate_limits.accepted_symbol_per_minute', 100);
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 100);
        config()->set('work_runs.rate_limits.status_per_minute', 100);

        Cache::flush();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_anonymous_callers_cannot_reach_market_data_or_work_endpoints(
        string $method,
        string $uri,
        array $payload
    ): void {
        $this->callJsonEndpoint($method, $uri, $payload)
            ->assertUnauthorized();

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    #[DataProvider('protectedEndpointProvider')]
    public function test_authenticated_users_without_an_entitlement_are_forbidden(
        string $method,
        string $uri,
        array $payload
    ): void {
        Sanctum::actingAs($this->createUser());

        $this->callJsonEndpoint($method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    #[DataProvider('featureProtectedEndpointProvider')]
    public function test_subscribers_without_the_endpoint_feature_are_forbidden(
        string $method,
        string $uri,
        array $payload
    ): void {
        Sanctum::actingAs($this->createSubscribedUser('price_gex011_limited'));

        $this->callJsonEndpoint($method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_available');

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    #[DataProvider('featureProtectedEndpointProvider')]
    public function test_subscribers_with_an_unmapped_plan_fail_closed_on_market_data_endpoints(
        string $method,
        string $uri,
        array $payload
    ): void {
        Sanctum::actingAs($this->createSubscribedUser('price_gex011_unknown'));

        $this->callJsonEndpoint($method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_unmapped');

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    public function test_anonymous_callers_cannot_read_a_durable_work_run(): void
    {
        $runId = $this->createStatusRun();

        $this->getJson('/api/work-runs/'.$runId)
            ->assertUnauthorized();

        Bus::assertNothingDispatched();
        $this->assertSame(1, DB::table('work_runs')->count());
    }

    public function test_authenticated_users_without_a_subscription_cannot_read_a_durable_work_run(): void
    {
        $runId = $this->createStatusRun();
        Sanctum::actingAs($this->createUser());

        $this->getJson('/api/work-runs/'.$runId)
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');

        Bus::assertNothingDispatched();
        $this->assertSame(1, DB::table('work_runs')->count());
    }

    public function test_subscriber_without_the_run_feature_cannot_read_calculator_status(): void
    {
        $runId = $this->createStatusRun();
        Sanctum::actingAs($this->createSubscribedUser('price_gex011_limited'));

        $this->getJson('/api/work-runs/'.$runId)
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_available');

        Bus::assertNothingDispatched();
        $this->assertSame(1, DB::table('work_runs')->count());
    }

    #[DataProvider('workRunKindProvider')]
    public function test_subscribers_with_an_unmapped_plan_cannot_read_work_run_status(string $kind): void
    {
        $runId = $this->createStatusRun($kind);
        Sanctum::actingAs($this->createSubscribedUser('price_gex011_unknown'));

        $this->getJson('/api/work-runs/'.$runId)
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_unmapped');

        Bus::assertNothingDispatched();
        $this->assertSame(1, DB::table('work_runs')->count());
    }

    #[DataProvider('internalDiagnosticEndpointProvider')]
    public function test_internal_health_and_debug_endpoints_reject_anonymous_callers(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    #[DataProvider('internalDiagnosticEndpointProvider')]
    public function test_internal_health_and_debug_endpoints_reject_ordinary_authenticated_users(string $uri): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->getJson($uri)->assertForbidden();

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    public function test_entitled_market_data_gets_are_pure_reads(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $runsBefore = DB::table('work_runs')->count();

        $this->getJson('/api/option-chain?symbol=COLD')
            ->assertStatus(202)
            ->assertJsonPath('underlying.symbol', 'COLD')
            ->assertJsonPath('chain', [])
            ->assertJsonPath('refresh_queued', false);

        $this->getJson('/api/symbol/status?symbol=COLD&timeframe=14d')
            ->assertNotFound()
            ->assertJsonPath('symbol', 'COLD')
            ->assertJsonPath('status', 'missing')
            ->assertJsonPath('run', null);

        $this->getJson('/api/gex-levels?symbol=COLD&timeframe=14d')
            ->assertNotFound()
            ->assertJsonPath('status', 'missing')
            ->assertJsonPath('run', null)
            ->assertJsonPath('available_timeframes', []);

        Bus::assertNothingDispatched();
        $this->assertSame($runsBefore, DB::table('work_runs')->count());
        $this->assertSame(0, DB::table('work_run_slots')->count());
    }

    public function test_calculator_start_rate_limit_returns_a_retryable_contract_without_creating_more_work(): void
    {
        config()->set('work_runs.rate_limits.user_per_minute', 1);
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/prime-calculator', ['symbol' => 'AAPL'])
            ->assertAccepted();

        $limited = $this->postJson('/api/prime-calculator', ['symbol' => 'MSFT']);

        $limited->assertTooManyRequests()
            ->assertJsonPath('code', 'work_rate_limited');
        $this->assertGreaterThanOrEqual(1, (int) $limited->json('retry_after_seconds'));
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
    }

    public function test_intraday_pull_creates_one_async_durable_run_per_canonical_symbol_and_coalesces_duplicates(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $first = $this->postJson('/api/intraday/pull', [
            'symbols' => [' aapl ', 'AAPL', ' spy ', 'SPY'],
            'force' => true,
            'sync' => true,
        ]);
        $first->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dispatch', 'async')
            ->assertJsonPath('queued_symbols', ['AAPL', 'SPY'])
            ->assertJsonPath('newly_queued_symbols', ['AAPL', 'SPY'])
            ->assertJsonPath('coalesced_symbols', []);

        $firstRuns = collect($first->json('runs'))->keyBy('symbol');
        $this->assertCount(2, $firstRuns);
        $this->assertSame('intraday-interactive', $firstRuns->get('AAPL')['queue'] ?? null);
        $this->assertSame('intraday-heavy', $firstRuns->get('SPY')['queue'] ?? null);
        $this->assertTrue((bool) ($firstRuns->get('AAPL')['queued'] ?? false));
        $this->assertTrue((bool) ($firstRuns->get('SPY')['queued'] ?? false));

        $runIds = $firstRuns->map(fn (array $run): string => (string) $run['run_id']);
        $this->assertSame(2, DB::table('work_runs')->where('kind', 'intraday_refresh')->count());
        $this->assertSame(
            ['AAPL', 'SPY'],
            DB::table('work_runs')
                ->where('kind', 'intraday_refresh')
                ->orderBy('symbol')
                ->pluck('symbol')
                ->all()
        );
        $this->assertSame(2, DB::table('work_run_slots')->count());

        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
        Bus::assertNotDispatchedSync(FetchPolygonIntradayOptionsJob::class);
        Bus::assertDispatched(
            FetchPolygonIntradayOptionsJob::class,
            fn (FetchPolygonIntradayOptionsJob $job): bool => $job->symbols === ['AAPL']
                && $job->queue === 'intraday-interactive'
                && $job->workRunId === $runIds->get('AAPL')
                && $job->workRunDeliveryToken !== null
        );
        Bus::assertDispatched(
            FetchPolygonIntradayOptionsJob::class,
            fn (FetchPolygonIntradayOptionsJob $job): bool => $job->symbols === ['SPY']
                && $job->queue === 'intraday-heavy'
                && $job->workRunId === $runIds->get('SPY')
                && $job->workRunDeliveryToken !== null
        );

        Cache::flush();

        $second = $this->postJson('/api/intraday/pull', [
            'symbols' => ['AAPL', 'aapl', 'SPY', ' spy '],
            'force' => true,
            'sync' => true,
        ]);

        $second->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dispatch', 'async')
            ->assertJsonPath('newly_queued_symbols', [])
            ->assertJsonPath('coalesced_symbols', ['AAPL', 'SPY']);

        $secondRunIds = collect($second->json('runs'))
            ->keyBy('symbol')
            ->map(fn (array $run): string => (string) $run['run_id']);
        $this->assertSame($runIds->all(), $secondRunIds->all());
        $this->assertSame(2, DB::table('work_runs')->count());
        $this->assertSame(2, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
        Bus::assertNotDispatchedSync(FetchPolygonIntradayOptionsJob::class);
    }

    public function test_intraday_force_replaces_only_a_completed_reusable_run(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $first = $this->postJson('/api/intraday/pull', [
            'symbols' => ['AAPL'],
            'force' => true,
        ])->assertAccepted();
        $firstRunId = (string) $first->json('runs.0.run_id');

        DB::table('work_runs')->where('id', $firstRunId)->update([
            'status' => 'completed',
            'completed_at' => now('UTC'),
            'reusable_until' => now('UTC')->addMinutes(10),
            'lease_expires_at' => null,
        ]);

        $this->postJson('/api/intraday/pull', ['symbols' => ['AAPL']])
            ->assertOk()
            ->assertJsonPath('runs.0.run_id', $firstRunId)
            ->assertJsonPath('runs.0.coalesced', true);

        $forced = $this->postJson('/api/intraday/pull', [
            'symbols' => ['AAPL'],
            'force' => true,
        ])->assertAccepted();

        $this->assertNotSame($firstRunId, (string) $forced->json('runs.0.run_id'));
        $forced->assertJsonPath('runs.0.generation', 2)
            ->assertJsonPath('runs.0.coalesced', false);
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
    }

    public function test_intraday_pull_accepts_the_exact_configured_symbol_cap(): void
    {
        config()->set('work_runs.max_symbols_per_request', 2);
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/intraday/pull', [
            'symbols' => [' aapl ', ' spy '],
            'force' => true,
        ])->assertAccepted()
            ->assertJsonPath('queued_symbols', ['AAPL', 'SPY'])
            ->assertJsonCount(2, 'runs');

        $this->assertSame(2, DB::table('work_runs')->count());
        $this->assertSame(2, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
    }

    public function test_intraday_pull_rejects_cap_plus_one_without_partial_work(): void
    {
        config()->set('work_runs.max_symbols_per_request', 2);
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/intraday/pull', [
            'symbols' => ['AAPL', 'MSFT', 'SPY'],
            'force' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'too_many_symbols')
            ->assertJsonPath('max_symbols', 2);

        $this->assertSame(0, DB::table('work_runs')->count());
        $this->assertSame(0, DB::table('work_run_slots')->count());
        Bus::assertNothingDispatched();
    }

    public function test_intraday_pull_rejects_non_string_and_storage_oversized_symbols_without_work(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/intraday/pull', [
            'symbols' => [['SPY']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['symbols.0']);
        $this->postJson('/api/intraday/pull', [
            'symbols' => [str_repeat('A', 11)],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['symbols.0']);

        $this->assertSame(0, DB::table('work_runs')->count());
        $this->assertSame(0, DB::table('work_run_slots')->count());
        Bus::assertNothingDispatched();
    }

    public function test_symbol_prime_rejects_symbols_longer_than_option_expiration_storage_without_work(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/prime', ['symbol' => str_repeat('A', 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('symbol');

        $this->assertSame(0, DB::table('work_runs')->count());
        $this->assertSame(0, DB::table('work_run_slots')->count());
        Bus::assertNothingDispatched();
    }

    public function test_invalid_symbol_formats_are_rejected_without_persisting_or_dispatching_work(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/prime-calculator', ['symbol' => '!!!'])
            ->assertUnprocessable();
        $this->postJson('/api/prime', ['symbol' => '../'])
            ->assertUnprocessable();
        $this->postJson('/api/intraday/pull', ['symbols' => ['AAPL', 'QQQ?all']])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_symbol');
        $this->postJson('/api/watchlist', ['symbol' => 'SPY/../QQQ'])
            ->assertUnprocessable();
        $this->getJson('/api/option-chain?symbol=%21%21%21')
            ->assertUnprocessable();
        $this->getJson('/api/symbol/status?symbol=..')
            ->assertUnprocessable();
        $this->getJson('/api/gex-levels?symbol=%2F%2F')
            ->assertUnprocessable();

        $this->assertSame(0, DB::table('watchlists')->count());
        $this->assertSame(0, DB::table('work_runs')->count());
        $this->assertSame(0, DB::table('work_run_slots')->count());
        Bus::assertNothingDispatched();
    }

    public function test_supported_dotted_symbol_format_remains_valid(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/prime', ['symbol' => ' brk.a '])
            ->assertAccepted()
            ->assertJsonPath('symbol', 'BRK.A');

        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
    }

    public function test_watchlist_rejects_symbols_longer_than_its_database_column_without_work(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/watchlist', ['symbol' => 'ABCDEFGHIJK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('symbol');

        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('watchlists')->count());
        $this->assertSame(0, DB::table('work_runs')->count());
    }

    public function test_intraday_pull_applies_the_cap_after_canonical_dedup_without_truncating_unique_symbols(): void
    {
        config()->set('work_runs.max_symbols_per_request', 2);
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/intraday/pull', [
            'symbols' => ['AAPL', ' aapl ', 'MSFT'],
            'force' => true,
        ])->assertAccepted()
            ->assertJsonPath('queued_symbols', ['AAPL', 'MSFT'])
            ->assertJsonCount(2, 'runs');

        $this->assertSame(
            ['AAPL', 'MSFT'],
            DB::table('work_runs')->orderBy('symbol')->pluck('symbol')->all()
        );
        $this->assertSame(2, DB::table('work_runs')->count());
        $this->assertSame(2, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(FetchPolygonIntradayOptionsJob::class, 2);
    }

    public function test_explicit_symbol_prime_is_durable_async_and_coalesces(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $first = $this->postJson('/api/prime', ['symbol' => ' msft ']);

        $first->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('kind', 'symbol_bootstrap')
            ->assertJsonPath('symbol', 'MSFT')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('queue', 'bootstrap-fast')
            ->assertJsonPath('queued', true)
            ->assertJsonPath('coalesced', false);

        $runId = (string) $first->json('run_id');
        $this->assertNotSame('', $runId);
        $this->assertDatabaseHas('work_runs', [
            'id' => $runId,
            'kind' => 'symbol_bootstrap',
            'symbol' => 'MSFT',
            'status' => 'pending',
            'queue' => 'bootstrap-fast',
        ]);
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertNotDispatchedSync(BootstrapUserSymbolJob::class);
        Bus::assertDispatched(
            BootstrapUserSymbolJob::class,
            fn (BootstrapUserSymbolJob $job): bool => $job->symbol === 'MSFT'
                && $job->queue === 'bootstrap-fast'
                && $job->workRunId === $runId
                && $job->workRunDeliveryToken !== null
        );

        Cache::flush();

        $this->postJson('/api/prime', ['symbol' => 'MSFT'])
            ->assertOk()
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('queued', false)
            ->assertJsonPath('coalesced', true);

        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertNotDispatchedSync(BootstrapUserSymbolJob::class);
    }

    public function test_watchlist_add_preserves_its_exact_response_and_shares_the_durable_bootstrap_run(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $watchlist = $this->postJson('/api/watchlist', ['symbol' => ' msft ']);
        $watchlist->assertCreated()
            ->assertExactJson([
                'id' => 1,
                'symbol' => 'MSFT',
            ]);

        $run = DB::table('work_runs')
            ->where('kind', 'symbol_bootstrap')
            ->where('symbol', 'MSFT')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertNotDispatched(FetchPolygonIntradayOptionsJob::class);

        Cache::flush();

        $this->postJson('/api/prime', ['symbol' => 'MSFT'])
            ->assertOk()
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('queued', false)
            ->assertJsonPath('coalesced', true);

        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertNotDispatchedSync(BootstrapUserSymbolJob::class);
    }

    public function test_provider_limited_watchlist_add_preserves_response_and_reconciles_the_same_deferred_run(): void
    {
        config()->set('work_runs.rate_limits.accepted_provider_per_minute', 1);
        $at = now('UTC')->startOfMinute();
        $this->travelTo($at);
        Sanctum::actingAs($this->createEntitledUser());

        $this->postJson('/api/prime-calculator', ['symbol' => 'AAPL'])->assertAccepted();
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);

        $this->postJson('/api/watchlist', ['symbol' => 'MSFT'])
            ->assertCreated()
            ->assertExactJson([
                'id' => 1,
                'symbol' => 'MSFT',
            ]);

        $run = DB::table('work_runs')
            ->where('kind', 'symbol_bootstrap')
            ->where('symbol', 'MSFT')
            ->first();
        $this->assertNotNull($run);
        $this->assertSame('pending', $run->status);
        $this->assertSame('admission_deferred', $run->error_category);
        $this->assertNotNull($run->next_dispatch_at);
        Bus::assertNotDispatched(BootstrapUserSymbolJob::class);

        $this->travelTo(\Carbon\CarbonImmutable::parse($run->next_dispatch_at, 'UTC')->addSecond());
        $this->assertSame(0, Artisan::call('work-runs:reconcile', ['--limit' => 100]));

        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertDispatched(
            BootstrapUserSymbolJob::class,
            fn (BootstrapUserSymbolJob $job): bool => $job->workRunId === $run->id
                && $job->workRunDeliveryToken !== null
        );
        $this->assertSame($run->id, DB::table('work_runs')
            ->where('kind', 'symbol_bootstrap')
            ->where('symbol', 'MSFT')
            ->value('id'));
    }

    #[DataProvider('calculatorSymbolProvider')]
    public function test_entitled_calculator_refresh_is_durable_and_coalesces_identical_requests(
        string $submittedSymbol,
        string $canonicalSymbol,
        string $expectedQueue
    ): void {
        $user = $this->createEntitledUser();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/prime-calculator', [
            'symbol' => $submittedSymbol,
            'sync' => true,
        ]);

        $first->assertAccepted()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('symbol', $canonicalSymbol)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('kind', 'calculator_refresh')
            ->assertJsonPath('queue', $expectedQueue)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('coalesced', false)
            ->assertJsonPath('mode', 'queue')
            ->assertJsonPath('sync_disabled', true);

        $runId = (string) $first->json('run_id');
        $statusUrl = (string) $first->json('status_url');

        $this->assertNotSame('', $runId);
        $this->assertNotSame('', $statusUrl);
        $this->assertDatabaseHas('work_runs', [
            'id' => $runId,
            'requested_by_user_id' => $user->id,
            'kind' => 'calculator_refresh',
            'symbol' => $canonicalSymbol,
            'status' => 'pending',
            'queue' => $expectedQueue,
        ]);
        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());

        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
        Bus::assertNotDispatchedSync(FetchCalculatorChainJob::class);
        Bus::assertDispatched(
            FetchCalculatorChainJob::class,
            fn (FetchCalculatorChainJob $job): bool => $job->symbol === $canonicalSymbol
                && $job->queue === $expectedQueue
                && property_exists($job, 'workRunId')
                && $job->workRunId === $runId
        );

        // Coalescing must come from the durable slot/run state, not a cache lock.
        Cache::flush();

        $second = $this->postJson('/api/prime-calculator', [
            'symbol' => $canonicalSymbol,
            'force' => true,
        ]);

        $second->assertOk()
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('queued', false)
            ->assertJsonPath('coalesced', true);

        $this->assertSame(1, DB::table('work_runs')->count());
        $this->assertSame(1, DB::table('work_run_slots')->count());
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
        Bus::assertNotDispatchedSync(FetchCalculatorChainJob::class);

        Cache::flush();

        $statusPath = (string) parse_url($statusUrl, PHP_URL_PATH);
        $this->getJson($statusPath)
            ->assertOk()
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('run_id', $runId)
            ->assertJsonPath('kind', 'calculator_refresh')
            ->assertJsonPath('symbol', $canonicalSymbol)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('queue', $expectedQueue)
            ->assertJsonPath('terminal', false)
            ->assertJsonPath('retry_after_seconds', 2);

        $this->assertSame(1, DB::table('work_runs')->count());
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);
    }

    public function test_work_run_status_embeds_a_lightweight_calculator_manifest_without_chain_rows(): void
    {
        Sanctum::actingAs($this->createEntitledUser());
        $start = $this->postJson('/api/prime-calculator', ['symbol' => 'AAPL'])
            ->assertAccepted();
        $workRunId = (string) $start->json('run_id');
        $at = CarbonImmutable::parse('2026-08-16 14:00:00', 'UTC');
        $publications = app(CalculatorPublicationRepository::class);
        $run = $publications->startCatalogRun(
            'AAPL',
            workRunId: $workRunId,
            at: $at
        );
        $publications->freezeCatalog(
            (string) $run['id'],
            ['2026-09-18'],
            'test',
            $at,
            terminalCursorReached: true,
            at: $at
        );
        $publications->stageAndPublishExpiry(
            (string) $run['id'],
            '2026-09-18',
            'test',
            $at,
            $at,
            [[
                'ticker' => 'O:AAPL260918C00150000',
                'type' => 'call',
                'strike' => 150,
                'bid' => 2,
                'ask' => 2.2,
                'mid' => 2.1,
                'implied_volatility' => 0.2,
            ]],
            $at
        );
        $publications->completeCatalog((string) $run['id'], $at);

        $this->getJson('/api/option-chain?symbol=AAPL')
            ->assertOk()
            ->assertJsonPath('run_id', $run['id'])
            ->assertJsonPath('run.scope', 'catalog')
            ->assertJsonPath('work_run.run_id', $workRunId)
            ->assertJsonPath('work_run.status_url', $start->json('status_url'))
            ->assertJsonPath('work_run.retry_after_seconds', 2);

        $this->getJson((string) parse_url((string) $start->json('status_url'), PHP_URL_PATH))
            ->assertOk()
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('calculator.run_id', $run['id'])
            ->assertJsonPath('calculator.status', 'complete')
            ->assertJsonPath('calculator.expected_count', 1)
            ->assertJsonPath('calculator.completed_count', 1)
            ->assertJsonPath('calculator.failed_count', 0)
            ->assertJsonPath('calculator.expirations.0.expiration', '2026-09-18')
            ->assertJsonPath('calculator.expirations.0.readiness', 'ready')
            ->assertJsonMissingPath('calculator.expirations.0.rows');
    }

    public function test_calculator_force_replaces_only_a_completed_reusable_run(): void
    {
        Sanctum::actingAs($this->createEntitledUser());

        $first = $this->postJson('/api/prime-calculator', ['symbol' => 'AAPL']);
        $first->assertAccepted();
        $firstRunId = (string) $first->json('run_id');
        DB::table('work_runs')->where('id', $firstRunId)->update([
            'status' => 'completed',
            'completed_at' => now('UTC'),
            'reusable_until' => now('UTC')->addMinutes(10),
            'lease_expires_at' => null,
        ]);

        $this->postJson('/api/prime-calculator', ['symbol' => 'AAPL'])
            ->assertOk()
            ->assertJsonPath('run_id', $firstRunId)
            ->assertJsonPath('coalesced', true);
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 1);

        $forced = $this->postJson('/api/prime-calculator', [
            'symbol' => 'AAPL',
            'force' => true,
        ]);
        $forced->assertAccepted()
            ->assertJsonPath('force', true)
            ->assertJsonPath('generation', 2)
            ->assertJsonPath('coalesced', false);
        $forcedRunId = (string) $forced->json('run_id');
        $this->assertNotSame($firstRunId, $forcedRunId);
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 2);

        DB::table('work_runs')->where('id', $forcedRunId)->update([
            'status' => 'failed',
            'failed_at' => now('UTC'),
            'retry_not_before' => now('UTC')->addMinutes(5),
            'lease_expires_at' => null,
        ]);
        $this->postJson('/api/prime-calculator', [
            'symbol' => 'AAPL',
            'force' => true,
        ])->assertOk()
            ->assertJsonPath('run_id', $forcedRunId)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('coalesced', true);
        Bus::assertDispatchedTimes(FetchCalculatorChainJob::class, 2);
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function protectedEndpointProvider(): array
    {
        return [
            'option-chain read' => ['GET', '/api/option-chain?symbol=COLD', []],
            'symbol status read' => ['GET', '/api/symbol/status?symbol=COLD&timeframe=14d', []],
            'GEX levels read' => ['GET', '/api/gex-levels?symbol=COLD&timeframe=14d', []],
            'calculator refresh write' => ['POST', '/api/prime-calculator', ['symbol' => 'AAPL']],
            'intraday refresh write' => ['POST', '/api/intraday/pull', ['symbols' => ['AAPL']]],
            'symbol bootstrap write' => ['POST', '/api/prime', ['symbol' => 'AAPL']],
        ];
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function featureProtectedEndpointProvider(): array
    {
        return [
            'option-chain read' => ['GET', '/api/option-chain?symbol=COLD', []],
            'symbol status read' => ['GET', '/api/symbol/status?symbol=COLD&timeframe=14d', []],
            'GEX levels read' => ['GET', '/api/gex-levels?symbol=COLD&timeframe=14d', []],
            'calculator refresh write' => ['POST', '/api/prime-calculator', ['symbol' => 'AAPL']],
            'intraday refresh write' => ['POST', '/api/intraday/pull', ['symbols' => ['AAPL']]],
            'symbol bootstrap write' => ['POST', '/api/prime', ['symbol' => 'AAPL']],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function calculatorSymbolProvider(): array
    {
        return [
            'normal symbol' => ['aapl', 'AAPL', 'calculator-fill'],
            'heavy symbol' => ['spy', 'SPY', 'calculator-fill-heavy'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function internalDiagnosticEndpointProvider(): array
    {
        return [
            'ingest health' => ['/api/health/ingest'],
            'IV skew debug' => ['/api/iv/skew/debug?symbol=SPY'],
            'unusual activity debug' => ['/api/ua/debug?symbol=SPY'],
            'market debug' => ['/api/debug/market'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function workRunKindProvider(): array
    {
        return [
            'calculator refresh' => ['calculator_refresh'],
            'intraday refresh' => ['intraday_refresh'],
            'symbol bootstrap' => ['symbol_bootstrap'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function callJsonEndpoint(string $method, string $uri, array $payload): TestResponse
    {
        return $method === 'GET'
            ? $this->getJson($uri)
            : $this->postJson($uri, $payload);
    }

    private function createUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'GEX-011 test user',
            'email' => 'gex011-'.uniqid('', true).'@example.test',
            'password' => 'unused-test-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createEntitledUser(): User
    {
        return $this->createSubscribedUser('price_gex011_entitled');
    }

    private function createSubscribedUser(string $price): User
    {
        $user = $this->createUser();
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid('', true),
            'stripe_status' => 'active',
            'stripe_price' => $price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId,
            'stripe_id' => 'si_'.uniqid('', true),
            'stripe_product' => 'prod_gex011',
            'stripe_price' => $price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function createStatusRun(string $kind = 'calculator_refresh'): string
    {
        $id = '00000000-0000-0000-0000-000000000011';
        $queue = match ($kind) {
            'intraday_refresh' => 'intraday-interactive',
            'symbol_bootstrap' => 'bootstrap-fast',
            default => 'calculator-fill',
        };
        DB::table('work_runs')->insert([
            'id' => $id,
            'requested_by_user_id' => null,
            'slot_key' => str_repeat('1', 64),
            'generation' => 1,
            'kind' => $kind,
            'provider' => 'massive',
            'symbol' => 'AAPL',
            'scope_hash' => str_repeat('2', 64),
            'status' => 'pending',
            'queue_connection' => 'redis',
            'queue' => $queue,
            'parameters' => json_encode(['expiry' => null], JSON_THROW_ON_ERROR),
            'requested_at' => now(),
            'next_dispatch_at' => now(),
            'lease_expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function configureEntitledPlan(): void
    {
        config()->set('plans.default_subscription_name', 'default');
        config()->set('plans.plans', [
            'gex011-test' => [
                'prices' => ['monthly' => 'price_gex011_entitled'],
                'features' => [
                    'app.access',
                    'scanner.access',
                    'calculator.access',
                    'intraday.access',
                ],
            ],
            'gex011-limited' => [
                'prices' => ['monthly' => 'price_gex011_limited'],
                'features' => ['scanner.access'],
            ],
        ]);
    }

    private function createContractTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);

        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamp('trial_ends_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        $schema->create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        $schema->create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->timestamps();
        });
        $schema->create('watchlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol', 32);
            $table->string('timeframe')->default('14d');
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });
        $schema->create('work_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->char('slot_key', 64);
            $table->unsignedBigInteger('generation');
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->char('scope_hash', 64);
            $table->string('status', 24);
            $table->string('queue_connection', 32)->default('redis');
            $table->string('queue', 64)->nullable();
            $table->json('parameters')->nullable();
            $table->uuid('delivery_token')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('requested_at');
            $table->timestamp('dispatching_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('reusable_until')->nullable();
            $table->timestamp('retry_not_before')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_category', 64)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->timestamps();
            $table->unique(['slot_key', 'generation']);
        });
        $schema->create('work_run_slots', function (Blueprint $table): void {
            $table->char('key', 64)->primary();
            $table->string('kind', 48);
            $table->string('provider', 32)->nullable();
            $table->string('symbol', 32)->nullable();
            $table->json('parameters')->nullable();
            $table->unsignedBigInteger('generation')->default(0);
            $table->uuid('current_run_id')->nullable();
            $table->timestamps();
        });
        $schema->create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
            $table->date('expiration_date');
        });
        $schema->create('option_chain_data', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expiration_id');
            $table->string('option_type');
            $table->decimal('strike', 12, 2);
            $table->decimal('gamma', 18, 10)->nullable();
            $table->unsignedBigInteger('open_interest')->default(0);
            $table->unsignedBigInteger('volume')->default(0);
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->date('data_date');
            $table->timestamp('data_timestamp')->nullable();
        });
        $schema->create('option_live_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32);
            $table->date('trade_date');
            $table->string('exp_date', 10)->nullable();
            $table->decimal('strike', 12, 4)->nullable();
            $table->string('option_type')->nullable();
            $table->bigInteger('volume')->default(0);
            $table->decimal('premium_usd', 18, 4)->nullable();
            $table->timestamp('asof')->nullable();
            $table->timestamps();
        });
        $schema->create('option_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
            $table->string('ticker')->nullable();
            $table->string('type')->nullable();
            $table->decimal('strike', 12, 2)->nullable();
            $table->date('expiry');
            $table->decimal('bid', 12, 4)->nullable();
            $table->decimal('ask', 12, 4)->nullable();
            $table->decimal('mid', 12, 4)->nullable();
            $table->decimal('implied_volatility', 12, 8)->nullable();
            $table->decimal('underlying_price', 12, 4)->nullable();
            $table->timestamp('fetched_at');
        });
        $schema->create('underlying_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32)->unique();
            $table->string('source')->nullable();
            $table->decimal('last_price', 14, 6);
            $table->decimal('prev_close', 14, 6)->nullable();
            $table->timestamp('asof');
            $table->timestamps();
        });
    }
}
