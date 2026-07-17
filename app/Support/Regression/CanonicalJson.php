<?php

namespace App\Support\Regression;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Traversable;

final class CanonicalJson
{
    /**
     * Normalize a value for stable JSON comparison.
     *
     * Object keys are always sorted. Lists keep their order unless their
     * JSON path is explicitly listed as unordered.
     *
     * @param  array<int,string>  $unorderedPaths
     */
    public static function normalize(mixed $value, array $unorderedPaths = [], string $path = '$'): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(
                fn (mixed $item): mixed => self::normalize($item, $unorderedPaths, $path.'[]'),
                $value
            );

            if (self::pathIsUnordered($path, $unorderedPaths)) {
                usort($normalized, fn (mixed $left, mixed $right): int => strcmp(
                    self::encode($left),
                    self::encode($right)
                ));
            }

            return array_values($normalized);
        }

        ksort($value, SORT_STRING);

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize(
                $item,
                $unorderedPaths,
                $path.'.'.self::escapePathSegment((string) $key)
            );
        }

        return $normalized;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    public static function sortRows(array $rows): array
    {
        $rows = array_map(
            fn (array $row): array => self::normalize($row),
            $rows
        );

        usort($rows, fn (array $left, array $right): int => strcmp(
            self::encode($left),
            self::encode($right)
        ));

        return array_values($rows);
    }

    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode(self::normalize($value), $flags);
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private static function pathIsUnordered(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $quoted = preg_quote($pattern, '/');
            $regex = '/^'.str_replace('\\*', '[^.\\[\\]]+', $quoted).'$/';

            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function escapePathSegment(string $segment): string
    {
        return str_replace(['\\', '.'], ['\\\\', '\\.'], $segment);
    }
}
