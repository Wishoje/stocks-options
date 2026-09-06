<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Aggregate history once in a worker; never truncate expiration coverage. */
final class EodSnapshotManifestBuilder
{
    public const ALGORITHM = 'eod-selector-v1';

    public static function policy(string $anchorDate, float $minSideRatio): array
    {
        if (! self::isDate($anchorDate) || ! is_finite($minSideRatio)
            || $minSideRatio < 0.01 || $minSideRatio > 1.0) {
            throw new InvalidArgumentException('EOD snapshot selector policy is invalid.');
        }

        return ['algorithm' => self::ALGORITHM, 'anchor_date' => $anchorDate, 'min_side_ratio' => $minSideRatio];
    }

    public static function normalizePolicy(array $policy): array
    {
        if (($policy['algorithm'] ?? null) !== self::ALGORITHM
            || ! is_string($policy['anchor_date'] ?? null)
            || (! is_float($policy['min_side_ratio'] ?? null) && ! is_int($policy['min_side_ratio'] ?? null))) {
            throw new InvalidArgumentException('EOD snapshot selector policy is invalid.');
        }

        return self::policy($policy['anchor_date'], (float) $policy['min_side_ratio']);
    }

    public static function policyHash(array $policy): string
    {
        return hash('sha256', self::canonicalJson(self::normalizePolicy($policy)));
    }

    public function build(string $symbol, int $revision, string $version, array $policy): array
    {
        $policy = self::normalizePolicy($policy);
        $catalog = DB::table('option_expirations')->where('symbol', $symbol)
            ->orderBy('expiration_date')->orderBy('id')->get(['id', 'expiration_date']);
        $history = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)
            ->selectRaw('/*+ MAX_EXECUTION_TIME(60000) */ o.expiration_id, o.data_date')
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.option_type = 'call' THEN o.strike END) AS call_strikes_n")
            ->selectRaw("COUNT(DISTINCT CASE WHEN o.option_type = 'put' THEN o.strike END) AS put_strikes_n")
            ->selectRaw('COUNT(DISTINCT o.strike) AS strike_count')
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('SUM(CASE WHEN o.gamma IS NOT NULL AND o.gamma != 0 THEN 1 ELSE 0 END) AS gamma_rows')
            ->selectRaw('SUM(CASE WHEN o.gamma IS NULL THEN 1 ELSE 0 END) AS missing_gamma_rows')
            ->selectRaw('MAX(o.data_timestamp) AS latest_data_timestamp')
            ->selectRaw("CASE WHEN COUNT(DISTINCT CASE WHEN o.option_type = 'call' THEN o.strike END) > 0
                AND COUNT(DISTINCT CASE WHEN o.option_type = 'put' THEN o.strike END) > 0
                AND LEAST(
                    COUNT(DISTINCT CASE WHEN o.option_type = 'call' THEN o.strike END),
                    COUNT(DISTINCT CASE WHEN o.option_type = 'put' THEN o.strike END)
                ) / NULLIF(GREATEST(
                    COUNT(DISTINCT CASE WHEN o.option_type = 'call' THEN o.strike END),
                    COUNT(DISTINCT CASE WHEN o.option_type = 'put' THEN o.strike END)
                ), 0) >= ? THEN 1 ELSE 0 END AS selector_balanced", [$policy['min_side_ratio']])
            ->groupBy('o.expiration_id', 'o.data_date')
            ->orderBy('o.expiration_id')->orderByDesc('o.data_date')->get();

        return $this->fromRows($symbol, $revision, $version, $policy, $catalog, $history);
    }

    /** Public pure projection permits exact selector-parity proof without database fixtures. */
    public function fromRows(
        string $symbol,
        int $revision,
        string $version,
        array $policy,
        Collection $catalog,
        Collection $history
    ): array {
        $policy = self::normalizePolicy($policy);
        $groups = $history->groupBy('expiration_id');
        $expirations = [];
        $dates = [];
        $latestTimestamp = null;
        foreach ($catalog as $expiration) {
            $id = (int) $expiration->id;
            $date = substr((string) $expiration->expiration_date, 0, 10);
            $dates[] = ['expiration_id' => $id, 'expiration_date' => $date];
            $all = $groups->get($id, collect())->sortByDesc('data_date')->values();
            $bounded = $all->filter(static fn ($row): bool => (string) $row->data_date <= $policy['anchor_date'])->values();
            $latestAny = $bounded->first();
            $latestBalanced = $bounded->first(static fn ($row): bool => EodHealth::sideRatioMeetsThreshold(
                (int) $row->call_strikes_n, (int) $row->put_strikes_n, $policy['min_side_ratio']
            ));
            // SQL integer division can round at a threshold where PHP's
            // health-summary ratio does not. Preserve both legacy decisions.
            $selectorBalanced = $bounded->first(static fn ($row): bool => isset($row->selector_balanced)
                ? (bool) $row->selector_balanced
                : EodHealth::sideRatioMeetsThreshold((int) $row->call_strikes_n, (int) $row->put_strikes_n, $policy['min_side_ratio']));
            $selected = $selectorBalanced ?? $latestAny;
            $unbounded = $all->first();
            $gamma = $all->first(static fn ($row): bool => (int) ($row->gamma_rows ?? 0) > 0);
            $sourceTime = $all->pluck('latest_data_timestamp')->filter()->max();
            if ($sourceTime !== null && ($latestTimestamp === null || $sourceTime > $latestTimestamp)) {
                $latestTimestamp = (string) $sourceTime;
            }
            $expirations[$id] = [
                'expiration_id' => $id,
                'expiration_date' => $date,
                'latest_any_date' => $latestAny?->data_date,
                'latest_balanced_date' => $latestBalanced?->data_date,
                'latest_selector_balanced_date' => $selectorBalanced?->data_date,
                'selected_date' => $selected?->data_date,
                'latest_any_call_rows' => (int) ($latestAny?->call_strikes_n ?? 0),
                'latest_any_put_rows' => (int) ($latestAny?->put_strikes_n ?? 0),
                'latest_any_strike_count' => (int) ($latestAny?->strike_count ?? 0),
                'latest_any_side_ratio' => $latestAny ? EodHealth::sideStrikeRatio((int) $latestAny->call_strikes_n, (int) $latestAny->put_strikes_n) : null,
                'selected_call_rows' => (int) ($selected?->call_strikes_n ?? 0),
                'selected_put_rows' => (int) ($selected?->put_strikes_n ?? 0),
                'selected_strike_count' => (int) ($selected?->strike_count ?? 0),
                'selected_side_ratio' => $selected ? EodHealth::sideStrikeRatio((int) $selected->call_strikes_n, (int) $selected->put_strikes_n) : null,
                'latest_any_gamma_rows' => (int) ($latestAny?->gamma_rows ?? 0),
                'selected_gamma_rows' => (int) ($selected?->gamma_rows ?? 0),
                'selected_missing_gamma_rows' => (int) ($selected?->missing_gamma_rows ?? 0),
                'selected_row_count' => (int) ($selected?->row_count ?? 0),
                'latest_unbounded_date' => $unbounded?->data_date,
                'latest_unbounded_gamma_rows' => (int) ($unbounded?->gamma_rows ?? 0),
                'latest_gamma_date' => $gamma?->data_date,
                'latest_data_timestamp' => $sourceTime !== null ? (string) $sourceTime : null,
                'selected_data_timestamp' => $selected?->latest_data_timestamp,
                'selection_state' => $selected === null ? 'missing' : ($selectorBalanced ? 'balanced' : 'fallback_partial'),
            ];
        }

        return [
            'schema_version' => 1,
            'symbol' => $symbol,
            'revision' => $revision,
            'cache_version' => $version,
            'policy' => $policy,
            'catalog' => $dates,
            'expirations' => $expirations,
            'expiration_count' => count($dates),
            'latest_data_timestamp' => $latestTimestamp,
            // Ordinary ingestion writes data_timestamp=now(); recovery may
            // retain a provider timestamp. Do not label this universally asof.
            'data_timestamp_semantics' => 'legacy_ingestion_or_recovery_timestamp',
        ];
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * MySQL's native JSON number parser can change a PHP double's final bit.
     * Keep the canonical document as a JSON string so its exact bytes survive
     * binary-JSON persistence. This does not round or change any business value.
     */
    public static function encodeStorage(array $value): string
    {
        return self::canonicalJson([
            'encoding' => 'canonical-json-v1',
            'payload' => self::canonicalJson($value),
        ]);
    }

    /** Read valid legacy objects or an exact, versioned canonical document. */
    public static function decodeStorage(string $json): array
    {
        $stored = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($stored)) {
            throw new InvalidArgumentException('EOD manifest storage must contain an object.');
        }
        if (! array_key_exists('encoding', $stored)) {
            return $stored;
        }
        if (count($stored) !== 2 || $stored['encoding'] !== 'canonical-json-v1'
            || ! is_string($stored['payload'] ?? null)) {
            throw new InvalidArgumentException('EOD manifest storage encoding is invalid.');
        }
        $decoded = json_decode($stored['payload'], true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || self::canonicalJson($decoded) !== $stored['payload']) {
            throw new InvalidArgumentException('EOD manifest storage payload is not canonical.');
        }

        return $decoded;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    public static function isDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
