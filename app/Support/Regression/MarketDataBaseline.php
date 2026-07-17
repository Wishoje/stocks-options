<?php

namespace App\Support\Regression;

use App\Support\Symbols;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MarketDataBaseline
{
    public const SCHEMA_VERSION = 2;

    /**
     * Capture a deterministic, credential-free market-data artifact.
     *
     * @param  array<int,string>  $symbols
     * @param  array<string,mixed>  $apiPayloads
     * @return array<string,mixed>
     */
    public function capture(array $symbols, ?string $date = null, array $apiPayloads = []): array
    {
        $symbols = collect($symbols)
            ->map(fn (mixed $symbol): ?string => Symbols::canon((string) $symbol))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($symbols === []) {
            throw new \InvalidArgumentException('At least one valid symbol is required.');
        }

        $date = $this->resolveDate($symbols, $date);

        $artifact = [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => [
                'symbols' => $symbols,
                'trade_date' => $date,
            ],
            'tables' => [
                'option_expirations' => $this->captureOptionExpirations($symbols, $date),
                'option_chain_data' => $this->captureOptionChainData($symbols, $date),
                'option_snapshots' => $this->captureOptionSnapshots($symbols, $date),
                'intraday_option_volumes' => $this->captureIntradayOptionVolumes($symbols, $date),
                'option_live_counters' => $this->captureOptionLiveCounters($symbols, $date),
                'daily_chain_snapshot' => $this->captureDailyChainSnapshot($symbols, $date),
                'unusual_activity' => $this->captureUnusualActivity($symbols, $date),
                'expiry_pressure' => $this->captureExpiryPressure($symbols, $date),
                'dex_by_expiry' => $this->captureDexByExpiry($symbols, $date),
                'underlying_quotes' => $this->captureUnderlyingQuotes($symbols),
                'prices_daily' => $this->capturePricesDaily($symbols, $date),
                'watchlists' => $this->captureWatchlists($symbols),
            ],
            'calculator' => $this->captureCalculatorState($symbols, $date),
            'api' => $this->normalizeApiPayloads($apiPayloads),
        ];

        return CanonicalJson::normalize($artifact);
    }

    /**
     * @param  array<int,string>  $symbols
     */
    private function resolveDate(array $symbols, ?string $date): string
    {
        if ($date !== null && trim($date) !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', trim($date), 'America/New_York')->toDateString();
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Date must use YYYY-MM-DD.');
            }
        }

        if (Schema::hasTable('option_chain_data') && Schema::hasTable('option_expirations')) {
            $latest = DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->whereIn('e.symbol', $symbols)
                ->max('o.data_date');

            if ($latest) {
                return substr((string) $latest, 0, 10);
            }
        }

        return now('America/New_York')->toDateString();
    }

    /** @param array<int,string> $symbols */
    private function captureOptionExpirations(array $symbols, string $date): array
    {
        if (!Schema::hasTable('option_expirations')) {
            return $this->missingTable();
        }

        $endDate = Carbon::parse($date, 'America/New_York')->addDays(120)->toDateString();
        $rows = DB::table('option_expirations')
            ->whereIn('symbol', $symbols)
            ->whereBetween('expiration_date', [$date, $endDate])
            ->get(['symbol', 'expiration_date'])
            ->all();

        return $this->fingerprint(
            $rows,
            expirationFields: ['expiration_date'],
            naturalKeyFields: ['symbol', 'expiration_date'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureOptionChainData(array $symbols, string $date): array
    {
        if (!Schema::hasTable('option_chain_data') || !Schema::hasTable('option_expirations')) {
            return $this->missingTable();
        }

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereIn('e.symbol', $symbols)
            ->whereDate('o.data_date', $date)
            ->get([
                'e.symbol',
                'e.expiration_date',
                'o.data_date',
                'o.option_type',
                'o.strike',
                'o.open_interest',
                'o.volume',
                'o.gamma',
                'o.delta',
                'o.vega',
                'o.iv',
                'o.underlying_price',
                'o.data_timestamp',
            ])
            ->all();

        return $this->fingerprint(
            $rows,
            sumFields: [
                'open_interest' => 'int',
                'volume' => 'int',
            ],
            expirationFields: ['expiration_date'],
            timestampFields: ['data_timestamp'],
            naturalKeyFields: ['symbol', 'expiration_date', 'data_date', 'option_type', 'strike'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureOptionSnapshots(array $symbols, string $date): array
    {
        if (!Schema::hasTable('option_snapshots')) {
            return $this->missingTable();
        }

        $rows = DB::table('option_snapshots')
            ->whereIn('symbol', $symbols)
            ->whereDate('fetched_at', $date)
            ->get([
                'symbol',
                'ticker',
                'type',
                'strike',
                'expiry',
                'bid',
                'ask',
                'mid',
                'underlying_price',
                'fetched_at',
            ])
            ->all();

        return $this->fingerprint(
            $rows,
            expirationFields: ['expiry'],
            timestampFields: ['fetched_at'],
            naturalKeyFields: ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureIntradayOptionVolumes(array $symbols, string $date): array
    {
        if (!Schema::hasTable('intraday_option_volumes')) {
            return $this->missingTable();
        }

        $rows = DB::table('intraday_option_volumes')
            ->whereIn('symbol', $symbols)
            ->whereDate('captured_at', $date)
            ->get([
                'symbol',
                'contract_symbol',
                'contract_type',
                'expiration_date',
                'strike_price',
                'volume',
                'open_interest',
                'implied_volatility',
                'delta',
                'gamma',
                'theta',
                'vega',
                'last_price',
                'captured_at',
            ])
            ->all();

        return $this->fingerprint(
            $rows,
            sumFields: [
                'volume' => 'int',
                'open_interest' => 'int',
            ],
            expirationFields: ['expiration_date'],
            timestampFields: ['captured_at'],
            naturalKeyFields: ['contract_symbol', 'captured_at'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureOptionLiveCounters(array $symbols, string $date): array
    {
        if (!Schema::hasTable('option_live_counters')) {
            return $this->missingTable();
        }

        $rows = DB::table('option_live_counters')
            ->whereIn('symbol', $symbols)
            ->whereDate('trade_date', $date)
            ->get([
                'symbol',
                'trade_date',
                'exp_date',
                'strike',
                'option_type',
                'volume',
                'premium_usd',
                'asof',
            ])
            ->all();

        return $this->fingerprint(
            $rows,
            sumFields: [
                'volume' => 'int',
                'premium_usd' => 'float',
            ],
            expirationFields: ['exp_date'],
            timestampFields: ['asof'],
            naturalKeyFields: ['symbol', 'trade_date', 'exp_date', 'strike', 'option_type'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureDailyChainSnapshot(array $symbols, string $date): array
    {
        if (!Schema::hasTable('daily_chain_snapshot') || !Schema::hasTable('option_expirations')) {
            return $this->missingTable();
        }

        $rows = DB::table('daily_chain_snapshot as d')
            ->join('option_expirations as e', 'e.id', '=', 'd.expiration_id')
            ->whereIn('d.symbol', $symbols)
            ->whereDate('d.data_date', $date)
            ->get([
                'd.symbol',
                'd.data_date',
                'e.expiration_date',
                'd.call_oi',
                'd.put_oi',
                'd.call_vol',
                'd.put_vol',
                'd.sum_gamma',
                'd.sum_delta',
                'd.sum_vega',
            ])
            ->all();

        return $this->fingerprint(
            $rows,
            sumFields: [
                'call_oi' => 'int',
                'put_oi' => 'int',
                'call_vol' => 'int',
                'put_vol' => 'int',
            ],
            expirationFields: ['expiration_date'],
            naturalKeyFields: ['symbol', 'data_date', 'expiration_date'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureUnusualActivity(array $symbols, string $date): array
    {
        if (!Schema::hasTable('unusual_activity')) {
            return $this->missingTable();
        }

        $rows = DB::table('unusual_activity')
            ->whereIn('symbol', $symbols)
            ->whereDate('data_date', $date)
            ->get(['symbol', 'data_date', 'exp_date', 'strike', 'z_score', 'vol_oi', 'meta'])
            ->all();

        return $this->fingerprint(
            $rows,
            expirationFields: ['exp_date'],
            naturalKeyFields: ['symbol', 'data_date', 'exp_date', 'strike'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureExpiryPressure(array $symbols, string $date): array
    {
        if (!Schema::hasTable('expiry_pressure')) {
            return $this->missingTable();
        }

        $columns = ['symbol', 'data_date', 'exp_date', 'pin_score', 'clusters_json', 'max_pain'];
        if (Schema::hasColumn('expiry_pressure', 'source_chain_date')) {
            $columns[] = 'source_chain_date';
        }

        $rows = DB::table('expiry_pressure')
            ->whereIn('symbol', $symbols)
            ->whereDate('data_date', $date)
            ->get($columns)
            ->all();

        return $this->fingerprint(
            $rows,
            expirationFields: ['exp_date'],
            naturalKeyFields: ['symbol', 'data_date', 'exp_date'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureDexByExpiry(array $symbols, string $date): array
    {
        if (!Schema::hasTable('dex_by_expiry')) {
            return $this->missingTable();
        }

        $columns = ['symbol', 'data_date', 'exp_date', 'dex_total'];
        if (Schema::hasColumn('dex_by_expiry', 'source_chain_date')) {
            $columns[] = 'source_chain_date';
        }

        $rows = DB::table('dex_by_expiry')
            ->whereIn('symbol', $symbols)
            ->whereDate('data_date', $date)
            ->get($columns)
            ->all();

        return $this->fingerprint(
            $rows,
            expirationFields: ['exp_date'],
            naturalKeyFields: ['symbol', 'data_date', 'exp_date'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureUnderlyingQuotes(array $symbols): array
    {
        if (!Schema::hasTable('underlying_quotes')) {
            return $this->missingTable();
        }

        $rows = DB::table('underlying_quotes')
            ->whereIn('symbol', $symbols)
            ->get(['symbol', 'source', 'last_price', 'prev_close', 'asof'])
            ->all();

        return $this->fingerprint(
            $rows,
            timestampFields: ['asof'],
            naturalKeyFields: ['symbol'],
        );
    }

    /** @param array<int,string> $symbols */
    private function capturePricesDaily(array $symbols, string $date): array
    {
        if (!Schema::hasTable('prices_daily')) {
            return $this->missingTable();
        }

        $rows = DB::table('prices_daily')
            ->whereIn('symbol', $symbols)
            ->whereDate('trade_date', $date)
            ->get(['symbol', 'trade_date', 'open', 'high', 'low', 'close'])
            ->all();

        return $this->fingerprint(
            $rows,
            naturalKeyFields: ['symbol', 'trade_date'],
        );
    }

    /** @param array<int,string> $symbols */
    private function captureWatchlists(array $symbols): array
    {
        if (!Schema::hasTable('watchlists')) {
            return $this->missingTable();
        }

        // User identifiers are intentionally reduced to per-symbol membership counts.
        $rows = DB::table('watchlists')
            ->whereIn('symbol', $symbols)
            ->select('symbol', DB::raw('COUNT(*) as membership_count'))
            ->groupBy('symbol')
            ->get()
            ->all();

        return $this->fingerprint(
            $rows,
            sumFields: ['membership_count' => 'int'],
            naturalKeyFields: ['symbol'],
        );
    }

    /**
     * @param  array<int,string>  $symbols
     * @return array<string,mixed>
     */
    private function captureCalculatorState(array $symbols, string $date): array
    {
        $state = [];
        foreach ($symbols as $symbol) {
            $state[$symbol] = [
                'catalog_expirations' => [],
                'snapshot_expirations' => [],
                'expirations' => [],
            ];
        }

        if (Schema::hasTable('option_expirations')) {
            $endDate = Carbon::parse($date, 'America/New_York')->addDays(120)->toDateString();
            $catalogRows = DB::table('option_expirations')
                ->whereIn('symbol', $symbols)
                ->whereBetween('expiration_date', [$date, $endDate])
                ->get(['symbol', 'expiration_date']);

            foreach ($catalogRows as $row) {
                $state[(string) $row->symbol]['catalog_expirations'][] = substr((string) $row->expiration_date, 0, 10);
            }
        }

        if (!Schema::hasTable('option_snapshots')) {
            foreach ($state as &$symbolState) {
                $symbolState['catalog_expirations'] = $this->sortedUnique($symbolState['catalog_expirations']);
            }

            return CanonicalJson::normalize($state);
        }

        $rows = DB::table('option_snapshots')
            ->whereIn('symbol', $symbols)
            ->whereDate('fetched_at', $date)
            ->orderBy('symbol')
            ->orderBy('expiry')
            ->orderBy('fetched_at')
            ->get(['symbol', 'expiry', 'fetched_at', 'type', 'strike', 'underlying_price']);

        $generations = [];
        foreach ($rows as $row) {
            $symbol = (string) $row->symbol;
            $expiry = substr((string) $row->expiry, 0, 10);
            $fetchedAt = (string) $row->fetched_at;
            $key = $symbol.'|'.$expiry.'|'.$fetchedAt;

            if (!isset($generations[$key])) {
                $generations[$key] = [
                    'symbol' => $symbol,
                    'expiry' => $expiry,
                    'fetched_at' => $fetchedAt,
                    'row_count' => 0,
                    'call_rows' => 0,
                    'put_rows' => 0,
                    'strikes' => [],
                    'min_strike' => null,
                    'max_strike' => null,
                    'spot_price' => null,
                ];
            }

            $generation = &$generations[$key];
            $strike = (float) $row->strike;
            $generation['row_count']++;
            $generation['call_rows'] += $row->type === 'call' ? 1 : 0;
            $generation['put_rows'] += $row->type === 'put' ? 1 : 0;
            $generation['strikes'][(string) $row->strike] = true;
            $generation['min_strike'] = $generation['min_strike'] === null
                ? $strike
                : min($generation['min_strike'], $strike);
            $generation['max_strike'] = $generation['max_strike'] === null
                ? $strike
                : max($generation['max_strike'], $strike);
            if ($generation['spot_price'] === null && $row->underlying_price !== null) {
                $generation['spot_price'] = (float) $row->underlying_price;
            }
            unset($generation);
        }

        foreach ($generations as $generation) {
            $generation['strike_count'] = count($generation['strikes']);
            unset($generation['strikes']);

            $spot = (float) ($generation['spot_price'] ?? 0);
            $coversSpot = $spot <= 0 || (
                (float) $generation['min_strike'] <= $spot * 0.95
                && (float) $generation['max_strike'] >= $spot * 1.05
            );
            $generation['is_partial'] = $generation['row_count'] < 40
                || $generation['call_rows'] === 0
                || $generation['put_rows'] === 0
                || !$coversSpot;

            $symbol = $generation['symbol'];
            $expiry = $generation['expiry'];
            unset($generation['symbol'], $generation['expiry']);

            $state[$symbol]['snapshot_expirations'][] = $expiry;
            $state[$symbol]['expirations'][$expiry][] = $generation;
        }

        foreach ($state as &$symbolState) {
            $symbolState['catalog_expirations'] = $this->sortedUnique($symbolState['catalog_expirations']);
            $symbolState['snapshot_expirations'] = $this->sortedUnique($symbolState['snapshot_expirations']);
            $allExpirations = array_values(array_unique(array_merge(
                $symbolState['catalog_expirations'],
                $symbolState['snapshot_expirations']
            )));
            sort($allExpirations, SORT_STRING);
            $symbolState['all_expirations'] = $allExpirations;

            ksort($symbolState['expirations'], SORT_STRING);
            foreach ($symbolState['expirations'] as &$expiryState) {
                usort($expiryState, fn (array $left, array $right): int => strcmp(
                    (string) $left['fetched_at'],
                    (string) $right['fetched_at']
                ));

                $latest = $expiryState[array_key_last($expiryState)];
                $fullest = collect($expiryState)
                    ->sortBy(fn (array $generation): string => sprintf(
                        '%010d|%s',
                        (int) $generation['row_count'],
                        (string) $generation['fetched_at']
                    ))
                    ->last();

                $expiryState = [
                    'generation_count' => count($expiryState),
                    'latest_fetched_at' => $latest['fetched_at'],
                    'latest_row_count' => $latest['row_count'],
                    'latest_is_partial' => $latest['is_partial'],
                    'fullest_fetched_at' => $fullest['fetched_at'],
                    'fullest_row_count' => $fullest['row_count'],
                    'latest_is_thinner_than_fullest' => $latest['row_count'] < $fullest['row_count'],
                    'generations' => array_values($expiryState),
                ];
            }
            unset($expiryState);
        }
        unset($symbolState);

        return CanonicalJson::normalize($state);
    }

    /**
     * @param  array<string,mixed>  $payloads
     * @return array<string,mixed>
     */
    private function normalizeApiPayloads(array $payloads): array
    {
        $safe = [];
        foreach ($payloads as $name => $payload) {
            if (!is_string($name) || trim($name) === '') {
                throw new \InvalidArgumentException('Every API payload requires a stable string name.');
            }

            if (is_object($payload) && method_exists($payload, 'json')) {
                $payload = $payload->json();
            }

            $safe[$name] = CanonicalJson::normalize($this->sanitizeApiPayload($payload));
        }

        ksort($safe, SORT_STRING);

        return $safe;
    }

    private function sanitizeApiPayload(mixed $value, ?string $key = null): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if ($key !== null) {
            $normalizedKey = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
            $normalizedKey = str_replace(['-', '.'], '_', $normalizedKey);

            if (preg_match('/(^|_)(password|passwd|secret|token|authorization|cookie|private_key|api_key|apikey)($|_)/', $normalizedKey)) {
                return $this->redactedValue($value, 'secret');
            }

            if (preg_match('/(^|_)(email|phone|address)($|_)/', $normalizedKey)) {
                return $this->redactedValue($value, 'pii');
            }

            if ($normalizedKey === 'id'
                || $normalizedKey === 'uuid'
                || str_ends_with($normalizedKey, '_id')
                || str_ends_with($normalizedKey, '_uuid')) {
                return $this->redactedValue($value, 'generated-id');
            }
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeApiPayload(
                    $childValue,
                    is_string($childKey) ? $childKey : null
                );
            }

            return $sanitized;
        }

        if (is_string($value) && preg_match('/^(Bearer\s+|Basic\s+|sk_(live|test)_|pk_live_|whsec_|base64:)/i', trim($value))) {
            return '<redacted-secret>';
        }

        return $value;
    }

    private function redactedValue(mixed $value, string $label): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->redactedValue($item, $label), $value);
        }

        return match (true) {
            $value === null => null,
            is_int($value) => 0,
            is_float($value) => 0.0,
            is_bool($value) => false,
            default => "<redacted-{$label}>",
        };
    }

    /**
     * @param  array<int,object|array<string,mixed>>  $rows
     * @param  array<string,'int'|'float'>  $sumFields
     * @param  array<int,string>  $expirationFields
     * @param  array<int,string>  $timestampFields
     * @param  array<int,string>  $naturalKeyFields
     * @return array<string,mixed>
     */
    private function fingerprint(
        array $rows,
        array $sumFields = [],
        array $expirationFields = [],
        array $timestampFields = [],
        array $naturalKeyFields = [],
    ): array {
        $normalizedRows = array_map(function (object|array $row): array {
            $row = (array) $row;

            foreach ($row as $key => $value) {
                if ($value instanceof \DateTimeInterface) {
                    $row[$key] = $value->format('Y-m-d H:i:s');
                }
            }

            return CanonicalJson::normalize($row);
        }, $rows);

        $normalizedRows = CanonicalJson::sortRows($normalizedRows);
        $timestampLookup = array_fill_keys($timestampFields, true);
        $stableRows = array_map(
            fn (array $row): array => array_diff_key($row, $timestampLookup),
            $normalizedRows
        );
        $stableRows = CanonicalJson::sortRows($stableRows);

        $sums = [];
        foreach ($sumFields as $field => $kind) {
            $sum = 0.0;
            foreach ($normalizedRows as $row) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $sum += (float) $row[$field];
                }
            }
            $sums[$field] = $kind === 'int' ? (int) round($sum) : round($sum, 6);
        }

        $expirationSet = [];
        foreach ($normalizedRows as $row) {
            foreach ($expirationFields as $field) {
                if (!empty($row[$field])) {
                    $expirationSet[] = substr((string) $row[$field], 0, 10);
                }
            }
        }
        $expirationSet = $this->sortedUnique($expirationSet);

        $latestTimestamps = [];
        $latestBySymbol = [];
        foreach ($timestampFields as $field) {
            $values = [];
            foreach ($normalizedRows as $row) {
                if (!empty($row[$field])) {
                    $values[] = (string) $row[$field];
                    if (!empty($row['symbol'])) {
                        $symbol = (string) $row['symbol'];
                        $latestBySymbol[$field][$symbol] = max(
                            (string) ($latestBySymbol[$field][$symbol] ?? ''),
                            (string) $row[$field]
                        );
                    }
                }
            }

            if ($values !== []) {
                sort($values, SORT_STRING);
                $latestTimestamps[$field] = $values[array_key_last($values)];
            } else {
                $latestTimestamps[$field] = null;
            }
        }

        foreach ($latestBySymbol as &$bySymbol) {
            ksort($bySymbol, SORT_STRING);
        }
        unset($bySymbol);

        $timestampsByNaturalKey = [];
        if ($timestampFields !== []) {
            $timestampKeyFields = array_values(array_diff($naturalKeyFields, $timestampFields));

            foreach ($normalizedRows as $row) {
                $keyPayload = [];
                foreach ($timestampKeyFields as $field) {
                    $keyPayload[$field] = $row[$field] ?? null;
                }
                if ($keyPayload === []) {
                    $keyPayload = array_diff_key($row, $timestampLookup);
                }

                $keyHash = substr(hash('sha256', CanonicalJson::encode($keyPayload)), 0, 24);
                foreach ($timestampFields as $field) {
                    if (! empty($row[$field])) {
                        $timestampsByNaturalKey[$keyHash][$field][] = (string) $row[$field];
                    }
                }
            }

            foreach ($timestampsByNaturalKey as &$fields) {
                foreach ($fields as &$values) {
                    sort($values, SORT_STRING);
                    if (count($values) === 1) {
                        $values = $values[0];
                    }
                }
                unset($values);
                ksort($fields, SORT_STRING);
            }
            unset($fields);
            ksort($timestampsByNaturalKey, SORT_STRING);
        }

        [$duplicateKeyCount, $duplicateRowCount] = $this->duplicateCounts($normalizedRows, $naturalKeyFields);
        $samples = $stableRows;
        if (count($samples) > 4) {
            $samples = array_merge(array_slice($samples, 0, 2), array_slice($samples, -2));
        }

        return CanonicalJson::normalize([
            'available' => true,
            'row_count' => count($normalizedRows),
            'sha256' => hash('sha256', CanonicalJson::encode($stableRows)),
            'sums' => $sums,
            'expiration_set' => $expirationSet,
            'latest_timestamps' => $latestTimestamps,
            'latest_timestamps_by_symbol' => $latestBySymbol,
            'timestamps_by_natural_key' => $timestampsByNaturalKey,
            'duplicate_natural_key_count' => $duplicateKeyCount,
            'duplicate_row_count' => $duplicateRowCount,
            'samples' => array_values($samples),
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $naturalKeyFields
     * @return array{0:int,1:int}
     */
    private function duplicateCounts(array $rows, array $naturalKeyFields): array
    {
        if ($naturalKeyFields === []) {
            return [0, 0];
        }

        $counts = [];
        foreach ($rows as $row) {
            $key = [];
            foreach ($naturalKeyFields as $field) {
                $key[$field] = $row[$field] ?? null;
            }
            $encoded = CanonicalJson::encode($key);
            $counts[$encoded] = ($counts[$encoded] ?? 0) + 1;
        }

        $duplicateKeys = 0;
        $duplicateRows = 0;
        foreach ($counts as $count) {
            if ($count > 1) {
                $duplicateKeys++;
                $duplicateRows += $count - 1;
            }
        }

        return [$duplicateKeys, $duplicateRows];
    }

    /** @param array<int,string> $values */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_STRING);

        return $values;
    }

    /** @return array<string,mixed> */
    private function missingTable(): array
    {
        return [
            'available' => false,
            'row_count' => 0,
            'sha256' => null,
            'sums' => [],
            'expiration_set' => [],
            'latest_timestamps' => [],
            'latest_timestamps_by_symbol' => [],
            'duplicate_natural_key_count' => 0,
            'duplicate_row_count' => 0,
            'samples' => [],
        ];
    }
}
