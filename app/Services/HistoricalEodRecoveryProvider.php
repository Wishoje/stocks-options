<?php

namespace App\Services;

use App\Support\ProviderConcurrencyLimiter;
use App\Support\Symbols;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only Massive client used by the one-time historical EOD recovery flow.
 *
 * This client is intentionally separate from normal queue ingestion. Recovery
 * capture writes immutable artifacts only; it never publishes canonical rows.
 */
class HistoricalEodRecoveryProvider
{
    private const REFERENCE_PAGE_LIMIT = 1000;

    private const REFERENCE_MAX_PAGES = 200;

    private const SNAPSHOT_PAGE_LIMIT = 250;

    private const SNAPSHOT_MAX_PAGES = 40;

    /**
     * Fetch the complete contract catalog that existed as of the target date.
     *
     * @return array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function referenceContracts(string $symbol, string $startDate, string $endDate): array
    {
        $symbol = Symbols::canon($symbol);
        if ($symbol === null || $symbol === '') {
            throw new RuntimeException('A valid symbol is required for recovery reference capture.');
        }
        if (! $this->validDate($startDate) || ! $this->validDate($endDate) || $startDate > $endDate) {
            throw new RuntimeException('Recovery reference dates must be a valid YYYY-MM-DD window.');
        }

        [$client, $base, $mode, $qparam, $key] = $this->clientConfiguration();
        $url = "{$base}/v3/reference/options/contracts";
        $params = [
            'underlying_ticker' => $symbol,
            'expiration_date.gte' => $startDate,
            'expiration_date.lte' => $endDate,
            'as_of' => $startDate,
            'order' => 'asc',
            'sort' => 'ticker',
            'limit' => self::REFERENCE_PAGE_LIMIT,
        ];

        $scopeValidator = function (array $contract) use ($symbol, $startDate, $endDate): ?string {
            $underlying = Symbols::canon((string) ($contract['underlying_ticker'] ?? ''));
            $expiry = (string) ($contract['expiration_date'] ?? '');
            $type = strtolower((string) ($contract['contract_type'] ?? ''));
            $ticker = strtoupper(trim((string) ($contract['ticker'] ?? '')));
            $strike = (float) ($contract['strike_price'] ?? 0);

            if ($underlying !== $symbol) {
                return 'wrong_underlying';
            }
            if ($expiry < $startDate || $expiry > $endDate) {
                return 'wrong_expiration';
            }
            if (! in_array($type, ['call', 'put'], true)) {
                return 'wrong_contract_type';
            }
            if ($ticker === '' || $strike <= 0) {
                return 'invalid_contract_identity';
            }

            return null;
        };
        $identityResolver = static fn (array $contract): ?string => strtoupper(trim((string) ($contract['ticker'] ?? ''))) ?: null;

        // `expired` is a filter, not an "include expired" switch. Query both
        // states at the same historical as_of point and require both paginations
        // to finish. The union avoids depending on provider-specific treatment
        // of contracts expiring on the as_of date.
        $catalogs = [];
        foreach ([true, false] as $expired) {
            $catalogs[$expired ? 'expired' : 'active'] = $this->paginate(
                client: $client,
                base: $base,
                firstUrl: $url,
                firstParams: array_merge($params, ['expired' => $expired ? 'true' : 'false']),
                mode: $mode,
                qparam: $qparam,
                key: $key,
                maxPages: max(1, (int) config(
                    'services.massive.eod_chain_reference_max_pages',
                    self::REFERENCE_MAX_PAGES,
                )),
                scopeValidator: $scopeValidator,
                identityResolver: $identityResolver,
            );
        }

        $contracts = [];
        foreach ($catalogs as $catalog) {
            foreach ($catalog['contracts'] as $contract) {
                $identity = $identityResolver($contract);
                if (
                    isset($contracts[$identity])
                    && $this->stablePayload($contracts[$identity]) !== $this->stablePayload($contract)
                ) {
                    throw new RuntimeException("Recovery reference catalogs conflict for {$identity}.");
                }
                $contracts[$identity] = $contract;
            }
        }
        ksort($contracts);

        return [
            'contracts' => array_values($contracts),
            'meta' => [
                'complete' => true,
                'contracts_unique' => count($contracts),
                'catalogs' => array_map(
                    static fn (array $catalog): array => $catalog['meta'],
                    $catalogs,
                ),
            ],
        ];
    }

    /**
     * Fetch one exact future expiration/side from the current weekend snapshot.
     *
     * @return array{contracts:array<int,array<string,mixed>>,spot:?float,meta:array<string,mixed>}
     */
    public function snapshotPartition(string $symbol, string $expiry, string $contractType): array
    {
        $symbol = Symbols::canon($symbol);
        $contractType = strtolower(trim($contractType));
        if ($symbol === null || $symbol === '') {
            throw new RuntimeException('A valid symbol is required for recovery snapshot capture.');
        }
        if (! in_array($contractType, ['call', 'put'], true)) {
            throw new RuntimeException("Unsupported recovery contract type [{$contractType}].");
        }
        if (! $this->validDate($expiry)) {
            throw new RuntimeException('Recovery snapshot expiration must use YYYY-MM-DD.');
        }

        [$client, $base, $mode, $qparam, $key] = $this->clientConfiguration();
        $url = "{$base}/v3/snapshot/options/{$symbol}";
        $params = [
            'expiration_date' => $expiry,
            'contract_type' => $contractType,
            'limit' => self::SNAPSHOT_PAGE_LIMIT,
        ];

        $result = $this->paginate(
            client: $client,
            base: $base,
            firstUrl: $url,
            firstParams: $params,
            mode: $mode,
            qparam: $qparam,
            key: $key,
            maxPages: max(1, (int) config(
                'services.massive.eod_chain_max_pages_per_partition',
                self::SNAPSHOT_MAX_PAGES,
            )),
            scopeValidator: function (array $contract) use ($symbol, $expiry, $contractType): ?string {
                $details = $contract['details'] ?? null;
                if (! is_array($details)) {
                    return 'missing_details';
                }

                $actualExpiry = (string) ($details['expiration_date'] ?? '');
                $actualType = strtolower((string) ($details['contract_type'] ?? ''));
                $underlying = Symbols::canon((string) (
                    $contract['underlying_asset']['ticker']
                        ?? $details['underlying_ticker']
                        ?? ''
                ));
                $ticker = strtoupper(trim((string) ($details['ticker'] ?? '')));
                $strike = (float) ($details['strike_price'] ?? 0);

                if ($actualExpiry !== $expiry) {
                    return 'wrong_expiration';
                }
                if ($actualType !== $contractType) {
                    return 'wrong_contract_type';
                }
                if ($underlying !== $symbol) {
                    return 'wrong_underlying';
                }
                if ($ticker === '' || $strike <= 0) {
                    return 'invalid_contract_identity';
                }

                return null;
            },
            identityResolver: static fn (array $contract): ?string => strtoupper(trim((string) ($contract['details']['ticker'] ?? ''))) ?: null,
        );

        $spots = [];
        foreach ($result['contracts'] as $contract) {
            $candidate = (float) ($contract['underlying_asset']['price'] ?? 0);
            if ($candidate > 0) {
                $spots[] = $candidate;
            }
        }
        $spot = null;
        if ($spots !== []) {
            $spot = $spots[0];
            foreach ($spots as $candidate) {
                if (abs($candidate - $spot) / $spot > 0.0001) {
                    throw new RuntimeException("Recovery snapshot has inconsistent underlying prices for {$symbol}.");
                }
            }
        }

        return [
            'contracts' => $result['contracts'],
            'spot' => $spot,
            'meta' => $result['meta'],
        ];
    }

    /**
     * @param  callable(array<string,mixed>):?string  $scopeValidator
     * @param  callable(array<string,mixed>):?string  $identityResolver
     * @return array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    private function paginate(
        PendingRequest $client,
        string $base,
        string $firstUrl,
        array $firstParams,
        string $mode,
        string $qparam,
        string $key,
        int $maxPages,
        callable $scopeValidator,
        callable $identityResolver,
    ): array {
        $endpointUrl = $this->safeProviderUrl($firstUrl, $base);
        $url = $endpointUrl;
        $params = $firstParams;
        $contracts = [];
        $seenUrls = [];
        $seenContracts = [];
        $pages = 0;
        $duplicates = 0;
        $lastHttpStatus = null;
        $requestIds = [];

        while ($url !== null) {
            if ($pages >= $maxPages) {
                throw new RuntimeException("Recovery provider pagination exceeded {$maxPages} pages.");
            }

            $url = $this->safeProviderUrl($url, $base);

            $requestParams = $params;
            if ($mode === 'query') {
                $requestParams[$qparam] = $key;
            }

            $fingerprint = hash(
                'sha256',
                $url."\n".$this->stablePayload($params),
            );
            if (isset($seenUrls[$fingerprint])) {
                throw new RuntimeException('Recovery provider returned a cursor cycle.');
            }
            $seenUrls[$fingerprint] = true;

            $pages++;
            $response = app(ProviderConcurrencyLimiter::class)->massive(
                fn () => $client->get($url, $requestParams)
            );
            $lastHttpStatus = $response->status();

            if ($response->status() === 401) {
                throw new RuntimeException('Recovery provider request was unauthorized.');
            }
            if ($response->status() === 429) {
                throw new RuntimeException('Recovery provider request was rate limited.');
            }
            if ($response->failed()) {
                throw new RuntimeException("Recovery provider request failed with HTTP {$response->status()}.");
            }

            $json = $response->json();
            if (! is_array($json) || ! array_key_exists('results', $json) || ! is_array($json['results'])) {
                throw new RuntimeException('Recovery provider returned a malformed payload.');
            }
            $providerStatus = strtoupper(trim((string) ($json['status'] ?? '')));
            if ($providerStatus !== 'OK') {
                throw new RuntimeException("Recovery provider returned status [{$providerStatus}].");
            }

            if (($json['request_id'] ?? null) !== null) {
                $requestIds[(string) $json['request_id']] = true;
            }

            $newContracts = 0;
            foreach ($json['results'] as $contract) {
                if (! is_array($contract)) {
                    throw new RuntimeException('Recovery provider returned a non-object contract.');
                }

                $scopeError = $scopeValidator($contract);
                if ($scopeError !== null) {
                    throw new RuntimeException("Recovery provider scope violation: {$scopeError}.");
                }

                $identity = $identityResolver($contract);
                if ($identity === null || $identity === '') {
                    throw new RuntimeException('Recovery provider contract identity was empty.');
                }
                if (isset($seenContracts[$identity])) {
                    throw new RuntimeException("Recovery provider returned duplicate contract {$identity}.");
                }

                $seenContracts[$identity] = true;
                $contracts[] = $contract;
                $newContracts++;
            }

            $next = trim((string) ($json['next_url'] ?? ''));
            if ($next === '') {
                $url = null;
                break;
            }
            if ($newContracts === 0) {
                throw new RuntimeException('Recovery provider pagination made no progress.');
            }

            $cursor = $this->trustedCursor(
                $next,
                $base,
                $endpointUrl,
                $firstParams,
                $qparam,
            );
            $url = $endpointUrl;
            $params = array_merge($firstParams, ['cursor' => $cursor]);
        }

        return [
            'contracts' => $contracts,
            'meta' => [
                'complete' => true,
                'pages' => $pages,
                'http_status' => $lastHttpStatus,
                'contracts_unique' => count($contracts),
                'duplicate_contracts' => $duplicates,
                'request_ids' => array_keys($requestIds),
            ],
        ];
    }

    /** @param array<string,mixed> $scopeParams */
    private function trustedCursor(
        string $nextUrl,
        string $base,
        string $endpointUrl,
        array $scopeParams,
        string $queryCredential,
    ): string {
        if (str_starts_with($nextUrl, '?')) {
            $nextUrl = $endpointUrl.$nextUrl;
        }
        $nextUrl = $this->safeProviderUrl($nextUrl, $base);
        $parts = parse_url($nextUrl);
        if (
            ! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('Recovery provider returned an unsafe cursor URL.');
        }

        $expectedPath = (string) parse_url($endpointUrl, PHP_URL_PATH);
        $actualPath = (string) ($parts['path'] ?? '');
        if ($expectedPath === '' || $actualPath !== $expectedPath) {
            throw new RuntimeException('Recovery provider returned a cursor for an unexpected endpoint.');
        }

        $cursor = null;
        $seen = [];
        $allowed = array_fill_keys(array_keys($scopeParams), true);
        $allowed[$queryCredential] = true;
        $allowed['cursor'] = true;
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$encodedKey, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode($encodedKey);
            $value = rawurldecode($encodedValue);
            if ($name === '' || isset($seen[$name]) || ! isset($allowed[$name])) {
                throw new RuntimeException('Recovery provider returned an invalid cursor query.');
            }
            $seen[$name] = true;

            if ($name === 'cursor') {
                $cursor = $value;

                continue;
            }
            if ($name !== $queryCredential && (string) $scopeParams[$name] !== $value) {
                throw new RuntimeException('Recovery provider cursor changed the requested scope.');
            }
        }

        if (! is_string($cursor) || trim($cursor) === '') {
            throw new RuntimeException('Recovery provider returned a malformed cursor.');
        }

        return $cursor;
    }

    /** @return array{0:PendingRequest,1:string,2:string,3:string,4:string} */
    private function clientConfiguration(): array
    {
        $base = rtrim((string) config('services.massive.base', 'https://api.massive.com'), '/');
        $key = (string) config('services.massive.key', '');
        $mode = (string) config('services.massive.mode', 'header');
        $header = (string) config('services.massive.header', 'X-API-Key');
        $qparam = (string) config('services.massive.qparam', 'apiKey');

        if ($key === '') {
            throw new RuntimeException('MASSIVE_API_KEY is required for recovery capture.');
        }
        if (! in_array($mode, ['header', 'bearer', 'query'], true)) {
            throw new RuntimeException("Unsupported MASSIVE_AUTH_MODE [{$mode}].");
        }

        $client = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->retry(2, 300, throw: false);

        if ($mode === 'bearer') {
            $client = $client->withToken($key);
        } elseif ($mode === 'header') {
            $client = $client->withHeaders([$header => $key]);
        }

        return [$client, $base, $mode, $qparam, $key];
    }

    private function safeProviderUrl(string $url, string $base): string
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = $base.'/'.ltrim($url, '/');
        }

        $expected = parse_url($base);
        $actual = parse_url($url);
        if (
            ! is_array($expected)
            || ! is_array($actual)
            || strtolower((string) ($expected['scheme'] ?? '')) !== strtolower((string) ($actual['scheme'] ?? ''))
            || strtolower((string) ($expected['host'] ?? '')) !== strtolower((string) ($actual['host'] ?? ''))
            || (int) ($expected['port'] ?? 0) !== (int) ($actual['port'] ?? 0)
        ) {
            throw new RuntimeException('Recovery provider returned an untrusted next_url.');
        }

        return $url;
    }

    private function stablePayload(array $payload): string
    {
        ksort($payload);

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
