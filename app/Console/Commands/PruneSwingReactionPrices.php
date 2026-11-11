<?php

namespace App\Console\Commands;

use App\Services\SwingReactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneSwingReactionPrices extends Command
{
    protected $signature = 'swing:prune-prices';

    protected $description = 'Prune old shared swing price evidence; preserve plans, reviews and Study results';

    public function handle(): int
    {
        if (! Schema::hasTable('swing_reaction_prices')) {
            return self::SUCCESS;
        }
        $deleted = 0;
        // A bounded maintenance task. Even a delayed open scenario protects its own evidence.
        for ($batch = 0; $batch < 5; $batch++) {
            $query = DB::table('swing_reaction_prices')->where('bar_at', '<', now('UTC')->subDays(90)->toDateTimeString())
                ->whereNotExists(fn ($ideas) => $ideas->selectRaw('1')->from('swing_reaction_ideas')
                    ->whereColumn('swing_reaction_ideas.symbol', 'swing_reaction_prices.symbol')
                    ->whereIn('swing_reaction_ideas.status', SwingReactionService::ACTIVE)
                    ->whereColumn('swing_reaction_ideas.created_at', '<=', 'swing_reaction_prices.bar_at'));
            $keys = (clone $query)->orderBy('bar_at')->limit(2000)->get(['symbol', 'bar_at']);
            if ($keys->isEmpty()) {
                break;
            }
            foreach ($keys->groupBy('symbol') as $symbol => $rows) {
                $deleted += (clone $query)->where('symbol', $symbol)->whereIn('bar_at', $rows->pluck('bar_at'))->delete();
            }
        }
        $this->info($deleted.' old price observations removed; idea and Study history retained.');

        return self::SUCCESS;
    }
}
