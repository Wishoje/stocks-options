<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/** Response payloads are separate from durable publication/health records. */
final class GexSnapshotCache
{
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

        return 'gex:levels:v5:'.hash('sha256', EodSnapshotManifestBuilder::canonicalJson($identity));
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
}
