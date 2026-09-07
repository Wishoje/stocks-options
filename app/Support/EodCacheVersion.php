<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Publication fences for EOD responses derived from a symbol's chain.
 *
 * Writers advance only the domains they completed. Payloads from the previous
 * version remain readable until their normal TTL, avoiding wildcard deletes
 * and preserving unrelated cache entries and distributed locks.
 */
final class EodCacheVersion
{
    public const DOMAIN_GEX = 'gex';

    public const DOMAIN_VOLATILITY = 'volatility';

    public const DOMAIN_EXPIRY_PRESSURE = 'expiry-pressure';

    public const DOMAIN_ACTIVITY = 'activity';

    public const ALL_DOMAINS = [
        self::DOMAIN_GEX,
        self::DOMAIN_VOLATILITY,
        self::DOMAIN_EXPIRY_PRESSURE,
        self::DOMAIN_ACTIVITY,
    ];

    private const INITIAL_VERSION = 'initial';

    private const VERSION_KEY_PREFIX = 'eod:cache-version:v2:';

    private const LOCK_KEY_PREFIX = 'eod:cache-version-lock:v2:';

    public function current(string $domain, string $symbol): string
    {
        $domain = $this->domain($domain);
        $symbol = Symbols::canon($symbol);
        if ($symbol === '') {
            return self::INITIAL_VERSION;
        }

        if (EodPublicationRepository::readsEnabled()) {
            return app(EodPublicationRepository::class)->currentMany($domain, [$symbol])[$symbol];
        }

        $version = $this->versionFrom(Cache::get($this->publicationKey($domain, $symbol)));
        if ($version === self::INITIAL_VERSION && EodPublicationRepository::writesEnabled()) {
            // A temporary mirror-read rollback must not resurrect old initial
            // payloads for symbols that never had a completed legacy head.
            return app(EodPublicationRepository::class)->currentMany($domain, [$symbol])[$symbol];
        }

        return $version;
    }

    /**
     * Publish the supplied generation if it is newer than the current one.
     *
     * A stable token and issuance time should be serialized with queued work.
     * Replays are idempotent, and a delayed older finalizer cannot roll a
     * symbol back from a newer generation.
     *
     * @param  iterable<int, string>  $symbols
     * @param  iterable<int, string>  $domains
     * @return array<string, array<string, string>> domain => symbol => version
     */
    public function publish(
        iterable $symbols,
        iterable $domains = self::ALL_DOMAINS,
        ?string $publicationToken = null,
        ?int $issuedAtMicroseconds = null
    ): array {
        $canonical = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $domains = $this->domains($domains);
        $publicationToken = trim((string) $publicationToken) ?: (string) Str::orderedUuid();
        $issuedAtMicroseconds ??= (int) floor(microtime(true) * 1_000_000);

        EodPublicationRepository::readsEnabled(); // Reject an unsafe read-on/write-off configuration.
        if (EodPublicationRepository::writesEnabled()) {
            return app(EodPublicationRepository::class)->publish(
                $canonical->all(), $domains, $publicationToken, $issuedAtMicroseconds
            );
        }

        $published = [];
        foreach ($domains as $domain) {
            foreach ($canonical as $symbol) {
                $published[$domain][$symbol] = $this->publishOne(
                    $domain,
                    $symbol,
                    $publicationToken,
                    $issuedAtMicroseconds
                );
            }
        }

        if (EodSnapshotHealth::enabled()) {
            $health = app(EodSnapshotHealth::class);
            foreach ($published[self::DOMAIN_GEX] ?? [] as $symbol => $version) {
                // The Redis fence may have rejected an older finalizer. Only
                // certify the requested token when it is still the accepted
                // publication. The database independently enforces ordering
                // and refuses active, failed, or unobserved raw mutations.
                if ($version === $publicationToken
                    && $health->certify($symbol, $version, $issuedAtMicroseconds)) {
                    $health->requestRebuild($symbol, $health->policy());
                }
            }
        }

        return $published;
    }

    public function key(string $namespace, string $domain, string $symbol, string ...$parts): string
    {
        $symbol = Symbols::canon($symbol);
        $segments = array_merge([$namespace, $symbol, $this->current($domain, $symbol)], $parts);

        return implode(':', array_map(
            static fn ($part): string => (string) $part,
            $segments
        ));
    }

    /**
     * A stable version for a response containing many symbols.
     *
     * @param  iterable<int, string>  $symbols
     */
    public function signature(string $domain, iterable $symbols): string
    {
        return substr(hash('sha256', json_encode(
            $this->currentMany($domain, $symbols),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        )), 0, 24);
    }

    /**
     * Legacy reads use one cache round trip. Durable reads use one metadata
     * query per internal 1,000-symbol unit, without changing request admission.
     *
     * @param  iterable<int, string>  $symbols
     * @return array<string, string>
     */
    public function currentMany(string $domain, iterable $symbols): array
    {
        $domain = $this->domain($domain);
        $canonical = collect($symbols)
            ->map(static fn ($symbol): string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if (EodPublicationRepository::readsEnabled()) {
            return app(EodPublicationRepository::class)->currentMany($domain, $canonical->all());
        }

        $publicationKeys = $canonical->mapWithKeys(
            fn (string $symbol): array => [$symbol => $this->publicationKey($domain, $symbol)]
        );
        $publications = Cache::many($publicationKeys->values()->all());

        $versions = $publicationKeys->mapWithKeys(fn (string $key, string $symbol): array => [
            $symbol => $this->versionFrom($publications[$key] ?? null),
        ])->all();
        if (EodPublicationRepository::writesEnabled()) {
            $missing = array_keys(array_filter($versions, static fn (string $version): bool => $version === self::INITIAL_VERSION));
            if ($missing !== []) {
                $versions = array_replace($versions, app(EodPublicationRepository::class)->currentMany($domain, $missing));
            }
        }

        return $versions;
    }

    public function publicationKey(string $domain, string $symbol): string
    {
        return self::VERSION_KEY_PREFIX.$this->domain($domain).':'.Symbols::canon($symbol);
    }

    private function publishOne(
        string $domain,
        string $symbol,
        string $publicationToken,
        int $issuedAtMicroseconds
    ): string {
        $key = $this->publicationKey($domain, $symbol);
        $lock = Cache::lock(self::LOCK_KEY_PREFIX.$domain.':'.$symbol, 10);

        return $lock->block(5, function () use (
            $key,
            $publicationToken,
            $issuedAtMicroseconds
        ): string {
            $current = Cache::get($key);
            if ($this->isNewerOrEqual($current, $publicationToken, $issuedAtMicroseconds)) {
                return $this->versionFrom($current);
            }

            $stored = Cache::forever($key, [
                'version' => $publicationToken,
                'issued_at_microseconds' => $issuedAtMicroseconds,
                'published_at' => now()->toIso8601String(),
            ]);

            if ($stored === false) {
                throw new RuntimeException("Unable to publish EOD cache version [{$key}].");
            }

            return $publicationToken;
        });
    }

    private function isNewerOrEqual(mixed $current, string $token, int $issuedAtMicroseconds): bool
    {
        if (! is_array($current)) {
            return false;
        }

        $currentIssuedAt = (int) ($current['issued_at_microseconds'] ?? 0);
        $currentToken = (string) ($current['version'] ?? '');

        return $currentIssuedAt > $issuedAtMicroseconds
            || ($currentIssuedAt === $issuedAtMicroseconds && strcmp($currentToken, $token) >= 0);
    }

    private function versionFrom(mixed $publication): string
    {
        $version = is_array($publication) ? ($publication['version'] ?? null) : $publication;

        return is_string($version) && $version !== ''
            ? $version
            : self::INITIAL_VERSION;
    }

    /** @return string[] */
    private function domains(iterable $domains): array
    {
        return collect($domains)
            ->map(fn ($domain): string => $this->domain((string) $domain))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function domain(string $domain): string
    {
        if (! in_array($domain, self::ALL_DOMAINS, true)) {
            throw new InvalidArgumentException("Unknown EOD cache domain [{$domain}].");
        }

        return $domain;
    }
}
