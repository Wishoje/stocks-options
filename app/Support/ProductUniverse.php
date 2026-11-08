<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class ProductUniverse
{
    /** Shared coverage includes configured symbols and every customer's saved symbols. @return list<string> */
    public static function symbols(?int $userId = null): array
    {
        $query = DB::table('watchlists')->whereNotNull('symbol')->whereRaw("TRIM(symbol) <> ''")
            ->selectRaw('UPPER(TRIM(symbol)) as symbol')->distinct()->orderBy('symbol');
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return self::canonical([
            ...$query->pluck('symbol')->all(),
            ...($userId === null ? self::configuredSymbols() : []),
        ]);
    }

    /** @return list<string> */
    public static function configuredSymbols(): array
    {
        return config('product_universe.enabled', true)
            ? self::canonical((array) config('product_universe.symbols', []))
            : [];
    }

    /** @return list<string> */
    private static function canonical(array $symbols): array
    {
        $result = [];
        foreach ($symbols as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $symbol = Symbols::canon($raw);
            if (strlen($symbol) <= 10 && Symbols::isValid($symbol)) {
                $result[$symbol] = true;
            }
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);

        return $result;
    }
}
