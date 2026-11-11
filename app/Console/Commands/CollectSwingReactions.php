<?php

namespace App\Console\Commands;

use App\Services\SwingDataSource;
use App\Services\SwingReactionService;
use App\Support\CoordinationCache;
use App\Support\ProductUniverse;
use App\Support\PublicSpyPreview;
use App\Support\Swing\SwingReactionRules;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollectSwingReactions extends Command
{
    protected $signature = 'swing:react {--symbols= : Explicit comma-separated symbols} {--all : Scan the whole universe, within the time budget} {--limit=60 : Rotating batch size, at most 200}';

    protected $description = 'Prepare shared wall-reaction swing ideas and follow observed touches from saved price bars';

    public function handle(SwingDataSource $source, SwingReactionService $service): int
    {
        if (! $service->prepared()) {
            $this->error('Apply the swing-reaction migration before collection.');

            return self::FAILURE;
        }
        $explicit = array_values(array_unique(array_filter(array_map(fn ($s) => Symbols::canon(trim($s)), explode(',', (string) $this->option('symbols'))))));
        if (count($explicit) > 200 || array_filter($explicit, fn ($s) => ! Symbols::isValid($s))) {
            $this->error('Choose at most 200 valid symbols.');

            return self::FAILURE;
        }
        $lock = CoordinationCache::store()->lock('swing:reaction:collect:v2', 180);
        if (! $lock->get()) {
            $this->info('Another swing reaction batch is running.');

            return self::SUCCESS;
        }
        try {
            $universe = ProductUniverse::symbols();
            $open = DB::table('swing_reaction_ideas')->where('rule_version', SwingReactionRules::VERSION)
                ->whereIn('status', SwingReactionService::ACTIVE)->distinct()->pluck('symbol')->all();
            $symbols = $explicit ?: array_values(array_unique([...$universe, ...$open]));
            sort($symbols, SORT_STRING);
            $cursor = $explicit || ! $symbols ? 0 : (int) Cache::get('swing:reaction:cursor:v2', 0) % count($symbols);
            $limit = $this->option('all') ? count($symbols) : max(1, min(200, (int) $this->option('limit')));
            $selected = array_slice([...$symbols, ...$symbols], $cursor, min($limit, count($symbols)));
            $result = ['symbols' => 0, 'universe' => count($universe), 'created' => 0, 'changed' => 0, 'pinned' => 0, 'failed' => 0, 'seeded' => 0];
            $start = microtime(true);
            foreach ($selected as $symbol) {
                if (microtime(true) - $start > 75) {
                    $this->warn('Time budget reached; the next batch resumes the symbol rotation.');
                    break;
                }
                $result['symbols']++;
                try {
                    $now = CarbonImmutable::now('UTC');
                    // Observe the previous session before a newer structure can withdraw an unentered idea.
                    $follow = $service->follow($symbol, $now);
                    $result['changed'] += $follow['changed'];
                    $result['pinned'] += $follow['pinned'];
                    $key = 'swing:reaction:seed:'.SwingReactionRules::VERSION.':'.PublicSpyPreview::completedSession().':'.$symbol;
                    if (in_array($symbol, $universe, true) && ! Cache::has($key)) {
                        // One EOD read supplies both horizons and strategies. No provider request.
                        try {
                            $input = $source->read($symbol);
                            $result['created'] += $service->seed($input, $now);
                            Cache::put($key, 'prepared', $now->addDays(3));
                            $result['seeded']++;
                        } catch (\Throwable $error) {
                            Cache::put($key, 'retry_later', $now->addMinutes(15));
                            Log::warning('swing-reaction.seed-unavailable', ['symbol' => $symbol, 'exception' => get_class($error)]);
                            $this->warn($symbol.': current structure unavailable ('.class_basename($error).'); continuing price follow-up.');
                            $result['failed']++;
                        }
                    }
                } catch (\Throwable $error) {
                    $result['failed']++;
                    report($error);
                    $this->warn($symbol.': collection failed ('.class_basename($error).').');
                }
            }
            if (! $explicit && $symbols) {
                Cache::put('swing:reaction:cursor:v2', ($cursor + $result['symbols']) % count($symbols), now()->addDays(7));
            }
            Cache::put('swing:reaction:last:v2', [...$result, 'at' => now('UTC')->toIso8601String()], now()->addDays(7));
            $this->line(json_encode($result));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
