<?php

namespace Tests\Unit;

use App\Exceptions\ProviderDeferred;
use App\Support\PolygonClient;
use App\Support\ProviderConcurrencyLimiter;
use App\Support\ProviderRequestReplay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** The provider contract uses fake HTTP and no database or Redis connection. */
class QuoteBatchProviderTest extends TestCase
{
    private const URL = 'https://api.massive.test/v2/snapshot/locale/us/markets/stocks/tickers';

    private QuoteBatchGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-04 15:00:00', 'UTC'));
        config()->set([
            'provider_backpressure.enabled' => true,
            'services.massive.base' => 'https://api.massive.test',
            'services.massive.key' => 'batch-test-key',
            'services.massive.mode' => 'header',
            'services.massive.header' => 'X-API-Key',
            'services.massive.qparam' => 'apiKey',
            'services.massive.concurrency.enabled' => false,
            'queue_lanes.isolated' => false,
        ]);
        $this->gate = new QuoteBatchGate;
        $this->app->instance(ProviderConcurrencyLimiter::class, $this->gate);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_four_single_requests_and_one_batch_have_exact_field_and_timestamp_parity(): void
    {
        $snapshots = $this->snapshots();
        Http::fake(function (Request $request) use ($snapshots) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/tickers')) {
                return Http::response(['status' => 'OK', 'tickers' => array_reverse(array_values($snapshots))]);
            }

            return Http::response(['status' => 'OK', 'ticker' => $snapshots[basename($path)]]);
        });

        $client = app(PolygonClient::class);
        $single = [];
        foreach (array_keys($snapshots) as $symbol) {
            $single[$symbol] = $client->underlyingQuote($symbol);
        }
        Http::assertSentCount(4);

        $batch = $client->underlyingQuotes(array_keys($snapshots));
        Http::assertSentCount(5); // Exactly one additional request for all four.
        $this->assertSame($single, $batch);
        $expected = [
            'SPY' => [501.125, 499.5, '1788533999123456789'],
            'QQQ' => [432.75, 430.25, 1788533940000],
            'AAPL' => [221.5, 0.0, 0],
            'IWM' => [202.25, null, null],
        ];
        foreach ($expected as $symbol => [$price, $previous, $asof]) {
            $this->assertSame([
                'symbol' => $symbol, 'last_price' => $price,
                'prev_close' => $previous, 'asof' => $asof, 'source' => 'massive-v2-snapshot',
            ], $batch[$symbol]);
        }
        $this->assertSame(
            ProviderRequestReplay::fingerprint(self::URL, ['tickers' => 'AAPL,IWM,QQQ,SPY']),
            $this->gate->keys[4]
        );
    }

    public function test_empty_input_never_calls_the_provider_or_the_shared_gate(): void
    {
        Http::fake();

        $this->assertSame([], app(PolygonClient::class)->underlyingQuotes([]));
        Http::assertNothingSent();
        $this->assertSame([], $this->gate->keys);
    }

    public function test_canonical_duplicates_share_one_bounded_filter_and_preserve_requested_key_order(): void
    {
        Http::fake(fn () => Http::response(['status' => 'OK', 'tickers' => []]));

        $this->assertSame(['SPY' => null, 'QQQ' => null, 'BRK.B' => null, 'BF-B' => null],
            app(PolygonClient::class)->underlyingQuotes([' spy ', 'qqq', 'SPY', 'BRK.B', 'bf-b', ' qqq ']));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $this->params($request) === ['tickers' => 'BF-B,BRK.B,QQQ,SPY']);
    }

    public static function invalidInputs(): array
    {
        return [
            'five symbols' => [['SPY', 'QQQ', 'IWM', 'AAPL', 'TSLA']],
            'empty string' => [['']],
            'whitespace' => [['  ']],
            'null' => [[null]],
            'integer' => [[123]],
            'nested array' => [[['SPY']]],
            'whole-market wildcard' => [['*']],
            'comma list' => [['SPY,QQQ']],
            'query injection' => [['SPY&tickers=']],
            'path' => [['../SPY']],
            'overlong' => [[str_repeat('A', 33)]],
            'mixed valid and invalid' => [['SPY', '']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_oversized_input_is_rejected_before_any_request(array $symbols): void
    {
        Http::fake();

        try {
            app(PolygonClient::class)->underlyingQuotes($symbols);
            $this->fail('Invalid quote batch must not reach the provider.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
            $this->assertSame([], $this->gate->keys);
        }
    }

    public function test_unrequested_missing_and_duplicate_rows_cannot_publish_the_wrong_symbol(): void
    {
        $rows = $this->snapshots();
        Http::fake(fn () => Http::response(['status' => 'OK', 'tickers' => [
            $rows['SPY'],
            array_replace($rows['SPY'], ['lastTrade' => ['p' => 999]]),
            $rows['SPY'],
            array_replace($rows['AAPL'], ['ticker' => 'TSLA']),
            ['ticker' => ['QQQ'], 'lastTrade' => ['p' => 999]],
            $rows['QQQ'],
        ]]));

        $batch = app(PolygonClient::class)->underlyingQuotes(['SPY', 'QQQ', 'AAPL']);
        $this->assertSame(['SPY', 'QQQ', 'AAPL'], array_keys($batch));
        $this->assertNull($batch['SPY']);
        $this->assertNull($batch['AAPL']);
        $this->assertSame('QQQ', $batch['QQQ']['symbol']);
        $this->assertSame(432.75, $batch['QQQ']['last_price']);
        Http::assertSentCount(1);
    }

    public function test_missing_price_remains_null_without_fabricating_time_or_fetching_single_fallbacks(): void
    {
        $snapshot = ['ticker' => 'SPY', 'lastTrade' => ['p' => 0], 'day' => ['c' => 0], 'min' => ['c' => 0], 'lastQuote' => ['P' => 0]];
        Http::fake(fn () => Http::response(['status' => 'OK', 'tickers' => [$snapshot]]));

        $this->assertSame(['SPY' => null, 'QQQ' => null], app(PolygonClient::class)->underlyingQuotes(['SPY', 'QQQ']));
        Http::assertSentCount(1);
    }

    public static function authModes(): array
    {
        return [['header'], ['bearer'], ['query']];
    }

    #[DataProvider('authModes')]
    public function test_each_auth_mode_keeps_the_nonempty_ticker_filter(string $mode): void
    {
        config()->set('services.massive.mode', $mode);
        Http::fake(fn () => Http::response(['status' => 'OK', 'tickers' => []]));

        app(PolygonClient::class)->underlyingQuotes(['QQQ', 'SPY']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($mode): bool {
            $params = $this->params($request);

            return $params['tickers'] === 'QQQ,SPY' && match ($mode) {
                'header' => $request->hasHeader('X-API-Key', 'batch-test-key') && ! isset($params['apiKey']),
                'bearer' => $request->hasHeader('Authorization', 'Bearer batch-test-key') && ! isset($params['apiKey']),
                'query' => ($params['apiKey'] ?? null) === 'batch-test-key',
            };
        });
        $params = ['tickers' => 'QQQ,SPY'];
        if ($mode === 'query') {
            $params['apiKey'] = 'batch-test-key';
        }
        $this->assertSame([ProviderRequestReplay::fingerprint(self::URL, $params)], $this->gate->keys);
    }

    public function test_query_auth_cannot_overwrite_the_ticker_filter(): void
    {
        config()->set(['services.massive.mode' => 'query', 'services.massive.qparam' => 'tickers']);
        Http::fake();

        try {
            app(PolygonClient::class)->underlyingQuotes(['SPY']);
            $this->fail('Authentication must not overwrite the bounded ticker filter.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public static function unsuccessfulStatuses(): array
    {
        return [[401], [403], [404], [408], [429], [500], [503]];
    }

    #[DataProvider('unsuccessfulStatuses')]
    public function test_unsuccessful_batch_responses_do_not_look_like_missing_quotes_or_trigger_fallbacks(int $status): void
    {
        Http::fake(fn () => Http::response(['status' => 'ERROR'], $status, ['Retry-After' => '180']));

        try {
            app(PolygonClient::class)->underlyingQuotes(['SPY', 'QQQ']);
            $this->fail('Batch failure must not be accepted as empty per-symbol data.');
        } catch (RuntimeException $exception) {
            $reason = ProviderDeferred::reasonForHttpStatus($status);
            if ($reason !== null) {
                $this->assertInstanceOf(ProviderDeferred::class, $exception);
                $this->assertSame($reason, $exception->reason);
                $this->assertSame(180, $exception->retryAfterSeconds(now('UTC')));
            } else {
                $this->assertNotInstanceOf(ProviderDeferred::class, $exception);
                $this->assertStringContainsString(in_array($status, [401, 403], true) ? 'unauthorized' : 'http_error', $exception->getMessage());
            }
        }
        Http::assertSentCount(1);
        $this->assertCount(1, $this->gate->keys);
    }

    public function test_zero_request_admission_deferral_propagates_unchanged(): void
    {
        $deferred = new ProviderDeferred(ProviderDeferred::COOLDOWN, now('UTC')->toImmutable()->addMinutes(5));
        $this->gate->admissionFailure = $deferred;
        Http::fake();

        try {
            app(PolygonClient::class)->underlyingQuotes(['SPY', 'QQQ']);
            $this->fail('A shared cooldown must remain pending provider work.');
        } catch (ProviderDeferred $exception) {
            $this->assertSame($deferred, $exception);
        }
        Http::assertNothingSent();
    }

    public static function invalidPayloads(): array
    {
        return [
            'provider error at HTTP200' => [['status' => 'ERROR', 'tickers' => []]],
            'missing tickers' => [['status' => 'OK']],
            'null tickers' => [['status' => 'OK', 'tickers' => null]],
            'object instead of list' => [['status' => 'OK', 'tickers' => ['SPY' => ['ticker' => 'SPY']]]],
            'invalid JSON' => ['<html>temporary failure</html>'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_malformed_batch_payload_is_not_accepted_as_complete_empty_data(mixed $body): void
    {
        Http::fake(fn () => Http::response($body));

        try {
            app(PolygonClient::class)->underlyingQuotes(['SPY']);
            $this->fail('Malformed batch must remain a provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('invalid_payload', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    private function snapshots(): array
    {
        return [
            'SPY' => ['ticker' => 'SPY', 'lastTrade' => ['p' => '501.125'], 'day' => ['c' => 500], 'prevDay' => ['c' => '499.5'], 'updated' => '1788533999123456789'],
            'QQQ' => ['ticker' => 'QQQ', 'lastTrade' => ['p' => 0], 'day' => ['c' => 0, 'previous_close' => '430.25'], 'min' => ['c' => '432.75', 't' => 1788533940000], 'updated' => null],
            'AAPL' => ['ticker' => 'AAPL', 'day' => ['c' => '221.5'], 'prevDay' => ['c' => 0], 'updated' => 0, 'min' => ['t' => 1788533940000]],
            'IWM' => ['ticker' => 'IWM', 'lastTrade' => ['p' => 0], 'day' => ['c' => 0], 'min' => ['c' => 0], 'lastQuote' => ['P' => '202.25']],
        ];
    }

    private function params(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return $params;
    }
}

/** The GEX-021 gate is independently covered with real Redis transport. */
class QuoteBatchGate extends ProviderConcurrencyLimiter
{
    public array $keys = [];

    public ?ProviderDeferred $admissionFailure = null;

    public function massive(callable $callback, ?int $blockForSeconds = null, ?string $requestKey = null): mixed
    {
        $this->keys[] = $requestKey;
        if ($this->admissionFailure !== null) {
            throw $this->admissionFailure;
        }
        $response = $callback();
        $reason = $response instanceof Response ? ProviderDeferred::reasonForHttpStatus($response->status()) : null;
        if ($reason !== null) {
            throw new ProviderDeferred($reason, now('UTC')->toImmutable()->addSeconds((int) $response->header('Retry-After')), $response->status());
        }

        return $response;
    }
}
