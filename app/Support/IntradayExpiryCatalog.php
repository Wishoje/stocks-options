<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class IntradayExpiryCatalog
{
    /** A verified listing can exclude an obsolete catalog date, not a missing chain. */
    public function filter(string $symbol, array $expiries, CarbonImmutable $now): array
    {
        $catalog = $this->reference($symbol, $now);
        if (! $catalog) {
            return $expiries;
        }

        return array_filter($expiries, fn ($expiry) => $expiry < $catalog['from'] || $expiry > $catalog['through']
            || in_array($expiry, $catalog['expirations'], true));
    }

    public function reference(string $symbol, CarbonImmutable $now): ?array
    {
        $catalog = Cache::get($this->key($symbol, $now));

        return ($catalog['complete'] ?? false) ? $catalog : null;
    }

    /** Called only by collection after a chain/catalog mismatch, never by a web read. */
    public function refresh(string $symbol, CarbonImmutable $now): bool
    {
        $key = $this->key($symbol, $now);
        if (($cached = Cache::get($key)) !== null) {
            return (bool) ($cached['complete'] ?? false);
        }

        return Cache::lock($key.':lock', 60)->get(function () use ($symbol, $now, $key) {
            $date = $now->setTimezone('America/New_York')->toDateString();
            $result = app(MassiveExpirationCatalog::class)->discover($symbol, $date, 7);
            $complete = ($result['meta']['complete'] ?? false) && ! ($result['meta']['capped'] ?? true)
                && ($result['meta']['status'] ?? null) === 'ok' && is_array($result['expirations'] ?? null);
            Cache::put($key, ['complete' => $complete, 'from' => $date,
                'through' => $now->setTimezone('America/New_York')->addDays(7)->toDateString(),
                'expirations' => $complete ? $result['expirations'] : [],
                'source' => 'massive_reference_contracts', 'checked_at' => $now->toIso8601String()], $now->addMinutes(30));

            return $complete;
        }) ?? false;
    }

    private function key(string $symbol, CarbonImmutable $now): string
    {
        return 'intraday:expiry-catalog:'.Symbols::canon($symbol).':'.$now->setTimezone('America/New_York')->toDateString();
    }
}
