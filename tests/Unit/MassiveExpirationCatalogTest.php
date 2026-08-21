<?php

namespace Tests\Unit;

use App\Support\MassiveExpirationCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MassiveExpirationCatalogTest extends TestCase
{
    private const BASE = 'https://api.massive.test';

    private const ENDPOINT = self::BASE.'/v3/reference/options/contracts';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.massive.base' => self::BASE,
            'services.massive.key' => 'local-secret',
            'services.massive.mode' => 'query',
            'services.massive.qparam' => 'apiKey',
            'services.massive.concurrency.enabled' => false,
            'services.massive.eod_chain_reference_probe_max_pages' => 4,
        ]);
    }

    public function test_terminal_pages_freeze_a_sorted_unique_catalog_and_rebuild_local_scope_and_auth(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $params = $this->requestParameters($request);
            $requests[] = [
                'url' => (string) $request->url(),
                'params' => $params,
            ];

            if (($params['cursor'] ?? null) === 'page-2') {
                return Http::response([
                    'status' => 'OK',
                    'results' => [
                        $this->contract('2026-08-21'),
                        $this->contract('2026-08-25'),
                    ],
                ]);
            }

            return Http::response([
                'status' => 'OK',
                'results' => [
                    $this->contract('2026-08-21'),
                    $this->contract('2026-08-19'),
                ],
                // The provider's credential is deliberately wrong. Only the
                // opaque cursor may survive into the next local request.
                'next_url' => self::ENDPOINT.'?cursor=page-2&apiKey=provider-value',
            ]);
        });

        $result = app(MassiveExpirationCatalog::class)->discover(
            ' spy ',
            '2026-08-17',
            10
        );

        $this->assertSame([
            '2026-08-19',
            '2026-08-21',
            '2026-08-25',
        ], $result['expirations']);
        $this->assertTrue($result['meta']['complete']);
        $this->assertSame('ok', $result['meta']['status']);
        $this->assertSame(2, $result['meta']['pages']);
        $this->assertFalse($result['meta']['capped']);
        $this->assertSame('bounded_catalog', $result['meta']['source']);
        $this->assertCount(2, $requests);

        foreach ($requests as $index => $captured) {
            $this->assertSame('/v3/reference/options/contracts', parse_url($captured['url'], PHP_URL_PATH));
            $this->assertSame('api.massive.test', parse_url($captured['url'], PHP_URL_HOST));
            $this->assertSame('SPY', $captured['params']['underlying_ticker']);
            $this->assertSame('2026-08-17', $captured['params']['expiration_date.gte']);
            $this->assertSame('2026-08-27', $captured['params']['expiration_date.lte']);
            $this->assertSame('2026-08-17', $captured['params']['as_of']);
            $this->assertSame('asc', $captured['params']['order']);
            $this->assertSame('expiration_date', $captured['params']['sort']);
            $this->assertSame(1000, (int) $captured['params']['limit']);
            $this->assertSame('local-secret', $captured['params']['apiKey']);
            $this->assertSame($index === 0 ? null : 'page-2', $captured['params']['cursor'] ?? null);
        }
    }

    public function test_cursor_scope_change_fails_closed_without_exact_date_fallback(): void
    {
        Http::fake(fn () => Http::response([
            'results' => [$this->contract('2026-08-21')],
            'next_url' => self::ENDPOINT.'?'.http_build_query([
                'cursor' => 'page-2',
                'expiration_date.lte' => '2099-01-01',
            ]),
        ]));

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 10);

        $this->assertSame([], $result['expirations']);
        $this->assertFalse($result['meta']['complete']);
        $this->assertSame('cursor_scope_violation', $result['meta']['status']);
        $this->assertSame('bounded_catalog', $result['meta']['source']);
        Http::assertSentCount(1);
    }

    #[DataProvider('terminalFailureProvider')]
    public function test_terminal_provider_and_payload_failures_do_not_freeze_partial_catalog(
        int $httpStatus,
        mixed $payload,
        string $expectedStatus
    ): void {
        Http::fake(fn () => Http::response($payload, $httpStatus));

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 10);

        $this->assertSame([], $result['expirations']);
        $this->assertFalse($result['meta']['complete']);
        $this->assertSame($expectedStatus, $result['meta']['status']);
        $this->assertSame('bounded_catalog', $result['meta']['source']);
    }

    /** @return array<string,array{int,mixed,string}> */
    public static function terminalFailureProvider(): array
    {
        return [
            'unauthorized' => [401, ['results' => []], 'unauthorized'],
            'rate limited' => [429, ['results' => []], 'rate_limited'],
            'provider unavailable' => [503, ['results' => []], 'http_error'],
            'malformed results' => [200, ['results' => 'not-a-list'], 'malformed_payload'],
            'malformed next cursor' => [200, ['results' => [], 'next_url' => ['bad']], 'malformed_payload'],
        ];
    }

    public function test_bounded_cap_falls_back_to_complete_exact_date_probes(): void
    {
        config()->set('services.massive.eod_chain_reference_probe_max_pages', 1);
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $params = $this->requestParameters($request);
            $requests[] = $params;

            if ((int) ($params['limit'] ?? 0) === 1000) {
                return Http::response([
                    'results' => [$this->contract('2026-08-17')],
                    'next_url' => self::ENDPOINT.'?cursor=page-2',
                ]);
            }

            return match ($params['expiration_date.gte']) {
                '2026-08-17' => Http::response(['results' => [$this->contract('2026-08-17')]]),
                '2026-08-18' => Http::response(['results' => []]),
                '2026-08-19' => Http::response(['results' => [$this->contract('2026-08-19')]]),
                default => Http::response([], 500),
            };
        });

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 2);

        $this->assertSame(['2026-08-17', '2026-08-19'], $result['expirations']);
        $this->assertTrue($result['meta']['complete']);
        $this->assertSame('ok', $result['meta']['status']);
        $this->assertSame('exact_date_fallback', $result['meta']['source']);
        $this->assertSame(4, $result['meta']['pages']);
        $this->assertSame(3, $result['meta']['dates_scanned']);
        $this->assertSame('pagination_capped', $result['meta']['bounded_status']);
        $this->assertSame(1, $result['meta']['bounded_pages']);
        $this->assertTrue($result['meta']['bounded_capped']);
        $this->assertFalse($result['meta']['capped']);

        $exactRequests = array_values(array_filter(
            $requests,
            static fn (array $params): bool => (int) ($params['limit'] ?? 0) === 1
        ));
        $this->assertSame([
            '2026-08-17',
            '2026-08-18',
            '2026-08-19',
        ], array_column($exactRequests, 'expiration_date.gte'));
        foreach ($exactRequests as $params) {
            $this->assertSame($params['expiration_date.gte'], $params['expiration_date.lte']);
            $this->assertSame('2026-08-17', $params['as_of']);
            $this->assertSame('local-secret', $params['apiKey']);
        }
    }

    #[DataProvider('recoverableBoundedFailureProvider')]
    public function test_cycle_and_no_progress_also_use_the_exact_date_fallback(string $mode): void
    {
        Http::fake(function (Request $request) use ($mode) {
            $params = $this->requestParameters($request);
            if ((int) ($params['limit'] ?? 0) === 1) {
                return Http::response(['results' => [$this->contract('2026-08-17')]]);
            }

            if ($mode === 'no_progress') {
                return Http::response([
                    'results' => [],
                    'next_url' => self::ENDPOINT.'?cursor=empty-page',
                ]);
            }

            return Http::response([
                'results' => [$this->contract('2026-08-17')],
                'next_url' => self::ENDPOINT.'?cursor=repeat',
            ]);
        });

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 0);

        $this->assertSame(['2026-08-17'], $result['expirations']);
        $this->assertTrue($result['meta']['complete']);
        $this->assertSame('exact_date_fallback', $result['meta']['source']);
        $this->assertSame(
            $mode === 'no_progress' ? 'pagination_no_progress' : 'cursor_cycle',
            $result['meta']['bounded_status']
        );
    }

    /** @return array<string,array{string}> */
    public static function recoverableBoundedFailureProvider(): array
    {
        return [
            'cursor cycle' => ['cursor_cycle'],
            'empty page with cursor' => ['no_progress'],
        ];
    }

    public function test_failed_exact_date_probe_discards_dates_found_before_the_failure(): void
    {
        config()->set('services.massive.eod_chain_reference_probe_max_pages', 1);
        Http::fake(function (Request $request) {
            $params = $this->requestParameters($request);
            if ((int) ($params['limit'] ?? 0) === 1000) {
                return Http::response([
                    'results' => [$this->contract('2026-08-17')],
                    'next_url' => self::ENDPOINT.'?cursor=page-2',
                ]);
            }

            return $params['expiration_date.gte'] === '2026-08-17'
                ? Http::response(['results' => [$this->contract('2026-08-17')]])
                : Http::response(['results' => []], 429);
        });

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 2);

        $this->assertSame([], $result['expirations']);
        $this->assertFalse($result['meta']['complete']);
        $this->assertSame('rate_limited', $result['meta']['status']);
        $this->assertSame('exact_date_fallback', $result['meta']['source']);
        $this->assertSame(3, $result['meta']['pages']);
        $this->assertSame(2, $result['meta']['dates_scanned']);
        $this->assertSame('pagination_capped', $result['meta']['bounded_status']);
    }

    public function test_terminal_empty_catalog_is_complete_and_distinct_from_provider_failure(): void
    {
        Http::fake(fn () => Http::response(['status' => 'OK', 'results' => []]));

        $result = app(MassiveExpirationCatalog::class)->discover('SPY', '2026-08-17', 90);

        $this->assertSame([], $result['expirations']);
        $this->assertTrue($result['meta']['complete']);
        $this->assertSame('empty_catalog', $result['meta']['status']);
        $this->assertSame(1, $result['meta']['pages']);
    }

    #[DataProvider('invalidRequestProvider')]
    public function test_invalid_requests_fail_before_calling_massive(
        string $symbol,
        string $sessionDate,
        int $horizonDays
    ): void {
        Http::fake();

        $result = app(MassiveExpirationCatalog::class)->discover(
            $symbol,
            $sessionDate,
            $horizonDays
        );

        $this->assertSame([], $result['expirations']);
        $this->assertFalse($result['meta']['complete']);
        $this->assertSame('invalid_request', $result['meta']['status']);
        $this->assertSame(0, $result['meta']['pages']);
        Http::assertNothingSent();
    }

    /** @return array<string,array{string,string,int}> */
    public static function invalidRequestProvider(): array
    {
        return [
            'invalid symbol' => ['../SPY', '2026-08-17', 90],
            'invalid date' => ['SPY', '2026-02-30', 90],
            'negative horizon' => ['SPY', '2026-08-17', -1],
            'unsafe horizon' => ['SPY', '2026-08-17', 367],
        ];
    }

    /** @return array<string,mixed> */
    private function contract(string $expiration, string $symbol = 'SPY'): array
    {
        return [
            'underlying_ticker' => $symbol,
            'expiration_date' => $expiration,
        ];
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
}
