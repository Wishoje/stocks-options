<?php

namespace Tests\Feature;

use App\Console\Commands\BuildDailyChainSnapshot;
use App\Http\Controllers\GexController;
use App\Jobs\ComputeVolMetricsJob;
use App\Jobs\PricesDailyJob;
use App\Jobs\PrimeSymbolJob;
use App\Jobs\PublishEodCacheVersionJob;
use App\Jobs\QueueJob;
use App\Support\EodCacheVersion;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class EodCacheVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_domain_publication_is_targeted_and_preserves_values_and_locks(): void
    {
        $versions = app(EodCacheVersion::class);
        $oldGexKey = $versions->key(
            'gex:levels:v4',
            EodCacheVersion::DOMAIN_GEX,
            'SPY',
            '14d'
        );
        Cache::put($oldGexKey, ['generation' => 'last-good'], 3600);
        Cache::put('unrelated:value', 'keep-me', 3600);
        Cache::put('bootstrap:idempotency:SPY', 'claimed', 3600);
        $schedulerLock = Cache::lock('scheduler:watchlist-preload', 60);
        $this->assertTrue($schedulerLock->get());

        $versions->publish(
            ['spy'],
            [EodCacheVersion::DOMAIN_VOLATILITY],
            'vol-v1',
            100
        );

        $this->assertSame('vol-v1', $versions->current(EodCacheVersion::DOMAIN_VOLATILITY, 'SPY'));
        $this->assertSame('initial', $versions->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));
        $this->assertSame('initial', $versions->current(EodCacheVersion::DOMAIN_VOLATILITY, 'QQQ'));
        $this->assertSame(['generation' => 'last-good'], Cache::get($oldGexKey));
        $this->assertSame('keep-me', Cache::get('unrelated:value'));
        $this->assertSame('claimed', Cache::get('bootstrap:idempotency:SPY'));
        $this->assertFalse(Cache::lock('scheduler:watchlist-preload', 60)->get());

        $schedulerLock->release();
    }

    public function test_daily_snapshot_publication_includes_every_domain_written_by_its_derived_jobs(): void
    {
        $this->assertSame([
            EodCacheVersion::DOMAIN_GEX,
            EodCacheVersion::DOMAIN_EXPIRY_PRESSURE,
            EodCacheVersion::DOMAIN_ACTIVITY,
        ], BuildDailyChainSnapshot::CACHE_PUBLICATION_DOMAINS);
    }

    public function test_publication_retry_is_idempotent_and_older_finalizer_cannot_roll_back(): void
    {
        $versions = app(EodCacheVersion::class);
        $older = new PublishEodCacheVersionJob(
            ['SPY'],
            [EodCacheVersion::DOMAIN_GEX],
            'older-token',
            100
        );
        $newer = new PublishEodCacheVersionJob(
            ['SPY'],
            [EodCacheVersion::DOMAIN_GEX],
            'newer-token',
            200
        );

        $newer->handle($versions);
        $newer->handle($versions);
        $older->handle($versions);

        $this->assertSame('newer-token', $versions->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));
        $this->assertSame('newer-token', Cache::get(
            $versions->publicationKey(EodCacheVersion::DOMAIN_GEX, 'SPY')
        )['version']);
    }

    public function test_bulk_version_reads_use_one_cache_many_call_and_default_malformed_values(): void
    {
        $versions = app(EodCacheVersion::class);
        $aapl = $versions->publicationKey(EodCacheVersion::DOMAIN_EXPIRY_PRESSURE, 'AAPL');
        $qqq = $versions->publicationKey(EodCacheVersion::DOMAIN_EXPIRY_PRESSURE, 'QQQ');
        $spy = $versions->publicationKey(EodCacheVersion::DOMAIN_EXPIRY_PRESSURE, 'SPY');

        Cache::shouldReceive('many')
            ->once()
            ->with([$aapl, $qqq, $spy])
            ->andReturn([
                $aapl => ['version' => 'aapl-v1'],
                $qqq => ['version' => ''],
                $spy => null,
            ]);

        $this->assertSame([
            'AAPL' => 'aapl-v1',
            'QQQ' => 'initial',
            'SPY' => 'initial',
        ], $versions->currentMany(
            EodCacheVersion::DOMAIN_EXPIRY_PRESSURE,
            ['SPY', 'aapl', 'QQQ', 'SPY']
        ));
    }

    public function test_failed_chain_never_reaches_publication_fence(): void
    {
        $versions = app(EodCacheVersion::class);
        $versions->publish(
            ['SPY'],
            [EodCacheVersion::DOMAIN_GEX],
            'last-good',
            100
        );

        try {
            Bus::chain([
                new FailingEodCacheStep,
                new PublishEodCacheVersionJob(
                    ['SPY'],
                    [EodCacheVersion::DOMAIN_GEX],
                    'must-not-publish',
                    200
                ),
            ])->dispatch();
            $this->fail('The failing EOD step should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('expected failure', $exception->getMessage());
        }

        $this->assertSame('last-good', $versions->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));
    }

    public function test_watchlist_preload_preserves_cache_and_places_publication_last(): void
    {
        $this->ensureWatchlistsTable();
        DB::table('watchlists')->delete();
        DB::table('watchlists')->insert([
            'user_id' => 1,
            'symbol' => 'SPY',
            'timeframe' => '14d',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::put('unrelated:value', 'keep-me', 3600);
        Cache::put('bootstrap:idempotency:SPY', 'claimed', 3600);
        $lock = Cache::lock('scheduler:preload', 60);
        $this->assertTrue($lock->get());
        Bus::fake();

        $this->artisan('watchlist:preload')->assertSuccessful();

        $this->assertSame('keep-me', Cache::get('unrelated:value'));
        $this->assertSame('claimed', Cache::get('bootstrap:idempotency:SPY'));
        $this->assertFalse(Cache::lock('scheduler:preload', 60)->get());
        $this->assertSame(
            'initial',
            app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, 'SPY')
        );
        Bus::assertBatched(function (PendingBatch $batch): bool {
            $head = $batch->jobs->first();
            $chain = collect($head->chained)->map(static fn (string $serialized): object => unserialize($serialized));

            return $batch->name === 'Watchlist EOD Preload'
                && $chain->last() instanceof PublishEodCacheVersionJob;
        });

        $lock->release();
    }

    public function test_force_refresh_cannot_replace_existing_published_payload(): void
    {
        $this->ensureGexTables();
        DB::table('option_expirations')->insert([
            'id' => 1,
            'symbol' => 'SPY',
            'expiration_date' => '2026-08-21',
        ]);
        DB::table('option_chain_data')->insert([
            'expiration_id' => 1,
            'data_date' => '2026-08-14',
            'gamma' => 0.1,
            'data_timestamp' => '2026-08-14 20:00:00',
        ]);

        $controller = new StubGexController;
        $controller->payload = ['generation' => 'last-good', 'strike_data' => [], 'data_date' => '2026-08-14'];
        $first = $controller->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY',
            'timeframe' => '14d',
            'refresh' => 1,
        ]));
        $this->assertSame('last-good', json_decode($first->getContent(), true)['generation']);

        $controller->payload = ['generation' => 'in-progress', 'strike_data' => [], 'data_date' => '2026-08-14'];
        $forced = $controller->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY',
            'timeframe' => '14d',
            'refresh' => 1,
        ]));
        $normal = $controller->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY',
            'timeframe' => '14d',
        ]));

        $this->assertSame('in-progress', json_decode($forced->getContent(), true)['generation']);
        $this->assertSame('last-good', json_decode($normal->getContent(), true)['generation']);

        app(EodCacheVersion::class)->publish(
            ['SPY'],
            [EodCacheVersion::DOMAIN_GEX],
            'published-v2',
            200
        );
        $published = $controller->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => 'SPY',
            'timeframe' => '14d',
        ]));
        $this->assertSame('in-progress', json_decode($published->getContent(), true)['generation']);
    }

    public function test_standalone_derived_commands_publish_only_their_completed_domain(): void
    {
        Bus::fake();
        $versions = app(EodCacheVersion::class);

        $this->artisan('vol:compute', ['symbols' => ['SPY']])->assertSuccessful();
        $this->assertNotSame(
            'initial',
            $versions->current(EodCacheVersion::DOMAIN_VOLATILITY, 'SPY')
        );
        $this->assertSame('initial', $versions->current(EodCacheVersion::DOMAIN_GEX, 'SPY'));

        $this->artisan('expiry:compute', ['--symbols' => ['QQQ']])->assertSuccessful();
        $this->assertNotSame(
            'initial',
            $versions->current(EodCacheVersion::DOMAIN_EXPIRY_PRESSURE, 'QQQ')
        );
        $this->assertSame('initial', $versions->current(EodCacheVersion::DOMAIN_GEX, 'QQQ'));
        $this->assertSame('initial', $versions->current(EodCacheVersion::DOMAIN_ACTIVITY, 'QQQ'));
    }

    public function test_no_op_prime_does_not_advance_or_dispatch_any_cache_domain(): void
    {
        Bus::fake();

        (new NoOpPrimeSymbolJob('SPY'))->handle();

        Bus::assertNothingDispatched();
        foreach (EodCacheVersion::ALL_DOMAINS as $domain) {
            $this->assertSame('initial', app(EodCacheVersion::class)->current($domain, 'SPY'));
        }
    }

    public function test_prime_dispatches_non_cache_work_without_adding_a_publication(): void
    {
        Bus::fake();

        (new NonCacheOnlyPrimeSymbolJob('SPY'))->handle();

        Bus::assertChained([PricesDailyJob::class]);
        Bus::assertNotDispatched(PublishEodCacheVersionJob::class);
        foreach (EodCacheVersion::ALL_DOMAINS as $domain) {
            $this->assertSame('initial', app(EodCacheVersion::class)->current($domain, 'SPY'));
        }
    }

    public function test_legacy_queued_jobs_use_safe_defaults_for_new_publication_fields(): void
    {
        $legacyVol = (new ReflectionClass(ComputeVolMetricsJob::class))
            ->newInstanceWithoutConstructor();
        $legacyVol->symbols = [];
        $legacyVol->anchorDate = '2026-08-14';

        $this->assertFalse($legacyVol->publishCacheVersion);
        $this->assertNull($legacyVol->cachePublicationToken);
        $this->assertNull($legacyVol->cachePublicationIssuedAt);
        $legacyVol->handle();

        Bus::fake();
        $legacyPrime = (new ReflectionClass(NoOpPrimeSymbolJob::class))
            ->newInstanceWithoutConstructor();
        $legacyPrime->symbol = 'SPY';

        $this->assertSame([], $legacyPrime->completedCacheDomains);
        $legacyPrime->handle();
        Bus::assertNothingDispatched();
    }

    private function ensureWatchlistsTable(): void
    {
        if (Schema::hasTable('watchlists')) {
            return;
        }

        Schema::create('watchlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol', 10);
            $table->string('timeframe')->nullable();
            $table->timestamps();
        });
    }

    private function ensureGexTables(): void
    {
        if (! Schema::hasTable('work_run_slots')) {
            Schema::create('work_run_slots', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->uuid('current_run_id')->nullable();
            });
        }
        if (! Schema::hasTable('option_expirations')) {
            Schema::create('option_expirations', function (Blueprint $table): void {
                $table->id();
                $table->string('symbol', 10);
                $table->date('expiration_date');
            });
        }
        if (! Schema::hasTable('option_chain_data')) {
            Schema::create('option_chain_data', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('expiration_id');
                $table->date('data_date');
                $table->decimal('gamma', 16, 8)->nullable();
                $table->dateTime('data_timestamp')->nullable();
            });
        }
    }
}

class FailingEodCacheStep extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        throw new RuntimeException('expected failure');
    }
}

class StubGexController extends GexController
{
    /** @var array<string, mixed> */
    public array $payload = [];

    protected function getTimeframeExpirations(string $symbol, string $requestedTimeframe): array
    {
        return [$requestedTimeframe => ['2026-08-21']];
    }

    protected function hasUsableGreeks(array $expirationIds, string $date): bool
    {
        return true;
    }

    protected function buildGexPayload(
        string $symbol,
        string $timeframe,
        array $dates,
        array $timeframeExpirations,
        array $expirationIds,
        ?string $anchorDate = null
    ): ?array {
        return $this->payload;
    }
}

class NoOpPrimeSymbolJob extends PrimeSymbolJob
{
    public function plannedJobs(): array
    {
        return [];
    }
}

class NonCacheOnlyPrimeSymbolJob extends PrimeSymbolJob
{
    public function plannedJobs(): array
    {
        return [new PricesDailyJob([$this->symbol])];
    }
}
