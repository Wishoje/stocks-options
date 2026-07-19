<?php

namespace Tests\Feature;

use App\Jobs\BootstrapUserSymbolJob;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ColdSymbolLocalOwnershipTest extends TestCase
{
    private string $originalDatabaseConnection;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('queue_lanes.isolated', false);
        $this->originalDatabaseConnection = DB::getDefaultConnection();
        config()->set('database.connections.cold-symbol-test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('cold-symbol-test');
        DB::setDefaultConnection('cold-symbol-test');
    }

    public function test_legacy_lambda_configuration_cannot_create_a_second_bootstrap_owner(): void
    {
        config()->set('services.cold_symbol', [
            'lambda_enabled' => true,
            'function' => 'https://unused.invalid/bootstrap',
            'internal_ingest_secret' => 'unused-test-value',
            'internal_base_url' => 'https://unused.invalid',
            'lambda_timeout_ms' => 1,
            'fast_window_days' => 14,
        ]);
        Http::fake();
        Bus::fake();

        $this->createWatchlistIngressTables();
        $user = (new User)->forceFill([
            'id' => 42,
            'name' => 'Cold symbol owner',
            'email' => 'cold-symbol@example.test',
        ]);

        $this->actingAs($user)
            ->postJson('/api/watchlist', ['symbol' => 'AAPL'])
            ->assertCreated()
            ->assertJsonPath('symbol', 'AAPL');

        $this->actingAs($user)
            ->postJson('/api/prime', ['symbol' => 'AAPL'])
            ->assertNoContent();

        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
        Bus::assertDispatched(
            BootstrapUserSymbolJob::class,
            fn (BootstrapUserSymbolJob $job): bool => $job->symbol === 'AAPL'
                && $job->queue === 'bootstrap'
        );
        Http::assertNothingSent();
    }

    public function test_removed_lambda_code_configuration_and_generated_artifacts_stay_absent(): void
    {
        $this->assertNull(config('services.cold_symbol'));

        foreach ([
            \App\Services\ColdSymbolBootstrapCoordinator::class,
            \App\Services\ColdSymbolFastPath::class,
            \App\Services\ColdSymbolEodIngestor::class,
            \App\Services\ColdSymbolIntradayIngestor::class,
            \App\Http\Controllers\InternalColdSymbolIngestController::class,
            \App\Http\Middleware\VerifyColdSymbolInternalRequest::class,
            \App\Jobs\ColdSymbolHydrationJob::class,
        ] as $removedClass) {
            $this->assertFalse(class_exists($removedClass), "{$removedClass} must remain removed.");
        }

        foreach ([
            'lambda/cold_symbol_bootstrap/handler.py',
            'lambda/cold_symbol_bootstrap/README.md',
            'lambda/cold_symbol_bootstrap/__pycache__/handler.cpython-311.pyc',
            'tests/Feature/ColdSymbolFastPathTest.php',
        ] as $removedPath) {
            $this->assertFileDoesNotExist(base_path($removedPath));
        }

        $this->assertStringNotContainsString(
            'COLD_SYMBOL_',
            (string) file_get_contents(base_path('.env.example'))
        );
    }

    public function test_former_lambda_callback_surfaces_are_absent(): void
    {
        Http::fake();

        $this->postJson('/api/internal/cold-symbol/eod-ingest', ['symbol' => 'AAPL'])
            ->assertNotFound();
        $this->postJson('/api/internal/cold-symbol/intraday-ingest', ['symbol' => 'AAPL'])
            ->assertNotFound();

        $uris = collect(Route::getRoutes())->map(fn ($route): string => $route->uri());
        $this->assertFalse($uris->contains(
            fn (string $uri): bool => str_contains($uri, 'internal/cold-symbol')
        ));
        $this->assertArrayNotHasKey('coldsymbol.ingest', app('router')->getMiddleware());
        Http::assertNothingSent();
    }

    public function test_a_failed_local_dispatch_releases_the_claim_for_retry(): void
    {
        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('test queue outage'));

        try {
            BootstrapUserSymbolJob::dispatchIfNeeded('MSFT', 'watchlist_store');
            $this->fail('The simulated dispatch must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('test queue outage', $exception->getMessage());
        }

        Bus::fake();
        $this->assertTrue(BootstrapUserSymbolJob::dispatchIfNeeded('MSFT', 'watchlist_store'));
        Bus::assertDispatchedTimes(BootstrapUserSymbolJob::class, 1);
    }

    private function createWatchlistIngressTables(): void
    {
        Schema::create('watchlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('symbol');
            $table->timestamps();
            $table->unique(['user_id', 'symbol']);
        });
        Schema::create('option_live_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
        });
        Schema::create('option_expirations', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol');
        });
    }

    protected function tearDown(): void
    {
        DB::purge('cold-symbol-test');
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }
}
