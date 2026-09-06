<?php

namespace Tests\Feature;

use App\Exceptions\ProviderDeferred;
use App\Http\Controllers\SymbolSearchController;
use App\Jobs\FetchOptionChainDataJob;
use App\Jobs\FetchUnderlyingQuotesJob;
use App\Jobs\PricesDailyJob;
use App\Models\User;
use App\Services\HistoricalEodRecoveryProvider;
use App\Support\MassiveExpirationCatalog;
use App\Support\PolygonClient;
use App\Support\Prices;
use App\Support\ProviderConcurrencyLimiter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Provider boundary tests use fake HTTP and no database or Redis connection. */
class ProviderHttpBoundaryTest extends TestCase
{
    private ProviderBoundaryLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'));
        config()->set([
            'provider_backpressure.enabled' => true,
            'intraday_freshness.enabled' => true,
            'queue_lanes.isolated' => false,
            'services.massive.concurrency.enabled' => false,
            'services.massive.base' => 'https://api.massive.test',
            'services.massive.key' => 'boundary-test-key',
            'services.massive.mode' => 'header',
            'services.massive.header' => 'X-API-Key',
            'services.massive.qparam' => 'apiKey',
            'services.massive.eod_chain_partitioned_fetch_enabled' => false,
            'services.finnhub.api_key' => null,
            'cache.default' => 'array',
        ]);
        $this->limiter = new ProviderBoundaryLimiter;
        $this->app->instance(ProviderConcurrencyLimiter::class, $this->limiter);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public static function deferredBoundaries(): array
    {
        $cases = [];
        foreach ([429, 503] as $status) {
            foreach (['intraday', 'quote', 'quote_job', 'catalog', 'eod', 'historical_catalog', 'historical_snapshot', 'prices', 'prices_job', 'symbol_search'] as $boundary) {
                $cases[$boundary.' HTTP '.$status] = [$boundary, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('deferredBoundaries')]
    public function test_each_boundary_makes_one_physical_attempt_and_preserves_typed_deferral(string $boundary, int $status): void
    {
        Http::fake(fn () => Http::response(['status' => 'ERROR'], $status, ['Retry-After' => '180']));

        try {
            $this->invokeBoundary($boundary);
            $this->fail('A transient provider failure must remain deferred instead of returning empty data.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame(ProviderDeferred::reasonForHttpStatus($status), $exception->reason);
            $this->assertSame($status, $exception->httpStatus);
            $this->assertSame(180, $exception->retryAfterSeconds(now('UTC')));
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => parse_url($request->url(), PHP_URL_HOST) === 'api.massive.test');
        $this->assertCount(1, $this->limiter->requestKeys);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->limiter->requestKeys[0]);
        $this->assertFalse(Cache::has('sym_search:spy'));
    }

    public function test_symbol_search_does_not_negative_cache_a_zero_http_admission_deferral(): void
    {
        $deferred = new ProviderDeferred(ProviderDeferred::COOLDOWN, now('UTC')->toImmutable()->addMinutes(5), 429);
        $this->limiter->admissionFailure = $deferred;
        Http::fake();

        try {
            $this->invokeBoundary('symbol_search');
            $this->fail('A cooldown must reach the global retry renderer.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame($deferred, $exception);
        }

        Http::assertNothingSent();
        $this->assertFalse(Cache::has('sym_search:spy'));
    }

    public function test_an_unauthorized_response_remains_auth_failure_instead_of_a_retryable_empty_quote(): void
    {
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));

        try {
            $this->invokeBoundary('quote');
            $this->fail('An unauthorized quote must remain an authentication error.');
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(ProviderDeferred::class, $exception);
            $this->assertStringContainsString('unauthorized', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_web_symbol_search_renders_the_shared_retry_deadline_without_negative_caching(): void
    {
        $this->limiter->admissionFailure = new ProviderDeferred(
            ProviderDeferred::COOLDOWN, now('UTC')->toImmutable()->addMinutes(5), 429
        );
        Http::fake();
        $this->actingAs((new User)->forceFill([
            'id' => 42,
            'name' => 'Boundary tester',
            'email' => 'boundary@example.test',
            'trial_ends_at' => now()->addDay(),
        ]));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->getJson('/api/symbols?q=SPY')
                ->assertStatus(503)
                ->assertHeader('Retry-After', '300')
                ->assertJsonPath('code', ProviderDeferred::COOLDOWN)
                ->assertJsonPath('retry_after_seconds', 300);
        }

        Http::assertNothingSent();
        $this->assertFalse(Cache::has('sym_search:spy'));
    }

    public function test_successful_price_and_quote_response_fields_are_unchanged(): void
    {
        $sourceAsOf = '1788533970123456789';
        Http::fake(function (ClientRequest $request) use ($sourceAsOf) {
            if (str_contains($request->url(), '/v1/open-close/')) {
                return Http::response(['from' => '2026-09-04', 'open' => 499.125, 'high' => 501.75, 'low' => 498.5, 'close' => 500.625, 'volume' => 987654321]);
            }

            return Http::response(['ticker' => ['lastTrade' => ['p' => 501.125], 'prevDay' => ['c' => 498.5], 'updated' => $sourceAsOf]]);
        });

        $this->assertSame([
            'date' => '2026-09-04', 'open' => 499.125, 'high' => 501.75, 'low' => 498.5, 'close' => 500.625, 'volume' => 987654321,
        ], Prices::daily('SPY', '2026-09-04'));
        $this->assertSame([
            'symbol' => 'SPY', 'last_price' => 501.125, 'prev_close' => 498.5, 'asof' => $sourceAsOf, 'source' => 'massive-v2-snapshot',
        ], app(PolygonClient::class)->underlyingQuote('SPY'));
        Http::assertSentCount(2);
        $this->assertCount(2, array_unique($this->limiter->requestKeys));
    }

    public static function pageReplayModes(): array
    {
        return [
            'successful page reused' => [true, 3],
            'whole job retried without page reuse' => [false, 4],
        ];
    }

    #[DataProvider('pageReplayModes')]
    public function test_late_page_deferral_preserves_complete_numeric_payload(bool $reusePages, int $physicalAcrossRetry): void
    {
        $this->limiter->replaySuccessful = $reusePages;
        $failedSecondPage = false;
        $pageOne = $this->contract('call', 75, 2.0);
        $pageTwo = $this->contract('put', 30, 1.5);
        Http::fake(function (ClientRequest $request) use (&$failedSecondPage, $pageOne, $pageTwo) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            if (($params['cursor'] ?? null) === 'page-two') {
                if (! $failedSecondPage) {
                    $failedSecondPage = true;

                    return Http::response([], 429, ['Retry-After' => '60']);
                }

                return Http::response(['status' => 'OK', 'request_id' => 'page-two-id', 'results' => [$pageTwo]]);
            }

            return Http::response([
                'status' => 'OK', 'request_id' => 'page-one-id', 'results' => [$pageOne],
                'next_url' => 'https://api.massive.test/v3/snapshot/options/SPY?cursor=page-two',
            ]);
        });

        try {
            app(PolygonClient::class)->intradayOptionVolumes('SPY', '2026-09-11');
            $this->fail('The failed second page must not expose the successful first page as a complete snapshot.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame(60, $exception->retryAfterSeconds(now('UTC')));
        }
        Http::assertSentCount(2);

        $this->travel(60)->seconds();
        $replayed = app(PolygonClient::class)->intradayOptionVolumes('SPY', '2026-09-11');
        Http::assertSentCount($physicalAcrossRetry);
        $this->assertSame(['call_vol' => 75, 'put_vol' => 30, 'premium' => 19500.0], $replayed['totals']);
        $this->assertTrue($replayed['complete']);
        $this->assertSame(json_decode(json_encode([$pageOne, $pageTwo]), true), $replayed['contracts']);
        $this->assertSame($this->limiter->requestKeys[0], $this->limiter->requestKeys[2]);
        $this->assertSame($this->limiter->requestKeys[1], $this->limiter->requestKeys[3]);
        $this->assertNotSame($this->limiter->requestKeys[0], $this->limiter->requestKeys[1]);

        $this->limiter->replaySuccessful = false;
        $fresh = app(PolygonClient::class)->intradayOptionVolumes('SPY', '2026-09-11');
        Http::assertSentCount($physicalAcrossRetry + 2);
        $this->assertSame($fresh, $replayed);
    }

    private function invokeBoundary(string $name): mixed
    {
        return match ($name) {
            'intraday' => app(PolygonClient::class)->intradayOptionVolumes('SPY', '2026-09-11'),
            'quote' => app(PolygonClient::class)->underlyingQuote('SPY'),
            'quote_job' => (new FetchUnderlyingQuotesJob(['SPY', 'QQQ']))->handle(),
            'catalog' => app(MassiveExpirationCatalog::class)->discover('SPY', '2026-09-04', 14),
            'eod' => (new ProviderBoundaryEodProbe)->fetchProviderOnly(),
            'historical_catalog' => app(HistoricalEodRecoveryProvider::class)->referenceContracts('SPY', '2026-09-04', '2026-09-18'),
            'historical_snapshot' => app(HistoricalEodRecoveryProvider::class)->snapshotPartition('SPY', '2026-09-11', 'call'),
            'prices' => Prices::daily('SPY', '2026-09-04'),
            'prices_job' => (new PricesDailyJob(['SPY', 'QQQ'], '2026-09-04'))->handle(),
            'symbol_search' => app(SymbolSearchController::class)->lookup(Request::create('/api/symbols', 'GET', ['q' => 'SPY'])),
        };
    }

    private function contract(string $side, int $volume, float $price): array
    {
        return [
            'details' => ['contract_type' => $side, 'strike_price' => 500, 'expiration_date' => '2026-09-11'],
            'day' => ['volume' => $volume, 'vwap' => $price, 'last_updated' => CarbonImmutable::parse('2026-09-04 14:59:00', 'UTC')->getTimestamp()],
        ];
    }
}

/**
 * A deterministic gate contract, not a replacement for limiter/Redis tests.
 * It exposes repeated physical HTTP inside one callback and preserves page
 * bodies by their supplied key so caller reconstruction can be checked alone.
 */
class ProviderBoundaryLimiter extends ProviderConcurrencyLimiter
{
    public array $requestKeys = [];

    public bool $replaySuccessful = false;

    public ?ProviderDeferred $admissionFailure = null;

    private array $successful = [];

    public function massive(callable $callback, ?int $blockForSeconds = null, ?string $requestKey = null): mixed
    {
        $this->requestKeys[] = $requestKey;
        if ($this->replaySuccessful && isset($this->successful[$requestKey])) {
            return $this->successful[$requestKey];
        }
        if ($this->admissionFailure !== null) {
            throw $this->admissionFailure;
        }
        $response = $callback();
        $reason = $response instanceof Response ? ProviderDeferred::reasonForHttpStatus($response->status()) : null;
        if ($reason !== null) {
            throw new ProviderDeferred(
                $reason,
                now('UTC')->toImmutable()->addSeconds(max(1, (int) $response->header('Retry-After'))),
                $response->status()
            );
        }
        if ($this->replaySuccessful && $response instanceof Response && $response->status() === 200) {
            $this->successful[$requestKey] = $response;
        }

        return $response;
    }
}

class ProviderBoundaryEodProbe extends FetchOptionChainDataJob
{
    public function __construct()
    {
        parent::__construct(['SPY'], 14, '2026-09-04');
    }

    public function fetchProviderOnly(): array
    {
        return $this->fetchMassiveChain(
            'SPY',
            Carbon::parse('2026-09-04', 'America/New_York'),
            Carbon::parse('2026-09-18', 'America/New_York')
        );
    }
}
