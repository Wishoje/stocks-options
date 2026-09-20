<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEodHealthAccess;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureSubscribed;
use App\Http\Middleware\EnsureWorkRunFeature;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedProductRouteBoundaryTest extends TestCase
{
    private const CONNECTION = 'authenticated-product-route-boundary';

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

        config()->set('plans.default_subscription_name', 'default');
        config()->set('plans.plans', [
            'all-products' => [
                'prices' => ['monthly' => 'price_route_boundary_all'],
                'features' => [
                    'app.access',
                    'intraday.access',
                    'scanner.access',
                    'calculator.access',
                ],
            ],
            'scanner-only' => [
                'prices' => ['monthly' => 'price_route_boundary_scanner'],
                'features' => ['scanner.access'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalDatabaseConnection);

        parent::tearDown();
    }

    public function test_framework_authentication_middleware_is_not_shadowed_by_an_exact_alias(): void
    {
        $aliases = app(Router::class)->getMiddleware();

        $this->assertArrayNotHasKey('auth:sanctum', $aliases);
        $this->assertSame(Authenticate::class, $aliases['auth'] ?? null);
        $this->assertFileDoesNotExist(app_path('Providers/SanctumServiceProvider.php'));
        $this->assertArrayNotHasKey(
            'App\\Providers\\SanctumServiceProvider',
            app()->getLoadedProviders()
        );
    }

    public function test_every_first_party_api_route_is_covered_by_an_explicit_access_contract(): void
    {
        $expected = [];
        foreach (self::productRouteProvider() as [$method, $uri]) {
            $expected[] = $method.' '.$this->matchedRoute($method, $uri)->uri();
        }
        foreach (self::diagnosticRouteProvider() as [$uri]) {
            $expected[] = 'GET '.$this->matchedRoute('GET', $uri)->uri();
        }
        foreach (['/api/me', '/api/user'] as $uri) {
            $expected[] = 'GET '.$this->matchedRoute('GET', $uri)->uri();
        }
        $expected[] = 'GET '.$this->matchedRoute(
            'GET',
            '/api/work-runs/00000000-0000-0000-0000-000000000001'
        )->uri();

        $actual = [];
        foreach (app(Router::class)->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $actual[] = $method.' '.$route->uri();
            }
        }

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[DataProvider('productRouteProvider')]
    public function test_product_routes_have_real_authentication_strict_entitlement_and_a_limiter(
        string $method,
        string $uri,
        string $feature,
        string $limiter
    ): void {
        $middleware = $this->resolvedMiddleware($method, $uri);

        $this->assertMiddlewareIncludesInOrder($middleware, [
            Authenticate::class.':sanctum',
            EnsureFeature::class.':'.$feature.',strict',
        ]);
        $this->assertContains(ThrottleRequests::class.':'.$limiter, $middleware);
    }

    #[DataProvider('diagnosticRouteProvider')]
    public function test_internal_diagnostics_retain_authentication_health_access_and_read_throttling(
        string $uri
    ): void {
        $middleware = $this->resolvedMiddleware('GET', $uri);

        $this->assertMiddlewareIncludesInOrder($middleware, [
            Authenticate::class.':sanctum',
            EnsureEodHealthAccess::class,
        ]);
        $this->assertContains(ThrottleRequests::class.':market-data-read', $middleware);
    }

    public function test_identity_routes_are_authentication_only(): void
    {
        foreach (['/api/me', '/api/user'] as $uri) {
            $middleware = $this->resolvedMiddleware('GET', $uri);

            $this->assertContains(Authenticate::class.':sanctum', $middleware);
            $this->assertFalse(collect($middleware)->contains(
                fn (string $name): bool => str_starts_with($name, EnsureFeature::class.':')
                    || $name === EnsureEodHealthAccess::class
                    || $name === EnsureSubscribed::class
                    || $name === EnsureWorkRunFeature::class
                    || str_starts_with($name, ThrottleRequests::class.':')
            ));
        }
    }

    public function test_subscription_is_checked_before_a_work_run_is_loaded(): void
    {
        $middleware = $this->resolvedMiddleware(
            'GET',
            '/api/work-runs/00000000-0000-0000-0000-000000000001'
        );

        $this->assertMiddlewareIncludesInOrder($middleware, [
            Authenticate::class.':sanctum',
            EnsureSubscribed::class,
            EnsureWorkRunFeature::class,
        ]);
        $this->assertContains(ThrottleRequests::class.':work-status', $middleware);
    }

    public function test_app_product_route_rejects_guests_and_unsubscribed_accounts_before_controller_work(): void
    {
        $this->getJson('/api/watchlist')->assertUnauthorized();

        $this->actingAs($this->user())
            ->getJson('/api/watchlist')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_strict_feature_gate_rejects_a_subscriber_without_the_product_feature(): void
    {
        $this->actingAs($this->subscribedUser('price_route_boundary_scanner'))
            ->getJson('/api/watchlist')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_not_available');
    }

    public function test_entitled_subscriber_can_reach_the_product_controller(): void
    {
        $this->actingAs($this->subscribedUser('price_route_boundary_all'))
            ->getJson('/api/watchlist')
            ->assertOk()
            ->assertExactJson([]);
    }

    #[DataProvider('adverseSubscriptionProvider')]
    public function test_strict_feature_gates_fail_closed_for_adverse_subscription_states(
        string $providerStatus,
        string $endTiming,
        string $uri,
    ): void {
        $this->actingAs($this->subscribedUser(
            'price_route_boundary_all',
            $providerStatus,
            $endTiming,
        ))
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_strict_feature_gate_preserves_a_standalone_generic_trial(): void
    {
        $user = $this->user();
        $user->forceFill(['trial_ends_at' => now()->addDay()])->save();

        $this->actingAs($user->fresh())
            ->getJson('/api/watchlist')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_strict_feature_gate_does_not_let_a_stale_generic_trial_bypass_an_adverse_subscription(): void
    {
        $user = $this->subscribedUser('price_route_boundary_all', 'paused');
        $user->forceFill(['trial_ends_at' => now()->addDay()])->save();

        $this->actingAs($user->fresh())
            ->getJson('/api/watchlist')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /** @return array<string, array{string, string, string}> */
    public static function adverseSubscriptionProvider(): array
    {
        return [
            'past due app access' => ['past_due', 'none', '/api/watchlist'],
            'incomplete intraday access' => ['incomplete', 'none', '/api/intraday/summary?symbol=SPY'],
            'paused scanner access' => ['paused', 'none', '/api/hot-options'],
            'unpaid calculator access' => ['unpaid', 'none', '/api/option-chain?symbol=SPY'],
            'expired setup' => ['incomplete_expired', 'none', '/api/watchlist'],
            'unknown provider status' => ['provider_future_state', 'none', '/api/intraday/summary?symbol=SPY'],
            'canceled without an end date' => ['canceled', 'none', '/api/hot-options'],
            'active with a past local end' => ['active', 'past', '/api/option-chain?symbol=SPY'],
            'paused with a future local end' => ['paused', 'future', '/api/watchlist'],
        ];
    }

    #[DataProvider('adverseSharedSubscriptionProvider')]
    public function test_shared_subscription_gate_fails_closed_for_adverse_subscription_states(
        string $providerStatus,
        string $endTiming,
    ): void {
        $this->actingAs($this->subscribedUser(
            'price_route_boundary_all',
            $providerStatus,
            $endTiming,
        ))
            ->getJson('/api/work-runs/00000000-0000-0000-0000-000000000001')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_required');
    }

    /** @return array<string, array{string, string}> */
    public static function adverseSharedSubscriptionProvider(): array
    {
        return [
            'past due' => ['past_due', 'none'],
            'incomplete' => ['incomplete', 'none'],
            'paused' => ['paused', 'none'],
            'unpaid' => ['unpaid', 'none'],
            'expired setup' => ['incomplete_expired', 'none'],
            'unknown provider status' => ['provider_future_state', 'none'],
            'canceled without an end date' => ['canceled', 'none'],
            'active with a past local end' => ['active', 'past'],
            'paused with a future local end' => ['paused', 'future'],
        ];
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function productRouteProvider(): array
    {
        $appReads = [
            '/api/gex-levels?symbol=SPY&timeframe=14d',
            '/api/symbols?q=SPY',
            '/api/symbol/status?symbol=SPY&timeframe=14d',
            '/api/watchlist',
            '/api/watchlist/universe',
            '/api/watchlist/eod-exports',
            '/api/watchlist/eod-export/1',
            '/api/watchlist/eod-export/1/download',
            '/api/iv/term?symbol=SPY',
            '/api/vrp?symbol=SPY',
            '/api/qscore?symbol=SPY',
            '/api/seasonality/5d?symbol=SPY',
            '/api/iv/skew?symbol=SPY',
            '/api/iv/skew/by-bucket?symbol=SPY&days=7',
            '/api/iv/skew/history?symbol=SPY',
            '/api/iv/skew/history/bucket?symbol=SPY&days=7',
            '/api/dex?symbol=SPY',
            '/api/expiry-pressure?symbol=SPY',
            '/api/expiry-pressure/batch?symbols[]=SPY',
            '/api/ua?symbol=SPY',
        ];
        $intradayReads = [
            '/api/intraday/summary?symbol=SPY',
            '/api/intraday/volume-by-strike?symbol=SPY',
            '/api/intraday/ua?symbol=SPY',
            '/api/intraday/strikes?symbol=SPY',
            '/api/intraday/repriced-gex-by-strike?symbol=SPY',
        ];

        $routes = [];
        foreach ($appReads as $uri) {
            $routes['app read '.$uri] = ['GET', $uri, 'app.access', 'market-data-read'];
        }
        foreach ([
            ['POST', '/api/watchlist'],
            ['DELETE', '/api/watchlist/1'],
            ['POST', '/api/watchlist/eod-export'],
            ['POST', '/api/prime'],
        ] as [$method, $uri]) {
            $routes['app work '.$method.' '.$uri] = [$method, $uri, 'app.access', 'work-start'];
        }
        foreach ($intradayReads as $uri) {
            $routes['intraday read '.$uri] = ['GET', $uri, 'intraday.access', 'market-data-read'];
        }
        $routes['intraday work'] = ['POST', '/api/intraday/pull', 'intraday.access', 'work-start'];
        $routes['scanner read'] = ['GET', '/api/hot-options', 'scanner.access', 'market-data-read'];
        $routes['scanner computation'] = ['POST', '/api/scanner/walls', 'scanner.access', 'market-data-read'];
        $routes['calculator read'] = ['GET', '/api/option-chain?symbol=SPY', 'calculator.access', 'market-data-read'];
        $routes['calculator work'] = ['POST', '/api/prime-calculator', 'calculator.access', 'work-start'];

        return $routes;
    }

    /** @return array<string, array{string}> */
    public static function diagnosticRouteProvider(): array
    {
        return [
            'ingest health' => ['/api/health/ingest'],
            'EOD health' => ['/api/eod/health'],
            'skew debug' => ['/api/iv/skew/debug?symbol=SPY'],
            'unusual activity debug' => ['/api/ua/debug?symbol=SPY'],
            'market debug' => ['/api/debug/market'],
        ];
    }

    /** @return list<string> */
    private function resolvedMiddleware(string $method, string $uri): array
    {
        $router = app(Router::class);
        $route = $this->matchedRoute($method, $uri);

        return array_values($router->gatherRouteMiddleware($route));
    }

    private function matchedRoute(string $method, string $uri): IlluminateRoute
    {
        return app(Router::class)->getRoutes()->match(Request::create($uri, $method));
    }

    /** @param list<string> $actual @param list<string> $expected */
    private function assertMiddlewareIncludesInOrder(array $actual, array $expected): void
    {
        $lastIndex = -1;
        foreach ($expected as $middleware) {
            $index = array_search($middleware, $actual, true);
            $this->assertIsInt($index, "Missing middleware [{$middleware}].");
            $this->assertGreaterThan($lastIndex, $index, "Middleware [{$middleware}] is out of order.");
            $lastIndex = $index;
        }
    }

    private function subscribedUser(
        string $price,
        string $providerStatus = 'active',
        string $endTiming = 'none',
    ): User {
        $user = $this->user();
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid('', true),
            'stripe_status' => $providerStatus,
            'stripe_price' => $price,
            'quantity' => 1,
            'trial_ends_at' => $providerStatus === 'trialing' ? now()->addDays(7) : null,
            'ends_at' => match ($endTiming) {
                'future' => now()->addDay(),
                'past' => now()->subMinute(),
                default => null,
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId,
            'stripe_id' => 'si_'.uniqid('', true),
            'stripe_product' => 'prod_route_boundary',
            'stripe_price' => $price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function user(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Route boundary user',
            'email' => 'route-boundary-'.uniqid('', true).'@example.test',
            'password' => 'unused-test-password',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
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
            $table->string('symbol', 10);
            $table->timestamps();
        });
    }
}
