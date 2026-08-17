<?php

namespace App\Support;

use App\Models\OptionLiveTotal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class OptionLiveTotalsRepository
{
    /** @var list<string> */
    private const COMPARISON_FIELDS = [
        'symbol',
        'trade_date',
        'call_volume',
        'put_volume',
        'volume',
        'premium_usd',
        'asof',
        'source_updated_at',
        'source_row_id',
    ];

    /**
     * Write the compatibility total first, then optionally shadow it into the
     * non-null canonical key. The canonical upsert independently rejects stale
     * and out-of-order source rows.
     *
     * @param  array{symbol:string,trade_date:string|DateTimeInterface,call_volume:int,put_volume:int,volume?:int,premium_usd?:int|float|string|null,asof?:string|DateTimeInterface|null,source_updated_at?:string|DateTimeInterface|null}  $attributes
     * @return array{legacy:array<string,mixed>,canonical:?array<string,mixed>,comparison:?array<string,mixed>}
     */
    public function publish(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $input = $this->normalizePublishedTotal($attributes);
            $legacyWrite = $this->writeLegacyTotal($input);
            $legacy = $this->legacyTotal($input['symbol'], $input['trade_date']);

            if ($legacy === null) {
                throw new RuntimeException('The option-live compatibility total was not persisted.');
            }

            $canonical = null;
            if ((bool) config('option_live_totals.dual_write', false)) {
                // A stale retry must not transfer its already-written detail
                // aggregates under the fresher compatibility row's source key.
                $canonical = $legacyWrite['accepted']
                    ? $this->normalizeResult($this->store([
                        'symbol' => $legacy['symbol'],
                        'trade_date' => $legacy['trade_date'],
                        'call_volume' => $input['call_volume'],
                        'put_volume' => $input['put_volume'],
                        'volume' => $legacy['volume'],
                        'premium_usd' => $legacy['premium_usd'],
                        'asof' => $legacy['asof'],
                        'source_updated_at' => $legacy['source_updated_at'],
                        'source_row_id' => $legacy['source_row_id'],
                    ]))
                    : $this->canonicalTotal($input['symbol'], $input['trade_date']);
            }

            $comparison = null;
            if ((bool) config('option_live_totals.compare_writes', false)) {
                $comparison = $this->compare($input['symbol'], $input['trade_date']);
                if (! $comparison['matches']) {
                    Log::warning('option_live_totals.comparison_mismatch', [
                        'symbol' => $input['symbol'],
                        'trade_date' => $input['trade_date'],
                        'different_fields' => array_keys($comparison['differences']),
                    ]);
                }
            }

            return compact('legacy', 'canonical', 'comparison');
        }, 3);
    }

    /**
     * Atomically retain only the freshest source version of one canonical key.
     *
     * @param  array{symbol:string,trade_date:string|DateTimeInterface,call_volume:int,put_volume:int,volume?:int,premium_usd?:int|float|string|null,asof?:string|DateTimeInterface|null,source_updated_at?:string|DateTimeInterface|null,source_row_id:int}  $attributes
     */
    public function store(array $attributes): OptionLiveTotal
    {
        $row = $this->normalizeCanonicalTotal($attributes);
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $this->storeMySql($row);
        } elseif ($driver === 'sqlite') {
            $this->storeSqlite($row);
        } else {
            $this->storeWithLock($row);
        }

        return OptionLiveTotal::query()
            ->where('symbol', $row['symbol'])
            ->where('trade_date', $row['trade_date'])
            ->firstOrFail();
    }

    /** @return array<string, mixed>|null */
    public function legacyTotal(
        string $symbol,
        string|DateTimeInterface $tradeDate
    ): ?array {
        $symbol = $this->symbol($symbol);
        $tradeDate = $this->tradeDate($tradeDate);
        $row = $this->legacyTotalsQuery($symbol, $tradeDate)->first();

        if ($row === null) {
            return null;
        }

        $volumes = DB::table('option_live_counters')
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereIn('option_type', ['call', 'put'])
            ->selectRaw("SUM(CASE WHEN option_type = 'call' THEN volume ELSE 0 END) AS call_volume")
            ->selectRaw("SUM(CASE WHEN option_type = 'put' THEN volume ELSE 0 END) AS put_volume")
            ->first();

        return [
            'symbol' => $symbol,
            'trade_date' => $tradeDate,
            'call_volume' => (int) ($volumes?->call_volume ?? 0),
            'put_volume' => (int) ($volumes?->put_volume ?? 0),
            'volume' => (int) $row->volume,
            'premium_usd' => $this->premium($row->premium_usd),
            'asof' => $this->dateTime($row->asof),
            'source_updated_at' => $this->dateTime($row->updated_at),
            'source_row_id' => (int) $row->id,
            'freshness_key' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function canonicalTotal(
        string $symbol,
        string|DateTimeInterface $tradeDate
    ): ?array {
        $row = OptionLiveTotal::query()
            ->where('symbol', $this->symbol($symbol))
            ->where('trade_date', $this->tradeDate($tradeDate))
            ->first();

        return $row === null ? null : $this->normalizeResult($row);
    }

    /** @return array<string, mixed>|null */
    public function read(
        string $symbol,
        string|DateTimeInterface $tradeDate
    ): ?array {
        if ((bool) config('option_live_totals.read_from_canonical', false)) {
            return $this->canonicalTotal($symbol, $tradeDate);
        }

        return $this->legacyTotal($symbol, $tradeDate);
    }

    /** @return array<string, mixed>|null */
    public function backfillOne(
        string $symbol,
        string|DateTimeInterface $tradeDate
    ): ?array {
        $legacy = $this->legacyTotal($symbol, $tradeDate);
        if ($legacy === null) {
            return null;
        }

        return $this->normalizeResult($this->store($legacy));
    }

    /**
     * @return array{matches:bool,legacy:?array<string,mixed>,canonical:?array<string,mixed>,differences:array<string,array{legacy:mixed,canonical:mixed}>}
     */
    public function compare(
        string $symbol,
        string|DateTimeInterface $tradeDate
    ): array {
        $legacy = $this->legacyTotal($symbol, $tradeDate);
        $canonical = $this->canonicalTotal($symbol, $tradeDate);
        $differences = [];

        foreach (self::COMPARISON_FIELDS as $field) {
            $legacyValue = $this->comparisonValue($field, $legacy[$field] ?? null);
            $canonicalValue = $this->comparisonValue($field, $canonical[$field] ?? null);
            if ($legacyValue !== $canonicalValue) {
                $differences[$field] = [
                    'legacy' => $legacyValue,
                    'canonical' => $canonicalValue,
                ];
            }
        }

        return [
            'matches' => $legacy !== null && $canonical !== null && $differences === [],
            'legacy' => $legacy,
            'canonical' => $canonical,
            'differences' => $differences,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{row:object,accepted:bool}
     */
    private function writeLegacyTotal(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $existing = $this->legacyTotalsQuery(
                $input['symbol'],
                $input['trade_date']
            )->lockForUpdate()->first();

            $values = [
                'symbol' => $input['symbol'],
                'trade_date' => $input['trade_date'],
                'exp_date' => null,
                'strike' => null,
                'option_type' => null,
                'volume' => $input['volume'],
                'premium_usd' => $input['premium_usd'],
                'asof' => $this->databaseDateTime($input['asof']),
                'updated_at' => $this->databaseDateTime($input['source_updated_at']),
            ];

            if ($existing === null) {
                $values['created_at'] = $values['updated_at'];
                $id = (int) DB::table('option_live_counters')->insertGetId($values);

                return [
                    'row' => DB::table('option_live_counters')->where('id', $id)->first(),
                    'accepted' => true,
                ];
            }

            $accepted = $this->freshnessKey(
                $input['asof'],
                $input['source_updated_at'],
                (int) $existing->id
            ) > $this->freshnessKey(
                $this->dateTime($existing->asof),
                $this->dateTime($existing->updated_at),
                (int) $existing->id
            );

            if ($accepted) {
                DB::table('option_live_counters')
                    ->where('id', $existing->id)
                    ->update($values);
            }

            return [
                'row' => DB::table('option_live_counters')->where('id', $existing->id)->first(),
                'accepted' => $accepted,
            ];
        }, 3);
    }

    private function legacyTotalsQuery(string $symbol, string $tradeDate): Builder
    {
        return DB::table('option_live_counters')
            ->where('symbol', $symbol)
            ->where('trade_date', $tradeDate)
            ->whereNull('exp_date')
            ->whereNull('strike')
            ->whereNull('option_type')
            ->orderByRaw('CASE WHEN asof IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('asof')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /** @param array<string, mixed> $row */
    private function storeMySql(array $row): void
    {
        $columns = $this->insertColumns();
        $quoted = implode(', ', array_map(fn (string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $replace = [
            'call_volume', 'put_volume', 'volume', 'premium_usd', 'asof',
            'source_updated_at', 'source_row_id', 'updated_at',
        ];
        $updates = array_map(
            fn (string $column): string => "`{$column}` = IF(VALUES(`freshness_key`) > `freshness_key`, VALUES(`{$column}`), `{$column}`)",
            $replace
        );
        // Keep this last: earlier assignments must compare with the stored key.
        $updates[] = '`freshness_key` = IF(VALUES(`freshness_key`) > `freshness_key`, VALUES(`freshness_key`), `freshness_key`)';

        DB::statement(
            'INSERT INTO `option_live_totals` ('.$quoted.') VALUES ('.$placeholders.') '
            .'ON DUPLICATE KEY UPDATE '.implode(', ', $updates),
            $this->bindings($row, $columns)
        );
    }

    /** @param array<string, mixed> $row */
    private function storeSqlite(array $row): void
    {
        $columns = $this->insertColumns();
        $quoted = implode(', ', array_map(fn (string $column): string => '"'.$column.'"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $replace = [
            'call_volume', 'put_volume', 'volume', 'premium_usd', 'asof',
            'source_updated_at', 'source_row_id', 'updated_at', 'freshness_key',
        ];
        $updates = array_map(
            fn (string $column): string => '"'.$column.'" = excluded."'.$column.'"',
            $replace
        );

        DB::statement(
            'INSERT INTO "option_live_totals" ('.$quoted.') VALUES ('.$placeholders.') '
            .'ON CONFLICT("symbol", "trade_date") DO UPDATE SET '.implode(', ', $updates).' '
            .'WHERE excluded."freshness_key" > "option_live_totals"."freshness_key"',
            $this->bindings($row, $columns)
        );
    }

    /** @param array<string, mixed> $row */
    private function storeWithLock(array $row): void
    {
        DB::transaction(function () use ($row): void {
            $existing = OptionLiveTotal::query()
                ->where('symbol', $row['symbol'])
                ->where('trade_date', $row['trade_date'])
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                OptionLiveTotal::query()->create($row);

                return;
            }

            if ($row['freshness_key'] > $existing->freshness_key) {
                $existing->fill($row)->save();
            }
        }, 3);
    }

    /** @return list<string> */
    private function insertColumns(): array
    {
        return [
            'symbol', 'trade_date', 'call_volume', 'put_volume', 'volume',
            'premium_usd', 'asof', 'source_updated_at', 'source_row_id',
            'freshness_key', 'created_at', 'updated_at',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return list<mixed>
     */
    private function bindings(array $row, array $columns): array
    {
        return array_map(fn (string $column): mixed => $row[$column], $columns);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizePublishedTotal(array $attributes): array
    {
        $row = $this->normalizeValues($attributes);
        // The compatibility table stores TIMESTAMP(0). Canonical writes made
        // through publish() must compare the exact precision legacy retains.
        $row['asof'] = $this->legacyPrecision($row['asof']);
        $row['source_updated_at'] = $this->legacyPrecision($this->dateTime(
            $attributes['source_updated_at']
                ?? $attributes['updated_at']
                ?? CarbonImmutable::now('UTC')
        ));

        return $row;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeCanonicalTotal(array $attributes): array
    {
        $row = $this->normalizeValues($attributes);
        $sourceRowId = filter_var(
            $attributes['source_row_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($sourceRowId === false) {
            throw new InvalidArgumentException('Option-live source_row_id must be a positive integer.');
        }
        $sourceUpdatedAt = $this->dateTime($attributes['source_updated_at'] ?? null);
        $storedAt = CarbonImmutable::now('UTC');

        return [
            ...$row,
            'source_updated_at' => $this->databaseDateTime($sourceUpdatedAt),
            'source_row_id' => (int) $sourceRowId,
            'freshness_key' => $this->freshnessKey($row['asof'], $sourceUpdatedAt, (int) $sourceRowId),
            'asof' => $this->databaseDateTime($row['asof']),
            'created_at' => $this->databaseDateTime($storedAt),
            'updated_at' => $this->databaseDateTime($storedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeValues(array $attributes): array
    {
        $callVolume = $this->nonNegativeInteger($attributes['call_volume'] ?? null, 'call_volume');
        $putVolume = $this->nonNegativeInteger($attributes['put_volume'] ?? null, 'put_volume');
        $volume = $this->nonNegativeInteger(
            $attributes['volume'] ?? ($callVolume + $putVolume),
            'volume'
        );

        return [
            'symbol' => $this->symbol((string) ($attributes['symbol'] ?? '')),
            'trade_date' => $this->tradeDate($attributes['trade_date'] ?? ''),
            'call_volume' => $callVolume,
            'put_volume' => $putVolume,
            'volume' => $volume,
            'premium_usd' => $this->premium($attributes['premium_usd'] ?? null),
            'asof' => $this->dateTime($attributes['asof'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeResult(OptionLiveTotal $row): array
    {
        return [
            'symbol' => (string) $row->symbol,
            'trade_date' => $row->trade_date->format('Y-m-d'),
            'call_volume' => (int) $row->call_volume,
            'put_volume' => (int) $row->put_volume,
            'volume' => (int) $row->volume,
            'premium_usd' => $this->premium($row->premium_usd),
            'asof' => $this->dateTime($row->asof),
            'source_updated_at' => $this->dateTime($row->source_updated_at),
            'source_row_id' => (int) $row->source_row_id,
            'freshness_key' => (string) $row->freshness_key,
        ];
    }

    private function symbol(string $symbol): string
    {
        $symbol = Symbols::canon($symbol);
        if (! Symbols::isValid($symbol) || strlen($symbol) > 12) {
            throw new InvalidArgumentException('Option-live symbol is invalid or exceeds 12 characters.');
        }

        return $symbol;
    }

    private function tradeDate(string|DateTimeInterface $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        $value = trim($value);

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = null;
        }

        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Option-live trade_date must use Y-m-d.');
        }

        return $value;
    }

    private function dateTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)->utc()
                : CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Option-live timestamp is invalid.');
        }
    }

    private function databaseDateTime(?DateTimeInterface $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::instance($value)->utc()->format('Y-m-d H:i:s.u');
    }

    private function legacyPrecision(?CarbonImmutable $value): ?CarbonImmutable
    {
        return $value?->setMicrosecond(0);
    }

    private function freshnessKey(
        ?DateTimeInterface $asof,
        ?DateTimeInterface $sourceUpdatedAt,
        int $sourceRowId
    ): string {
        return sprintf(
            '%d:%s:%s:%020d',
            $asof === null ? 0 : 1,
            $asof === null ? str_repeat('0', 20) : CarbonImmutable::instance($asof)->utc()->format('YmdHisu'),
            $sourceUpdatedAt === null ? str_repeat('0', 20) : CarbonImmutable::instance($sourceUpdatedAt)->utc()->format('YmdHisu'),
            $sourceRowId
        );
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        if ($validated === false) {
            throw new InvalidArgumentException("Option-live {$field} must be a non-negative integer.");
        }

        return (int) $validated;
    }

    private function premium(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Option-live premium_usd must be numeric or null.');
        }

        try {
            return (string) BigDecimal::of((string) $value)
                ->toScale(4, RoundingMode::HALF_UP);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Option-live premium_usd is outside the supported decimal format.');
        }
    }

    private function comparisonValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['asof', 'source_updated_at'], true)) {
            return $this->databaseDateTime($this->dateTime($value));
        }
        if ($field === 'premium_usd') {
            return $this->premium($value);
        }

        return $value;
    }
}
