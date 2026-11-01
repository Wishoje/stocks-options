<?php

namespace App\Console\Commands;

use App\Services\SwingDataSource;
use App\Services\SwingIdeaService;
use App\Support\CoordinationCache;
use App\Support\Swing\PausedSwingPlan;
use App\Support\Swing\SwingIdeaRules;
use App\Support\Symbols;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshSwingIdeas extends Command
{
    protected $signature = 'swing:refresh {--symbols= : Comma-separated symbols, at most 20} {--watchlist : Process an outdated saved-symbol batch} {--limit=10 : Maximum watchlist symbols per run, at most 20}';

    protected $description = 'Prepare swing research plans from existing completed EOD data; no provider fetches or notifications';

    public function handle(SwingDataSource $source, SwingIdeaService $service): int
    {
        $symbols = array_values(array_unique(array_filter(array_map(fn ($s) => Symbols::canon(trim($s)), explode(',', (string) $this->option('symbols'))))));
        if ($this->option('watchlist') && $symbols) {
            $this->error('Choose explicit symbols or a watchlist batch.');

            return self::FAILURE;
        }
        if ($this->option('watchlist')) {
            $date = \App\Support\PublicSpyPreview::completedSession();
            $limit = max(1, min(20, (int) $this->option('limit')));
            $known = DB::table('watchlists as saved')->where(function ($due) use ($date) {
                foreach (array_keys(SwingIdeaRules::HORIZONS) as $horizon) {
                    $due->orWhereNotExists(fn ($q) => $q->selectRaw('1')->from('swing_ideas')
                        ->whereColumn('swing_ideas.symbol', 'saved.symbol')->where('rule_version', SwingIdeaRules::VERSION)
                        ->where('horizon', $horizon)->where(fn ($review) => $review->where('reviewed_through', '>=', $date)
                        ->orWhere('status', 'needs_update')));
                }
                // A current publication must not hide an older plan still awaiting its daily review.
                $due->orWhereExists(fn ($q) => $q->selectRaw('1')->from('swing_ideas')
                    ->whereColumn('swing_ideas.symbol', 'saved.symbol')->where('rule_version', SwingIdeaRules::VERSION)
                    ->whereIn('status', ['watching', 'conditions_met', 'in_progress'])->where('reviewed_through', '<', $date));
            })->orderBy('symbol')->distinct()->limit(500)->pluck('symbol')->all();
            $known = array_values(array_unique([...$known, ...$this->replaceablePausedSymbols($date)]));
            sort($known);
            $keys = array_map(fn ($s) => 'swing:attempt:'.$date.':'.$s, $known);
            $attempts = $keys ? Cache::many($keys) : [];
            $symbols = array_slice(array_values(array_filter($known, fn ($s) => Symbols::isValid($s)
                && $s === Symbols::canon($s) && empty($attempts['swing:attempt:'.$date.':'.$s]))), 0, $limit);
            if (! $symbols) {
                $this->line('No saved-symbol refresh is due.');

                return self::SUCCESS;
            }
        }
        if (! $symbols || count($symbols) > 20 || array_filter($symbols, fn ($s) => ! Symbols::isValid($s))) {
            $this->error('Choose between one and twenty valid symbols.');

            return self::FAILURE;
        }
        $lock = CoordinationCache::store()->lock('swing:refresh:v1', 180);
        if (! $lock->get()) {
            $this->warn('Another swing refresh is running.');

            return self::FAILURE;
        }
        $started = microtime(true);
        $failed = false;
        try {
            foreach ($symbols as $symbol) {
                if (microtime(true) - $started > 90) {
                    $this->warn('Refresh budget reached; run the remaining symbols separately.');

                    return self::FAILURE;
                }
                try {
                    if ($this->option('watchlist')) {
                        Cache::put('swing:attempt:'.\App\Support\PublicSpyPreview::completedSession().':'.$symbol, true, now()->addMinutes(30));
                    }
                    $input = $source->read($symbol);
                    $ids = $service->refresh($input);
                    $this->line($symbol.' · '.$input['data_date'].' · '.count($ids).' new plan records');
                } catch (\Throwable $error) {
                    $this->warn($symbol.' · Not published: '.get_class($error));
                    $failed = true;
                }
            }
        } finally {
            $lock->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function replaceablePausedSymbols(string $date): array
    {
        $paused = DB::table('swing_ideas')->where('rule_version', SwingIdeaRules::VERSION)->where('status', 'needs_update')
            ->whereIn('symbol', DB::table('watchlists')->select('symbol')->distinct())
            ->orderBy('symbol')->orderBy('horizon')->orderByDesc('source_date')->limit(500)
            ->get(['symbol', 'horizon', 'current_state']);
        if ($paused->isEmpty()) {
            return [];
        }

        $current = DB::table('swing_ideas')->where('rule_version', SwingIdeaRules::VERSION)
            ->whereIn('symbol', $paused->pluck('symbol')->unique())->where('reviewed_through', '>=', $date)
            ->get(['symbol', 'horizon'])->mapWithKeys(fn ($row) => [$row->symbol.':'.$row->horizon => true]);
        $symbols = [];
        foreach ($paused as $row) {
            $state = json_decode($row->current_state, true);
            if (! isset($current[$row->symbol.':'.$row->horizon]) && is_array($state)
                && PausedSwingPlan::replaceable($state, $date)) {
                $symbols[] = $row->symbol;
            }
        }

        return array_values(array_unique($symbols));
    }
}
