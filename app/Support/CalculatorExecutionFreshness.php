<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Cheap execution-time proof from published heads, without loading contract rows. */
final class CalculatorExecutionFreshness
{
    public function isFresh(string $symbol, ?string $expiry = null, ?CarbonInterface $at = null): bool
    {
        $cutoff = ($at ? CarbonImmutable::instance($at) : CarbonImmutable::now('UTC'))
            ->subMinutes(max(1, (int) config('calculator.scheduler.fresh_minutes', 10)));
        if ($expiry === null) {
            return DB::table('calculator_catalog_heads as head')
                ->join('calculator_publication_runs as publication', 'publication.id', '=', 'head.current_run_id')
                ->where('head.symbol', Symbols::canon($symbol))
                ->where('publication.status', 'complete')
                ->where('publication.completed_at', '>', $cutoff)
                ->exists();
        }

        return DB::table('calculator_expiry_heads as head')
            ->join('calculator_expiry_publications as publication', 'publication.id', '=', 'head.current_publication_id')
            ->where('head.symbol', Symbols::canon($symbol))->where('head.expiration', $expiry)
            ->where('publication.created_at', '>', $cutoff)->exists();
    }
}
