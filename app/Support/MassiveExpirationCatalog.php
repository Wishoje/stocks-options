<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Discover one immutable Massive expiration catalog without trusting provider
 * continuation URLs as requests.
 */
final class MassiveExpirationCatalog
{
    private const ENDPOINT = '/v3/reference/options/contracts';

    private const PAGE_LIMIT = 1000;

    /**
     * @return array{
     *     expirations:list<string>,
     *     meta:array{
     *         complete:bool,
     *         status:string,
     *         pages:int,
     *         capped:bool,
     *         source:string,
     *         http_status:?int
     *     }
     * }
     */
    public function discover(string $symbol, string $sessionDate, int $horizonDays): array
    {
        $symbol = Symbols::canon($symbol);
        $session = $this->date($sessionDate);
        if (! Symbols::isValid($symbol) || $session === null || $horizonDays < 0 || $horizonDays > 366) {
            return $this->failure('invalid_request');
        }

        $configuration = $this->clientConfiguration();
        if (isset($configuration['failure'])) {
            return $this->failure((string) $configuration['failure']);
        }

        /** @var PendingRequest $client */
        $client = $configuration['client'];
        $base = (string) $configuration['base'];
        $mode = (string) $configuration['mode'];
        $queryCredential = (string) $configuration['query_credential'];
        $key = (string) $configuration['key'];
        $endDate = $session->addDays($horizonDays)->toDateString();
        $scope = $this->scope($symbol, $session->toDateString(), $endDate);

        $bounded = $this->boundedCatalog(
            $client,
            $base,
            $mode,
            $queryCredential,
            $key,
            $scope,
            max(1, (int) config('services.massive.eod_chain_reference_probe_max_pages', 4))
        );

        if ($bounded['meta']['complete']) {
            return $bounded;
        }

        if (! in_array($bounded['meta']['status'], [
            'pagination_capped',
            'cursor_cycle',
            'pagination_no_progress',
        ], true)) {
            return [
                'expirations' => [],
                'meta' => $bounded['meta'],
            ];
        }

        $fallback = $this->exactDateCatalog(
            $client,
            $base,
            $mode,
            $queryCredential,
            $key,
            $symbol,
            $session,
            $horizonDays
        );
        $fallback['meta']['pages'] += $bounded['meta']['pages'];
        $fallback['meta']['bounded_status'] = $bounded['meta']['status'];
        $fallback['meta']['bounded_pages'] = $bounded['meta']['pages'];
        $fallback['meta']['bounded_capped'] = $bounded['meta']['capped'];

        return $fallback;
    }

    /**
     * @param  array<string,scalar>  $scope
     * @return array{expirations:list<string>,meta:array<string,mixed>}
     */
    private function boundedCatalog(
        PendingRequest $client,
        string $base,
        string $mode,
        string $queryCredential,
        string $key,
        array $scope,
        int $maxPages
    ): array {
        $endpoint = $base.self::ENDPOINT;
        $cursor = null;
        $expirations = [];
        $requested = [];
        $pages = 0;

        while (true) {
            if ($pages >= $maxPages) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'pagination_capped',
                    pages: $pages,
                    capped: true,
                    source: 'bounded_catalog'
                ));
            }

            $params = $scope;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }
            $fingerprint = $this->requestFingerprint($endpoint, $params);
            if (isset($requested[$fingerprint])) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'cursor_cycle',
                    pages: $pages,
                    source: 'bounded_catalog'
                ));
            }
            $requested[$fingerprint] = true;
            $pages++;

            $response = $this->request(
                $client,
                $endpoint,
                $params,
                $mode,
                $queryCredential,
                $key
            );
            $failure = $this->responseFailure($response, $pages, 'bounded_catalog');
            if ($failure !== null) {
                return $failure;
            }

            $json = $this->json($response);
            if ($json === null) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'malformed_payload',
                    pages: $pages,
                    source: 'bounded_catalog',
                    httpStatus: $response->status()
                ));
            }

            $next = $json['next_url'] ?? null;
            if ($next !== null && ! is_string($next)) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'malformed_payload',
                    pages: $pages,
                    source: 'bounded_catalog',
                    httpStatus: $response->status()
                ));
            }

            $nextCursor = null;
            if (is_string($next) && trim($next) !== '') {
                try {
                    $nextCursor = $this->trustedCursor(
                        trim($next),
                        $base,
                        $endpoint,
                        $scope,
                        $queryCredential
                    );
                } catch (RuntimeException) {
                    return $this->catalogResult([], $this->meta(
                        complete: false,
                        status: 'cursor_scope_violation',
                        pages: $pages,
                        source: 'bounded_catalog',
                        httpStatus: $response->status()
                    ));
                }
            }

            $batch = $json['results'];
            if ($batch === [] && $nextCursor !== null) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'pagination_no_progress',
                    pages: $pages,
                    source: 'bounded_catalog',
                    httpStatus: $response->status()
                ));
            }

            foreach ($batch as $contract) {
                $expiration = $this->expirationFrom(
                    $contract,
                    (string) $scope['underlying_ticker'],
                    (string) $scope['expiration_date.gte'],
                    (string) $scope['expiration_date.lte']
                );
                if ($expiration === null) {
                    return $this->catalogResult([], $this->meta(
                        complete: false,
                        status: 'scope_violation',
                        pages: $pages,
                        source: 'bounded_catalog',
                        httpStatus: $response->status()
                    ));
                }
                $expirations[$expiration] = true;
            }

            if ($nextCursor === null) {
                $dates = array_keys($expirations);
                sort($dates, SORT_STRING);

                return $this->catalogResult($dates, $this->meta(
                    complete: true,
                    status: $dates === [] ? 'empty_catalog' : 'ok',
                    pages: $pages,
                    source: 'bounded_catalog',
                    httpStatus: $response->status()
                ));
            }

            $nextFingerprint = $this->requestFingerprint(
                $endpoint,
                array_merge($scope, ['cursor' => $nextCursor])
            );
            if (isset($requested[$nextFingerprint])) {
                return $this->catalogResult([], $this->meta(
                    complete: false,
                    status: 'cursor_cycle',
                    pages: $pages,
                    source: 'bounded_catalog',
                    httpStatus: $response->status()
                ));
            }

            $cursor = $nextCursor;
        }
    }

    /** @return array{expirations:list<string>,meta:array<string,mixed>} */
    private function exactDateCatalog(
        PendingRequest $client,
        string $base,
        string $mode,
        string $queryCredential,
        string $key,
        string $symbol,
        CarbonImmutable $session,
        int $horizonDays
    ): array {
        $endpoint = $base.self::ENDPOINT;
        $expirations = [];
        $pages = 0;

        for ($offset = 0; $offset <= $horizonDays; $offset++) {
            $candidate = $session->addDays($offset)->toDateString();
            $params = $this->scope($symbol, $candidate, $candidate);
            $params['as_of'] = $session->toDateString();
            $params['limit'] = 1;
            $pages++;

            $response = $this->request(
                $client,
                $endpoint,
                $params,
                $mode,
                $queryCredential,
                $key
            );
            $failure = $this->responseFailure($response, $pages, 'exact_date_fallback');
            if ($failure !== null) {
                $failure['meta']['dates_scanned'] = $pages;

                return $failure;
            }

            $json = $this->json($response);
            if ($json === null) {
                $meta = $this->meta(
                    complete: false,
                    status: 'malformed_payload',
                    pages: $pages,
                    source: 'exact_date_fallback',
                    httpStatus: $response->status()
                );
                $meta['dates_scanned'] = $pages;

                return $this->catalogResult([], $meta);
            }

            if ($json['results'] === [] && ! empty($json['next_url'] ?? null)) {
                $meta = $this->meta(
                    complete: false,
                    status: 'pagination_no_progress',
                    pages: $pages,
                    source: 'exact_date_fallback',
                    httpStatus: $response->status()
                );
                $meta['dates_scanned'] = $pages;

                return $this->catalogResult([], $meta);
            }

            foreach ($json['results'] as $contract) {
                $expiration = $this->expirationFrom($contract, $symbol, $candidate, $candidate);
                if ($expiration === null) {
                    $meta = $this->meta(
                        complete: false,
                        status: 'scope_violation',
                        pages: $pages,
                        source: 'exact_date_fallback',
                        httpStatus: $response->status()
                    );
                    $meta['dates_scanned'] = $pages;

                    return $this->catalogResult([], $meta);
                }
                $expirations[$expiration] = true;
            }
        }

        $dates = array_keys($expirations);
        sort($dates, SORT_STRING);
        $meta = $this->meta(
            complete: true,
            status: $dates === [] ? 'empty_catalog' : 'ok',
            pages: $pages,
            source: 'exact_date_fallback'
        );
        $meta['dates_scanned'] = $pages;

        return $this->catalogResult($dates, $meta);
    }

    /** @return array<string,mixed>|null */
    private function json(Response $response): ?array
    {
        try {
            $json = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($json)
            || ! array_key_exists('results', $json)
            || ! is_array($json['results'])
            || ! array_is_list($json['results'])
            || (array_key_exists('next_url', $json)
                && $json['next_url'] !== null
                && ! is_string($json['next_url']))) {
            return null;
        }

        $providerStatus = strtoupper(trim((string) ($json['status'] ?? 'OK')));
        if ($providerStatus !== 'OK') {
            return null;
        }

        return $json;
    }

    /** @return array{expirations:list<string>,meta:array<string,mixed>}|null */
    private function responseFailure(Response $response, int $pages, string $source): ?array
    {
        $status = $response->status();
        $failure = match (true) {
            $status === 401, $status === 403 => 'unauthorized',
            $status === 429 => 'rate_limited',
            $response->failed() => 'http_error',
            default => null,
        };

        return $failure === null
            ? null
            : $this->catalogResult([], $this->meta(
                complete: false,
                status: $failure,
                pages: $pages,
                source: $source,
                httpStatus: $status
            ));
    }

    /** @param array<string,scalar> $params */
    private function request(
        PendingRequest $client,
        string $endpoint,
        array $params,
        string $mode,
        string $queryCredential,
        string $key
    ): Response {
        if ($mode === 'query') {
            $params[$queryCredential] = $key;
        }

        return app(ProviderConcurrencyLimiter::class)->massive(
            fn (): Response => $client->get($endpoint, $params)
        );
    }

    /**
     * @param  array<string,scalar>  $scope
     */
    private function trustedCursor(
        string $nextUrl,
        string $base,
        string $endpoint,
        array $scope,
        string $queryCredential
    ): string {
        if (str_starts_with($nextUrl, '?')) {
            $nextUrl = $endpoint.$nextUrl;
        } elseif (! str_starts_with($nextUrl, 'http://') && ! str_starts_with($nextUrl, 'https://')) {
            $nextUrl = rtrim($base, '/').'/'.ltrim($nextUrl, '/');
        }

        $expected = parse_url($base);
        $actual = parse_url($nextUrl);
        if (! is_array($expected)
            || ! is_array($actual)
            || isset($actual['user'])
            || isset($actual['pass'])
            || isset($actual['fragment'])
            || strtolower((string) ($expected['scheme'] ?? '')) !== strtolower((string) ($actual['scheme'] ?? ''))
            || strtolower((string) ($expected['host'] ?? '')) !== strtolower((string) ($actual['host'] ?? ''))
            || $this->effectivePort($expected) !== $this->effectivePort($actual)
            || (string) parse_url($endpoint, PHP_URL_PATH) !== (string) ($actual['path'] ?? '')) {
            throw new RuntimeException('Massive returned an untrusted catalog cursor.');
        }

        $cursor = null;
        $seen = [];
        $allowed = array_fill_keys(array_keys($scope), true);
        $allowed[$queryCredential] = true;
        $allowed['cursor'] = true;

        foreach (explode('&', (string) ($actual['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($encodedName);
            $value = rawurldecode($encodedValue);
            if ($name === '' || isset($seen[$name]) || ! isset($allowed[$name])) {
                throw new RuntimeException('Massive returned an invalid catalog cursor query.');
            }
            $seen[$name] = true;

            if ($name === 'cursor') {
                $cursor = $value;
            } elseif ($name !== $queryCredential && (string) $scope[$name] !== $value) {
                throw new RuntimeException('Massive changed the catalog cursor scope.');
            }
        }

        if (! is_string($cursor) || trim($cursor) === '') {
            throw new RuntimeException('Massive returned a malformed catalog cursor.');
        }

        return trim($cursor);
    }

    /** @param array<string,mixed> $parts */
    private function effectivePort(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    private function expirationFrom(mixed $contract, string $symbol, string $startDate, string $endDate): ?string
    {
        if (! is_array($contract)) {
            return null;
        }

        $expiration = substr(trim((string) ($contract['expiration_date'] ?? '')), 0, 10);
        $underlying = trim((string) ($contract['underlying_ticker'] ?? ''));

        return $this->date($expiration) !== null
            && $expiration >= $startDate
            && $expiration <= $endDate
            && ($underlying === '' || Symbols::canon($underlying) === $symbol)
                ? $expiration
                : null;
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'America/New_York');
        } catch (Throwable) {
            return null;
        }

        return $date !== false && $date->toDateString() === trim($value) ? $date : null;
    }

    /** @return array<string,scalar> */
    private function scope(string $symbol, string $startDate, string $endDate): array
    {
        return [
            'underlying_ticker' => $symbol,
            'expiration_date.gte' => $startDate,
            'expiration_date.lte' => $endDate,
            'as_of' => $startDate,
            'order' => 'asc',
            'sort' => 'expiration_date',
            'limit' => self::PAGE_LIMIT,
        ];
    }

    /** @param array<string,scalar> $params */
    private function requestFingerprint(string $endpoint, array $params): string
    {
        ksort($params, SORT_STRING);

        return hash('sha256', $endpoint."\n".json_encode(
            $params,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    /** @return array<string,mixed> */
    private function clientConfiguration(): array
    {
        $base = rtrim((string) config('services.massive.base', 'https://api.massive.com'), '/');
        $key = (string) config('services.massive.key', '');
        $mode = (string) config('services.massive.mode', 'header');
        $header = (string) config('services.massive.header', 'X-API-Key');
        $queryCredential = (string) config('services.massive.qparam', 'apiKey');

        if ($key === '') {
            return ['failure' => 'missing_api_key'];
        }
        if (! in_array($mode, ['header', 'bearer', 'query'], true)
            || filter_var($base, FILTER_VALIDATE_URL) === false
            || $queryCredential === '') {
            return ['failure' => 'invalid_configuration'];
        }

        $client = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 300, throw: false);
        if ($mode === 'bearer') {
            $client = $client->withToken($key);
        } elseif ($mode === 'header') {
            if ($header === '') {
                return ['failure' => 'invalid_configuration'];
            }
            $client = $client->withHeaders([$header => $key]);
        }

        return [
            'client' => $client,
            'base' => $base,
            'mode' => $mode,
            'query_credential' => $queryCredential,
            'key' => $key,
        ];
    }

    /** @return array{expirations:list<string>,meta:array<string,mixed>} */
    private function failure(string $status): array
    {
        return $this->catalogResult([], $this->meta(
            complete: false,
            status: $status,
            pages: 0,
            source: 'bounded_catalog'
        ));
    }

    /** @param array<string,mixed> $meta */
    private function catalogResult(array $expirations, array $meta): array
    {
        return [
            'expirations' => array_values($expirations),
            'meta' => $meta,
        ];
    }

    /** @return array<string,mixed> */
    private function meta(
        bool $complete,
        string $status,
        int $pages,
        bool $capped = false,
        string $source = 'bounded_catalog',
        ?int $httpStatus = null
    ): array {
        return [
            'complete' => $complete,
            'status' => $status,
            'pages' => $pages,
            'capped' => $capped,
            'source' => $source,
            'http_status' => $httpStatus,
        ];
    }
}
