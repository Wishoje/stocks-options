<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class IntradayOptionCounterWriter
{
    /** Preserve newer strike data when an older complete response arrives late. */
    public function upsert(array $rows): void
    {
        $driver = DB::connection()->getDriverName();
        $updates = [];
        // Assign asof last so all MySQL assignments compare with the stored time.
        foreach (['volume', 'premium_usd', 'updated_at', 'asof'] as $column) {
            $incoming = $driver === 'mysql' ? 'VALUES(`'.$column.'`)' : 'excluded."'.$column.'"';
            $current = $driver === 'mysql' ? '`'.$column.'`' : '"option_live_counters"."'.$column.'"';
            $incomingTime = $driver === 'mysql' ? 'VALUES(`asof`)' : 'excluded."asof"';
            $currentTime = $driver === 'mysql' ? '`asof`' : '"option_live_counters"."asof"';
            $updates[$column] = DB::raw('CASE WHEN '.$currentTime.' IS NULL OR '
                .$incomingTime.' >= '.$currentTime.' THEN '.$incoming.' ELSE '.$current.' END');
        }

        $size = max(1, min(1000, (int) config('intraday_ingestion.chunk_size', 250)));
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table('option_live_counters')->upsert(
                $chunk, ['symbol', 'trade_date', 'exp_date', 'strike', 'option_type'], $updates
            );
        }
    }
}
