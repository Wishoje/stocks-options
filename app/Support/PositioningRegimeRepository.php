<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class PositioningRegimeRepository
{
    public const DEFAULT_SCOPE_DAYS = 14;

    /**
     * @param  array{
     *     strength:?float,
     *     sign:?int,
     *     net_gamma:?float,
     *     absolute_gamma:?float,
     *     source_meta:?array<string,mixed>
     * }  $facts
     * @return array<string,mixed>
     */
    public function write(string $symbol, string $dataDate, int $scopeDays, array $facts): array
    {
        $symbol = $this->symbol($symbol);
        $dataDate = $this->date($dataDate);
        $scopeDays = $this->scopeDays($scopeDays);
        $strength = $this->strength($facts['strength'] ?? null);
        $sign = $this->sign($facts['sign'] ?? null);
        $netGamma = $this->finiteNumber($facts['net_gamma'] ?? null, 'net_gamma');
        $absoluteGamma = $this->finiteNumber($facts['absolute_gamma'] ?? null, 'absolute_gamma');
        if ($absoluteGamma !== null && $absoluteGamma < 0) {
            throw new InvalidArgumentException('Positioning absolute_gamma cannot be negative.');
        }

        $sourceMeta = $facts['source_meta'] ?? null;
        if ($sourceMeta !== null && ! is_array($sourceMeta)) {
            throw new InvalidArgumentException('Positioning source metadata must be an array or null.');
        }

        try {
            $sourceMetaJson = $sourceMeta === null
                ? null
                : json_encode($sourceMeta, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Positioning source metadata must be valid JSON.', previous: $exception);
        }

        $now = now('UTC');
        DB::table('positioning_regimes')->upsert(
            [[
                'symbol' => $symbol,
                'data_date' => $dataDate,
                'scope_days' => $scopeDays,
                'strength' => $strength,
                'gamma_sign' => $sign,
                'net_gamma' => $netGamma,
                'absolute_gamma' => $absoluteGamma,
                'source_meta_json' => $sourceMetaJson,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['symbol', 'data_date', 'scope_days'],
            [
                'strength',
                'gamma_sign',
                'net_gamma',
                'absolute_gamma',
                'source_meta_json',
                'updated_at',
            ]
        );

        return [
            'date' => $dataDate,
            'scope_days' => $scopeDays,
            'strength' => $strength,
            'sign' => $sign,
            'net_gamma' => $netGamma,
            'absolute_gamma' => $absoluteGamma,
            'source_meta' => $sourceMeta,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $symbol, string $dataDate, int $scopeDays = self::DEFAULT_SCOPE_DAYS): ?array
    {
        try {
            $row = DB::table('positioning_regimes')
                ->where('symbol', $this->symbol($symbol))
                ->where('data_date', $this->date($dataDate))
                ->where('scope_days', $this->scopeDays($scopeDays))
                ->first([
                    'data_date',
                    'scope_days',
                    'strength',
                    'gamma_sign',
                    'net_gamma',
                    'absolute_gamma',
                    'source_meta_json',
                ]);
        } catch (QueryException $exception) {
            // A rolling deployment can briefly run this code before its
            // migration. Only that known case falls back to the legacy cache.
            if ($this->missingTable($exception)) {
                return null;
            }

            throw $exception;
        }

        if ($row === null) {
            return null;
        }

        try {
            $sourceMeta = $row->source_meta_json === null
                ? null
                : json_decode((string) $row->source_meta_json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored positioning source metadata is invalid.', previous: $exception);
        }

        return [
            'date' => (string) $row->data_date,
            'scope_days' => (int) $row->scope_days,
            'strength' => $row->strength === null ? null : (float) $row->strength,
            'sign' => $row->gamma_sign === null ? null : (int) $row->gamma_sign,
            'net_gamma' => $row->net_gamma === null ? null : (float) $row->net_gamma,
            'absolute_gamma' => $row->absolute_gamma === null ? null : (float) $row->absolute_gamma,
            'source_meta' => $sourceMeta,
        ];
    }

    private function symbol(string $symbol): string
    {
        $symbol = Symbols::canon($symbol);
        if (! Symbols::isValid($symbol)) {
            throw new InvalidArgumentException('Positioning symbol is invalid.');
        }

        return $symbol;
    }

    private function date(string $date): string
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', trim($date), 'UTC');
        } catch (\Throwable) {
            $parsed = null;
        }
        if ($parsed === null || $parsed->format('Y-m-d') !== trim($date)) {
            throw new InvalidArgumentException('Positioning data_date must use Y-m-d.');
        }

        return trim($date);
    }

    private function scopeDays(int $scopeDays): int
    {
        if ($scopeDays < 0 || $scopeDays > 366) {
            throw new InvalidArgumentException('Positioning scope_days must be between 0 and 366.');
        }

        return $scopeDays;
    }

    private function strength(mixed $value): ?float
    {
        $strength = $this->finiteNumber($value, 'strength');
        if ($strength !== null && ($strength < 0 || $strength > 1)) {
            throw new InvalidArgumentException('Positioning strength must be between 0 and 1.');
        }

        return $strength;
    }

    private function sign(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! in_array($value, [-1, 0, 1], true)) {
            throw new InvalidArgumentException('Positioning gamma sign must be -1, 0, 1, or null.');
        }

        return $value;
    }

    private function finiteNumber(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new InvalidArgumentException("Positioning {$field} must be finite or null.");
        }

        return (float) $value;
    }

    private function missingTable(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        if (in_array($sqlState, ['42S02', '42P01'], true)) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'no such table: positioning_regimes');
    }
}
