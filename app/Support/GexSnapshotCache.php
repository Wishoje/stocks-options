<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Response payloads are separate from durable publication/health records. */
final class GexSnapshotCache
{
    private const COMPATIBILITY_PREFIX = 'gex:levels:compat:v1:';

    /**
     * A short-lived legacy response is not a certified manifest. Raw state is
     * read on every attempt: head() alone cannot distinguish absent history
     * from an active first tracked write that is not certified yet.
     *
     * @return array{key:string,cache_version:string}|null
     */
    public function compatibilityContext(string $symbol, string $timeframe, array $policy, ?CarbonInterface $at = null): ?array
    {
        if (! EodSnapshotHealth::readsEnabled()) {
            return null;
        }
        try {
            $state = DB::table('eod_snapshot_states')->where('symbol', $symbol)->first([
                'revision', 'certified_revision', 'certified_version', 'certified_issued_at_microseconds',
            ]);
            if ($state === null) {
                $stateIdentity = ['state' => 'absent'];
            } elseif ((int) $state->revision > 0 && (int) $state->revision === (int) $state->certified_revision
                && is_string($state->certified_version) && $state->certified_version !== ''
                && (int) $state->certified_issued_at_microseconds > 0) {
                $stateIdentity = [
                    'state' => 'certified', 'revision' => (int) $state->revision,
                    'certified_revision' => (int) $state->certified_revision,
                    'certified_version' => $state->certified_version,
                    'issued_at_microseconds' => (int) $state->certified_issued_at_microseconds,
                ];
            } else {
                // Never cache active/failed unpublished rows, even when a
                // previous response or Redis publication token still exists.
                return null;
            }
            $version = app(EodCacheVersion::class)->current(EodCacheVersion::DOMAIN_GEX, $symbol);
            if ($state !== null && ! hash_equals($state->certified_version, $version)) {
                return null; // Publication has advanced but its certificate has not caught up.
            }
            $clock = Carbon::instance($at ?? Carbon::now());
            $identity = [
                'symbol' => $symbol, 'timeframe' => $timeframe,
                'publication_version' => $version, 'raw_state' => $stateIdentity,
                'policy' => EodSnapshotManifestBuilder::policyHash($policy),
                'ny_day' => $clock->copy()->setTimezone('America/New_York')->toDateString(),
                'app_day' => $clock->copy()->setTimezone(date_default_timezone_get())->toDateString(),
            ];

            return [
                'key' => self::COMPATIBILITY_PREFIX.hash('sha256', EodSnapshotManifestBuilder::canonicalJson($identity)),
                'cache_version' => $version,
            ];
        } catch (\Throwable) {
            // Missing/unavailable metadata is not evidence of an absent row.
            return null;
        }
    }

    public function key(string $symbol, string $timeframe, array $manifest, ?CarbonInterface $at = null): string
    {
        $clock = Carbon::instance($at ?? Carbon::now());
        $identity = [
            'symbol' => $symbol, 'timeframe' => $timeframe,
            'revision' => $manifest['revision'], 'version' => $manifest['cache_version'],
            'policy' => EodSnapshotManifestBuilder::policyHash($manifest['policy']),
            // Age/weekday horizons use New York. Monthly uses app time.
            'ny_day' => $clock->copy()->setTimezone('America/New_York')->toDateString(),
            'app_day' => $clock->copy()->setTimezone(date_default_timezone_get())->toDateString(),
        ];

        // v6 cold payloads retain legacy SQL traversal for exact floating-point
        // accumulation parity. Never reuse diagnostic v5 reordered payloads.
        return 'gex:levels:v6:'.hash('sha256', EodSnapshotManifestBuilder::canonicalJson($identity));
    }

    /** One cache retrieval; malformed payloads are misses, never HTTP 200 data. */
    public function get(string $key): ?array
    {
        $entry = Cache::get($key);
        if ($entry === null) {
            return null;
        }
        try {
            $payload = $entry['payload'] ?? null;
            if (is_array($entry) && ($entry['schema_version'] ?? null) === 1
                && is_array($payload) && is_array($payload['strike_data'] ?? null)
                && is_string($entry['sha256'] ?? null)
                && hash_equals($entry['sha256'], hash('sha256', EodSnapshotManifestBuilder::canonicalJson($payload)))) {
                return $payload;
            }
        } catch (\Throwable) {
            // Only this response entry is invalidated, never the manifest,
            // symbol head, locks, queue state, or another symbol's payload.
        }
        Cache::forget($key);

        return null;
    }

    /** Preserve an already published last-good response if another reader won. */
    public function putIfMissing(string $key, array $payload): void
    {
        Cache::add($key, [
            'schema_version' => 1,
            'payload' => $payload,
            'sha256' => hash('sha256', EodSnapshotManifestBuilder::canonicalJson($payload)),
        ], now()->addHours(8));
    }

    /** Caller must recheck the exact context after building from raw rows. */
    public function putCompatibilityIfMissing(string $key, array $payload): void
    {
        if (! str_starts_with($key, self::COMPATIBILITY_PREFIX)) {
            throw new \InvalidArgumentException('Compatibility payloads require their separate cache namespace.');
        }
        Cache::add($key, [
            'schema_version' => 1,
            'payload' => $payload,
            'sha256' => hash('sha256', EodSnapshotManifestBuilder::canonicalJson($payload)),
        ], now()->addSeconds(120));
    }
}
