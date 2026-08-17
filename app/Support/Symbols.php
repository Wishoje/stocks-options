<?php

namespace App\Support;

final class Symbols
{
    public static function canon(?string $s): string
    {
        return strtoupper(trim($s ?? ''));
    }

    public static function isValid(?string $symbol): bool
    {
        $symbol = self::canon($symbol);

        return $symbol !== ''
            && strlen($symbol) <= 32
            && preg_match('/^[A-Z0-9]+(?:[.-][A-Z0-9]+)*$/D', $symbol) === 1;
    }
}
