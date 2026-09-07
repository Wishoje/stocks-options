<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Durable publication order; Redis contains only a rollback compatibility mirror. */
class EodPublicationRepository
{
    public static function writesEnabled(): bool
    {
        return (bool) config('eod_publications.write_enabled', false);
    }

    public static function readsEnabled(): bool
    {
        $enabled = (bool) config('eod_publications.read_enabled', false);
        if ($enabled && ! self::writesEnabled()) {
            throw new RuntimeException('Durable publication reads require durable writes.');
        }

        return $enabled;
    }

    /**
     * One indexed query, including the mandatory initialization record. A DB
     * error is never converted into an unpublished version or a Redis fallback.
     *
     * @param  list<string>  $symbols
     * @return array<string,string>
     */
    public function currentMany(string $domain, array $symbols): array
    {
        if ($symbols === []) {
            return [];
        }
        $this->validateDomain($domain);
        if (count($symbols) > 1000) {
            $out = [];
            foreach (array_chunk($symbols, 1000) as $chunk) {
                $out = array_replace($out, $this->currentMany($domain, $chunk));
            }

            return $out;
        }
        $rows = DB::table('eod_cache_publication_state as s')
            ->leftJoin('eod_cache_publications as p', function ($join) use ($domain, $symbols): void {
                $join->where('p.domain', $domain)->whereIn('p.symbol', $symbols);
            })
            ->where('s.id', 1)
            ->get(['s.epoch', 's.cutover_microseconds', 'p.symbol', 'p.version']);
        if ($rows->isEmpty()) {
            throw new RuntimeException('Durable publication heads have not been prepared.');
        }
        $unpublished = $this->unpublished($rows->first());
        $out = array_fill_keys($symbols, $unpublished);
        foreach ($rows as $row) {
            if ($row->symbol !== null && array_key_exists($row->symbol, $out)) {
                $this->validateVersion($row->version);
                $out[$row->symbol] = $row->version;
            }
        }

        return $out;
    }

    /**
     * Initial import only. The operator must have drained legacy writers and
     * captured every legacy head. Missing heads use a new unpublished namespace
     * and a durable floor, never guessed publication times or old initial keys.
     *
     * @param  list<array{domain:string,symbol:string,version:string,issued_at_microseconds:int}>  $heads
     */
    public function prepare(array $heads, int $cutoverMicroseconds): array
    {
        if ($cutoverMicroseconds < 1 || self::writesEnabled() || (bool) config('eod_publications.read_enabled')) {
            throw new RuntimeException('Prepare requires positive ordering and both publication switches disabled.');
        }
        foreach ($heads as $head) {
            if (! is_array($head) || ! isset($head['domain'], $head['symbol'], $head['version'], $head['issued_at_microseconds'])) {
                throw new InvalidArgumentException('Legacy publication metadata is incomplete.');
            }
            $this->validateDomain($head['domain']);
            $this->validateSymbol($head['symbol']);
            $this->validateVersion($head['version']);
            if ($head['version'] === 'initial' || ! is_int($head['issued_at_microseconds']) || $head['issued_at_microseconds'] < 1) {
                throw new InvalidArgumentException('Legacy heads need exact completed tokens and positive issuance order.');
            }
        }

        return DB::transaction(function () use ($heads, $cutoverMicroseconds): array {
            if (DB::table('eod_cache_publication_state')->where('id', 1)->exists()
                || DB::table('eod_cache_publications')->exists()) {
                throw new RuntimeException('Publication import is already prepared or contains unresolved rows; inspect it instead of reinitializing.');
            }
            $epoch = (string) Str::uuid();
            DB::table('eod_cache_publication_state')->insert([
                'id' => 1, 'epoch' => $epoch, 'cutover_microseconds' => $cutoverMicroseconds,
                'prepared_at' => now('UTC'),
            ]);
            $indexed = [];
            foreach ($heads as $head) {
                $key = $head['domain'].':'.$head['symbol'];
                if (! isset($indexed[$key]) || $this->newer($head, $indexed[$key])) {
                    $indexed[$key] = $head;
                }
            }
            // A cache loss must not erase a newer durable GEX certificate.
            foreach (DB::table('eod_snapshot_states')->whereNotNull('certified_version')->orderBy('symbol')->lockForUpdate()->get() as $state) {
                $head = $this->certificate($state);
                if ($head === null) {
                    throw new RuntimeException('A GEX certificate has invalid publication metadata.');
                }
                $key = EodCacheVersion::DOMAIN_GEX.':'.$state->symbol;
                if (! isset($indexed[$key]) || $this->newer($head, $indexed[$key])) {
                    $indexed[$key] = $head;
                }
            }
            ksort($indexed, SORT_STRING);
            foreach (array_chunk(array_values($indexed), 250) as $chunk) {
                DB::table('eod_cache_publications')->insert(array_map(static fn (array $head): array => $head + ['published_at' => now('UTC')], $chunk));
            }

            return ['heads_imported' => count($indexed), 'epoch' => $epoch, 'cutover_microseconds' => $cutoverMicroseconds];
        }, 3);
    }

    /** @return array<string,array<string,string>> */
    public function publish(array $symbols, array $domains, string $token, int $issuedAtMicroseconds): array
    {
        if ($symbols === [] || $domains === []) {
            return [];
        }
        $this->validateVersion($token);
        if ($token === 'initial' || $issuedAtMicroseconds < 1) {
            throw new InvalidArgumentException('Completed publication order is invalid.');
        }
        sort($symbols, SORT_STRING);
        sort($domains, SORT_STRING);
        foreach ($domains as $domain) {
            $this->validateDomain($domain);
        }
        $out = [];
        foreach ($symbols as $symbol) {
            $this->validateSymbol($symbol);
            $versions = DB::transaction(function () use ($symbol, $domains, $token, $issuedAtMicroseconds): array {
                $state = DB::table('eod_cache_publication_state')->where('id', 1)->first();
                if ($state === null) {
                    throw new RuntimeException('Durable publication heads have not been prepared.');
                }
                $unpublished = $this->unpublished($state);
                $now = now('UTC');
                foreach ($domains as $domain) {
                    DB::table('eod_cache_publications')->insertOrIgnore([
                        'domain' => $domain, 'symbol' => $symbol, 'version' => $unpublished,
                        'issued_at_microseconds' => $state->cutover_microseconds, 'published_at' => $now,
                    ]);
                }
                $heads = DB::table('eod_cache_publications')->where('symbol', $symbol)->whereIn('domain', $domains)
                    ->orderBy('domain')->lockForUpdate()->get()->keyBy('domain');
                $certificate = in_array(EodCacheVersion::DOMAIN_GEX, $domains, true)
                    ? $this->certificate(DB::table('eod_snapshot_states')->where('symbol', $symbol)->lockForUpdate()->first())
                    : null;
                $accepted = [];
                foreach ($domains as $domain) {
                    $current = (array) $heads[$domain];
                    $this->validateVersion($current['version']);
                    if ($domain === EodCacheVersion::DOMAIN_GEX && $certificate !== null && $this->newer($certificate, $current)) {
                        $current = $certificate + ['published_at' => $now];
                    }
                    $candidate = ['version' => $token, 'issued_at_microseconds' => $issuedAtMicroseconds];
                    if ($issuedAtMicroseconds > (int) $state->cutover_microseconds && $this->newer($candidate, $current)) {
                        $current = $candidate + ['published_at' => $now];
                    }
                    DB::table('eod_cache_publications')->where('domain', $domain)->where('symbol', $symbol)->update([
                        'version' => $current['version'], 'issued_at_microseconds' => $current['issued_at_microseconds'],
                        'published_at' => $current['published_at'],
                    ]);
                    $accepted[$domain] = $current['version'];
                }
                if (($accepted[EodCacheVersion::DOMAIN_GEX] ?? null) === $token
                    && $issuedAtMicroseconds > (int) $state->cutover_microseconds && EodSnapshotHealth::enabled()) {
                    // Generic heads and any eligible raw certificate commit together.
                    // False means raw work is not certifiable; no certificate is invented.
                    app(EodSnapshotHealth::class)->certify($symbol, $token, $issuedAtMicroseconds);
                }

                return $accepted;
            }, 3);
            foreach ($versions as $domain => $version) {
                $out[$domain][$symbol] = $version;
            }
            // Nested caller transactions must commit before Redis or queue effects.
            DB::afterCommit(function () use ($symbol, $domains): void {
                $this->mirror([$symbol], $domains);
                if (in_array(EodCacheVersion::DOMAIN_GEX, $domains, true) && EodSnapshotHealth::enabled()) {
                    $health = app(EodSnapshotHealth::class);
                    $health->requestRebuild($symbol, $health->policy());
                }
            });
        }

        return $out;
    }

    /**
     * Re-read committed heads under a DB lock. A delayed callback mirrors the
     * latest publication, not its stale captured token. Mirror failure cannot
     * roll back the committed authority; retrying this method repairs it.
     */
    public function mirror(array $symbols, array $domains = EodCacheVersion::ALL_DOMAINS): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Compatibility mirrors require an outermost committed publication.');
        }
        sort($symbols, SORT_STRING);
        sort($domains, SORT_STRING);
        foreach ($symbols as $symbol) {
            DB::transaction(function () use ($symbol, $domains): void {
                $heads = DB::table('eod_cache_publications')->where('symbol', $symbol)->whereIn('domain', $domains)
                    ->orderBy('domain')->lockForUpdate()->get();
                foreach ($heads as $head) {
                    $stored = Cache::forever(app(EodCacheVersion::class)->publicationKey($head->domain, $symbol), [
                        'version' => $head->version, 'issued_at_microseconds' => (int) $head->issued_at_microseconds,
                        'published_at' => $head->published_at,
                    ]);
                    if ($stored === false) {
                        throw new RuntimeException('Committed publication compatibility mirror needs repair.');
                    }
                }
            }, 3);
        }
    }

    private function newer(array $candidate, array $current): bool
    {
        return (int) $candidate['issued_at_microseconds'] > (int) $current['issued_at_microseconds']
            || ((int) $candidate['issued_at_microseconds'] === (int) $current['issued_at_microseconds']
                && strcmp($candidate['version'], $current['version']) > 0);
    }

    private function certificate(?object $state): ?array
    {
        if ($state === null || $state->certified_version === null) {
            return null;
        }
        $this->validateVersion($state->certified_version);
        if ((int) $state->certified_issued_at_microseconds < 1) {
            throw new RuntimeException('A durable GEX certificate has invalid ordering.');
        }

        return ['domain' => EodCacheVersion::DOMAIN_GEX, 'symbol' => $state->symbol,
            'version' => $state->certified_version, 'issued_at_microseconds' => (int) $state->certified_issued_at_microseconds];
    }

    private function unpublished(object $state): string
    {
        if (! is_string($state->epoch) || $state->epoch === '' || (int) $state->cutover_microseconds < 1) {
            throw new RuntimeException('Durable publication initialization is invalid.');
        }

        return 'unpublished:v3:'.$state->epoch;
    }

    private function validateDomain(string $domain): void
    {
        if (! in_array($domain, EodCacheVersion::ALL_DOMAINS, true)) {
            throw new InvalidArgumentException('Unknown EOD publication domain.');
        }
    }

    private function validateSymbol(string $symbol): void
    {
        if ($symbol === '' || strlen($symbol) > 32 || Symbols::canon($symbol) !== $symbol) {
            throw new InvalidArgumentException('Invalid canonical EOD publication symbol.');
        }
    }

    private function validateVersion(mixed $version): void
    {
        if (! is_string($version) || $version === '' || $version === 'initial') {
            throw new InvalidArgumentException('A durable publication version must be nonempty and cannot reuse initial.');
        }
    }
}
