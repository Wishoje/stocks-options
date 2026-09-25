<?php

namespace Tests\Unit;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MarketReadRateLimitTest extends TestCase
{
    private ThrottleRequests $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('cache.limiter', 'array');
        config()->set('cache.coordination_enabled', false);
        $limiter = new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store('array'));
        $limiter->for('market-data-read', \Illuminate\Support\Facades\RateLimiter::limiter('market-data-read'));
        $this->middleware = new ThrottleRequests($limiter);
        Log::spy();
    }

    private function readAs(int $id, string $path = 'api/gex-levels')
    {
        $request = Request::create('/'.$path.'?symbol=SPY', 'GET');
        $request->setUserResolver(fn () => new class($id)
        {
            public function __construct(private int $id) {}

            public function getAuthIdentifier(): int
            {
                return $this->id;
            }
        });
        $request->setRouteResolver(fn () => new Route('GET', $path, fn () => null));
        try {
            return $this->middleware->handle(
                $request, fn () => response()->json(['ok' => true]), 'market-data-read'
            );
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }
    }

    public function test_shared_budget_is_preserved_and_logs_once_without_request_identity(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->assertSame(200, $this->readAs(101)->getStatusCode());
        }
        $limited = $this->readAs(101, 'api/dex');
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('market-data-read', $limited->getData(true)['rate_limit_scope']);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertSame(429, $this->readAs(101)->getStatusCode());
        $this->assertSame(200, $this->readAs(102)->getStatusCode());
        Log::shouldHaveReceived('warning')->once()->with('market_data.read_rate_limited', [
            'route' => 'api/dex', 'method' => 'GET', 'limit_per_minute' => 300,
            'retry_after_seconds' => (int) $limited->headers->get('Retry-After'),
        ]);
        $this->travel(61)->seconds();
        $this->assertSame(200, $this->readAs(101)->getStatusCode());
    }
}
