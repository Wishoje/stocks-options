<?php

namespace App\Console\Commands;

use App\Models\Watchlist;
use App\Services\WallInteractionService;
use App\Support\Symbols;
use Illuminate\Console\Command;

final class RefreshWallSetupReviews extends Command
{
    protected $signature = 'walls:refresh-reviews {--symbol=* : Limit to these saved-data symbols} {--session= : Session date; defaults to today in ET}';

    protected $description = 'Refresh setup follow-up from existing wall observations and price bars without provider requests';

    public function handle(WallInteractionService $service): int
    {
        $session = $this->option('session') ?: now('America/New_York')->toDateString();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $session);
        if (! $date || $date->format('Y-m-d') !== $session || $session > now('America/New_York')->toDateString()) {
            $this->error('Use a valid completed or current session date.');

            return self::FAILURE;
        }
        $requested = $this->option('symbol');
        $symbols = $requested ?: Watchlist::query()->distinct()->orderBy('symbol')->pluck('symbol')->all();
        $symbols = array_values(array_unique(array_map(fn ($symbol) => Symbols::canon($symbol), $symbols)));
        if (count($symbols) > 500 || array_filter($symbols, fn ($symbol) => ! Symbols::isValid($symbol))) {
            $this->error('Use up to 500 valid symbols per run.');

            return self::FAILURE;
        }
        $counts = [];
        foreach ($symbols as $symbol) {
            $result = $service->refreshReviewsFromRecorded($symbol, $session);
            $status = $result['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $this->line($symbol.': '.json_encode($result, JSON_THROW_ON_ERROR));
        }
        $this->info(json_encode(['session' => $session, 'symbols' => count($symbols), 'counts' => $counts], JSON_THROW_ON_ERROR));

        return isset($counts['busy']) || isset($counts['observation_limit']) ? self::FAILURE : self::SUCCESS;
    }
}
