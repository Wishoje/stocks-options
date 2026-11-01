<?php

namespace App\Services\SwingResearch;

use App\Models\User;
use App\Services\SwingDataSource;
use App\Support\MarketSession;
use App\Support\PublicSpyPreview;
use App\Support\SetupStudy\SetupStudyAccess;
use App\Support\SetupStudy\SetupStudyMetrics;
use App\Support\Swing\SwingIdeaRules;
use App\Support\SwingResearch\SwingResearchRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Private paired experiment over retained EOD evidence. No provider calls or jobs. */
class SwingResearchService
{
    private const OPEN = ['watching', 'conditions_met', 'in_progress'];

    public function collect(int $ownerId, int $limit = 6): array
    {
        $this->assertOwner($ownerId);
        if (! $this->prepared()) {
            return ['prepared' => false, 'symbols' => 0, 'groups' => 0, 'warnings' => ['Apply the swing-research migration before collection.']];
        }
        $lock = Cache::lock('swing-research:collect:'.$ownerId, 180);
        if (! $lock->get()) {
            return ['prepared' => true, 'busy' => true, 'symbols' => 0, 'groups' => 0, 'warnings' => ['Research collection is already running.']];
        }
        try {
            $symbols = DB::table('watchlists')->where('user_id', $ownerId)->distinct()->orderBy('symbol')->pluck('symbol')->all();
            $savedSymbols = $symbols;
            $openSymbols = DB::table('swing_research_groups as groups')->join('swing_research_cases as cases', 'cases.group_id', '=', 'groups.id')
                ->where('groups.owner_id', $ownerId)->where('groups.source_date', '>=', now()->subDays(180)->toDateString())
                ->whereIn('cases.status', self::OPEN)->distinct()->pluck('groups.symbol')->all();
            $symbols = array_values(array_unique([...$symbols, ...$openSymbols]));
            sort($symbols, SORT_STRING);
            $symbols = array_values(array_filter($symbols, fn ($s) => is_string($s) && preg_match('/^[A-Z0-9.^-]{1,16}$/D', $s)));
            $cursorKey = 'swing-research:cursor:'.$ownerId;
            $cursor = $symbols ? (int) Cache::get($cursorKey, 0) % count($symbols) : 0;
            $selected = array_slice(array_merge($symbols, $symbols), $cursor, min(count($symbols), max(1, min(20, $limit))));
            $completed = PublicSpyPreview::completedSession();
            $started = microtime(true);
            $result = ['prepared' => true, 'symbols' => 0, 'total_symbols' => count($symbols), 'groups' => 0,
                'skipped' => 0, 'failed' => 0, 'warnings' => []];
            foreach ($selected as $symbol) {
                if (microtime(true) - $started >= 90) {
                    $result['warnings'][] = 'The time limit was reached; the next batch resumes the rotation.';
                    break;
                }
                $result['symbols']++;
                $cooldown = 'swing-research:failed:'.$ownerId.':'.$symbol.':'.$completed;
                try {
                    $input = null;
                    if (Cache::has($cooldown)) {
                        $result['skipped']++;
                    } else {
                        try {
                            // A single source read supplies both horizons and all three arms.
                            $candidate = app(SwingDataSource::class)->read($symbol);
                            if (($candidate['symbol'] ?? null) === $symbol && ($candidate['data_date'] ?? null) === $completed) {
                                $input = $candidate;
                            } else {
                                $result['skipped']++;
                                $result['warnings'][] = $symbol.': current completed EOD evidence is not available; no new group was proposed.';
                                Cache::put($cooldown, true, now()->addMinutes(30));
                            }
                        } catch (\Throwable $error) {
                            Cache::put($cooldown, true, now()->addMinutes(30));
                            Log::warning('swing-research.source-failed', ['symbol' => $symbol, 'exception' => get_class($error)]);
                            $result['failed']++;
                            $result['warnings'][] = $symbol.': new proposals are unavailable ('.class_basename($error).'); retained daily follow-up was attempted.';
                        }
                    }
                    $result['groups'] += $this->collectSymbol($ownerId, $symbol, $input, $completed, in_array($symbol, $savedSymbols, true));
                } catch (\Throwable $error) {
                    Cache::put($cooldown, true, now()->addMinutes(30));
                    Log::warning('swing-research.collection-failed', ['symbol' => $symbol, 'exception' => get_class($error)]);
                    $result['failed']++;
                    $result['warnings'][] = $symbol.': collection failed ('.class_basename($error).').';
                }
            }
            Cache::put($cursorKey, $symbols ? ($cursor + $result['symbols']) % count($symbols) : 0, now()->addDays(30));
            $result['warnings'] = array_values(array_unique($result['warnings']));
            $result['collected_at'] = now('UTC')->toIso8601String();
            Cache::put('swing-research:last:'.$ownerId, $result, now()->addDays(7));

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function collectSymbol(int $ownerId, string $symbol, ?array $input, string $completed, bool $canSeed): int
    {
        $rules = app(SwingResearchRules::class);
        $hash = $input === null ? null : hash('sha256', $this->json($input));

        return DB::transaction(function () use ($ownerId, $symbol, $input, $completed, $rules, $hash, $canSeed) {
            $groups = DB::table('swing_research_groups')->where('owner_id', $ownerId)->where('symbol', $symbol)
                ->where('source_date', '>=', now()->subDays(180)->toDateString())->orderBy('source_date')->limit(400)->lockForUpdate()->get();
            if ($input === null && $groups->isEmpty()) {
                return 0;
            }
            $cases = DB::table('swing_research_cases')->whereIn('group_id', $groups->pluck('id'))->lockForUpdate()->get()->groupBy('group_id');
            [$bars, $changed, $observed] = $this->pinBars($ownerId, $symbol, $input['prices'] ?? [], $completed);
            foreach ($groups as $group) {
                $initial = array_column(json_decode($group->context, true, flags: JSON_THROW_ON_ERROR)['initial_prices'] ?? [], null, 'date');
                foreach ($cases->get($group->id, collect()) as $case) {
                    $state = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
                    $lastRelevant = $state['outcome_at'] ?? ($state['reviewed_through'] ?? $case->reviewed_through);
                    $corrected = $input !== null && $group->source_date === $completed && $group->input_hash !== $hash;
                    foreach ($changed as $date) {
                        // A newer cohort may legitimately start from a revised lookback bar.
                        $initialChanged = isset($initial[$date]) && $this->normalBar($initial[$date]) !== ($observed[$date] ?? null);
                        $corrected = $corrected || $initialChanged || ($date > $group->source_date && $date <= $lastRelevant);
                    }
                    if ($corrected) {
                        $state = [...$state, 'status' => 'needs_update', 'correction_detected' => true,
                            'reason' => 'Retained evidence changed. The original plan and daily prices are preserved for review.'];
                    }
                    if (in_array($state['status'], self::OPEN, true)) {
                        $expected = max(SwingIdeaRules::sessionAfter($case->reviewed_through, 1), $state['activation_session']);
                        foreach ($bars as $date => $bar) {
                            if ($date < $expected) {
                                continue;
                            }
                            if ($date !== $expected) {
                                $state = [...$state, 'status' => 'needs_update', 'evidence_gap' => true,
                                    'reason' => 'A completed daily bar is missing. This paired follow-up needs review.'];
                                break;
                            }
                            if ($this->closeAt($date)->lte(CarbonImmutable::parse($group->created_at, 'UTC'))) {
                                continue;
                            }
                            if ($date === $state['activation_session'] && SwingIdeaRules::sessionAfter($state['reviewed_through'], 1) !== $date) {
                                // An intraday publication excludes that day's pre-publication range.
                                // Move only the rules cursor past ineligible pre-activation sessions.
                                $state['reviewed_through'] = MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($date, 'America/New_York')->subDay());
                            }
                            $state = $rules->advance($state, $bar);
                            $expected = SwingIdeaRules::sessionAfter($date, 1);
                            if (! in_array($state['status'], self::OPEN, true)) {
                                break;
                            }
                        }
                    }
                    $signal = $case->signal_recorded_at;
                    if (! $signal && ! empty($state['qualified_at'])) {
                        $signal = now('UTC')->toDateTimeString();
                    }
                    $stateJson = $this->json($state);
                    if ($stateJson !== $case->current_state || $signal !== $case->signal_recorded_at) {
                        DB::table('swing_research_cases')->where('id', $case->id)->update(['current_state' => $stateJson,
                            'status' => $state['status'], 'reviewed_through' => $state['reviewed_through'] ?? $case->reviewed_through,
                            'signal_recorded_at' => $signal, 'updated_at' => now()]);
                    }
                }
            }
            if ($input === null || ! $canSeed) {
                return 0;
            }
            $created = 0;
            foreach (['short', 'position'] as $horizon) {
                $sameDate = $groups->contains(fn ($group) => $group->horizon === $horizon && $group->source_date === $completed
                    && $group->research_version === SwingResearchRules::VERSION);
                $active = DB::table('swing_research_cases as cases')->join('swing_research_groups as groups', 'groups.id', '=', 'cases.group_id')
                    ->where('groups.owner_id', $ownerId)->where('groups.symbol', $input['symbol'])->where('groups.horizon', $horizon)
                    ->where('groups.research_version', SwingResearchRules::VERSION)->whereIn('cases.status', self::OPEN)->exists();
                if ($sameDate || $active) {
                    continue;
                }
                $groupId = hash('sha256', $this->json([$ownerId, $input['symbol'], $horizon, $completed, SwingResearchRules::VERSION]));
                $context = ['initial_prices' => array_slice($input['prices'] ?? [], -80), 'source_version' => $input['source_version'] ?? null,
                    'volatility_version' => $input['volatility_version'] ?? null, 'units' => $input['units'] ?? null,
                    'published_at' => now('UTC')->toIso8601String()];
                DB::table('swing_research_groups')->insert(['id' => $groupId, 'owner_id' => $ownerId, 'symbol' => $input['symbol'],
                    'horizon' => $horizon, 'source_date' => $completed, 'research_version' => SwingResearchRules::VERSION,
                    'input_hash' => $hash, 'context' => $this->json($context), 'created_at' => now(), 'updated_at' => now()]);
                foreach (array_keys(SwingResearchRules::variants()) as $variant) {
                    $plan = $rules->propose($input, $horizon, $variant);
                    $plan['activation_session'] = CarbonImmutable::parse(MarketSession::describe()['next_open_at'])->setTimezone('America/New_York')->toDateString();
                    $plan['first_recorded_at'] = now('UTC')->toIso8601String();
                    $plan['reviewed_through'] = $completed;
                    if ($plan['status'] === 'watching' && $plan['activation_session'] > $plan['entry_deadline']) {
                        $plan['status'] = 'no_idea';
                        $plan['reason'] = 'The entry window ended before publication could activate this plan.';
                    }
                    $json = $this->json($plan);
                    DB::table('swing_research_cases')->insert(['id' => hash('sha256', $groupId.':'.$variant), 'group_id' => $groupId,
                        'variant' => $variant, 'status' => $plan['status'], 'original_plan' => $json, 'current_state' => $json,
                        'reviewed_through' => $completed, 'created_at' => now(), 'updated_at' => now()]);
                }
                $created++;
            }

            return $created;
        });
    }

    /** First observed OHLC stays immutable. Later values are compact revision evidence. */
    private function pinBars(int $ownerId, string $symbol, array $sourcePrices, string $completed): array
    {
        $observed = [];
        $prices = DB::table('prices_daily')->where('symbol', $symbol)->where('trade_date', '<=', $completed)
            ->orderByDesc('trade_date')->limit(260)->get(['trade_date', 'open', 'high', 'low', 'close']);
        foreach ($prices as $price) {
            $observed[$price->trade_date] = ['date' => $price->trade_date, 'open' => $price->open, 'high' => $price->high, 'low' => $price->low, 'close' => $price->close];
        }
        foreach ($sourcePrices as $bar) {
            if ($bar['date'] <= $completed) {
                $observed[$bar['date']] ??= $bar;
            }
        }
        $pinned = DB::table('swing_research_daily_bars')->where('owner_id', $ownerId)->where('symbol', $symbol)
            ->whereIn('trade_date', array_keys($observed))->get()->keyBy('trade_date');
        $changed = [];
        foreach ($observed as $date => $bar) {
            $bar = $this->normalBar($bar);
            $observed[$date] = $bar;
            if (! $bar) {
                $changed[] = $date;

                continue;
            }
            $json = $this->json($bar);
            $hash = hash('sha256', $json);
            $existing = $pinned->get($date);
            if (! $existing) {
                DB::table('swing_research_daily_bars')->insertOrIgnore(['owner_id' => $ownerId, 'symbol' => $symbol,
                    'trade_date' => $date, 'ohlc' => $json, 'evidence_hash' => $hash, 'first_recorded_at' => now()]);

                continue;
            }
            if ($existing->evidence_hash !== $hash || $existing->revision !== null) {
                $changed[] = $date;
            }
            if ($existing->evidence_hash !== $hash) {
                $revision = $existing->revision ? json_decode($existing->revision, true, flags: JSON_THROW_ON_ERROR) : [];
                $revision['first_detected_at'] ??= now('UTC')->toIso8601String();
                $revision['last_observed_at'] = now('UTC')->toIso8601String();
                $revision['latest_hash'] = $hash;
                $revision['latest_ohlc'] = $bar;
                DB::table('swing_research_daily_bars')->where('id', $existing->id)->update(['revision' => $this->json($revision)]);
            }
        }
        $bars = DB::table('swing_research_daily_bars')->where('owner_id', $ownerId)->where('symbol', $symbol)
            ->where('trade_date', '<=', $completed)->orderByDesc('trade_date')->limit(260)->get()->reverse()
            ->mapWithKeys(fn ($bar) => [$bar->trade_date => json_decode($bar->ohlc, true, flags: JSON_THROW_ON_ERROR)])->all();

        return [$bars, $changed, $observed];
    }

    /** GET requests read only the compact archive, without source calls or writes. */
    public function report(int $ownerId, array $filters): array
    {
        $this->assertOwner($ownerId);
        $prepared = $this->prepared();
        $warnings = [];
        $groups = $measurements = [];
        $withoutPlanCounts = [];
        $reviewedGroups = $withoutPlanGroups = 0;
        if ($prepared) {
            $query = DB::table('swing_research_groups')->where('owner_id', $ownerId)->where('horizon', $filters['horizon'])
                ->whereBetween('source_date', [$filters['from'], $filters['to']]);
            if (! empty($filters['symbol'])) {
                $query->where('symbol', $filters['symbol']);
            }
            $reviewedGroups = (clone $query)->count();
            $hasMaterialCase = fn ($cases) => $cases->selectRaw('1')->from('swing_research_cases as material')
                ->whereColumn('material.group_id', 'swing_research_groups.id')->where('material.status', '!=', 'no_idea');
            $withoutPlans = (clone $query)->whereNotExists($hasMaterialCase);
            $withoutPlanGroups = (clone $withoutPlans)->count();
            $withoutPlanCounts = DB::table('swing_research_cases')->whereIn('group_id', $withoutPlans->select('id'))
                ->select('variant')->selectRaw('COUNT(*) as total')->groupBy('variant')->pluck('total', 'variant')->all();
            $rows = $query->whereExists($hasMaterialCase)->orderByDesc('source_date')->orderByDesc('created_at')->limit(1001)
                ->get(['id', 'symbol', 'horizon', 'source_date', 'research_version', 'created_at']);
            if ($rows->count() > 1000) {
                $warnings[] = 'Detailed outcomes are limited to 1,000 paired groups with a plan or review. All no-plan groups remain in the totals. Narrow the dates or select a symbol.';
            }
            $rows = $rows->take(1000);
            $cases = DB::table('swing_research_cases')->whereIn('group_id', $rows->pluck('id'))->get()->groupBy('group_id');
            $lastFollowup = $rows->isEmpty() ? PublicSpyPreview::completedSession()
                : min(PublicSpyPreview::completedSession(), CarbonImmutable::parse($rows->max('source_date'))->addDays(45)->toDateString());
            $prices = $rows->isEmpty() ? collect() : DB::table('swing_research_daily_bars')->where('owner_id', $ownerId)
                ->whereIn('symbol', $rows->pluck('symbol')->unique())->where('trade_date', '>=', $rows->min('source_date'))
                ->where('trade_date', '<=', $lastFollowup)->orderBy('trade_date')->get()->groupBy('symbol');
            $latestDates = DB::table('swing_research_daily_bars')->where('owner_id', $ownerId)
                ->whereIn('symbol', $rows->pluck('symbol')->unique())->where('trade_date', '<=', PublicSpyPreview::completedSession())
                ->groupBy('symbol')->select('symbol')->selectRaw('MAX(trade_date) as latest_date');
            $latestPrices = $rows->isEmpty() ? collect() : DB::table('swing_research_daily_bars as bars')
                ->joinSub($latestDates, 'latest', fn ($join) => $join->on('latest.symbol', '=', 'bars.symbol')->on('latest.latest_date', '=', 'bars.trade_date'))
                ->where('bars.owner_id', $ownerId)->get(['bars.symbol', 'bars.ohlc'])->keyBy('symbol');
            foreach ($rows as $group) {
                $groupCases = [];
                $symbolBars = $prices->get($group->symbol, collect());
                foreach ($cases->get($group->id, collect()) as $case) {
                    $state = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
                    $measurement = $this->measurement($group, $case, $state, $symbolBars);
                    $measurements[$case->variant][] = $measurement;
                    $last = $latestPrices->get($group->symbol);
                    $bar = $last ? json_decode($last->ohlc, true, flags: JSON_THROW_ON_ERROR) : null;
                    $levels = $state['levels'] ?? [];
                    $latest = $bar ? ['date' => $bar['date'], 'close' => $bar['close'],
                        'distance_to_entry_pct' => isset($levels['entry_low'], $levels['entry_high']) && $bar['close'] > 0
                            ? 100 * max($levels['entry_low'] - $bar['close'], $bar['close'] - $levels['entry_high'], 0) / $bar['close'] : null] : null;
                    $groupCases[] = ['id' => $case->id, 'variant' => $case->variant, 'label' => SwingResearchRules::variants()[$case->variant]['label'],
                        'status' => $case->status, 'state' => $state, 'original_target' => $state['original_target'] ?? $levels['target'] ?? null,
                        'latest_price' => $latest, 'signal_recorded_at' => $this->iso($case->signal_recorded_at), 'alert_read_at' => $this->iso($case->alert_read_at),
                        'metrics' => [...$measurement['metrics'], 'qualified' => $measurement['qualified'], 'followup_complete' => $measurement['followup_complete'], 'needs_update' => $measurement['needs_update']]];
                }
                $groups[] = ['id' => $group->id, 'symbol' => $group->symbol, 'horizon' => $group->horizon,
                    'source_date' => $group->source_date, 'created_at' => $this->iso($group->created_at), 'cases' => $groupCases];
            }
        } else {
            $warnings[] = 'Research storage is not installed yet. Apply the migration before collection.';
        }
        $summary = [];
        foreach (SwingResearchRules::variants() as $variant => $metadata) {
            $items = $measurements[$variant] ?? [];
            $completed = array_values(array_filter($items, fn ($i) => $i['qualified'] && $i['followup_complete'] && ! $i['needs_update']));
            $hits = count(array_filter($completed, fn ($i) => $i['metrics']['strict_outcome'] === 'target_reached'));
            $average = function ($key) use ($completed) {
                $values = array_values(array_filter(array_column(array_column($completed, 'metrics'), $key), fn ($v) => is_numeric($v) && is_finite((float) $v)));

                return $values ? round(array_sum($values) / count($values), 3) : null;
            };
            $initialDistances = array_values(array_filter(array_column(array_column($items, 'metrics'), 'initial_entry_distance_pct'),
                fn ($value) => is_numeric($value) && is_finite((float) $value)));
            $summary[] = ['variant' => $variant, 'total' => count($items) + (int) ($withoutPlanCounts[$variant] ?? 0), 'watching' => count(array_filter($items, fn ($i) => $i['native_status'] === 'watching')),
                'qualified' => count(array_filter($items, fn ($i) => $i['qualified'])),
                'in_progress' => count(array_filter($items, fn ($i) => $i['qualified'] && in_array($i['native_status'], self::OPEN, true))),
                'completed' => count($completed), 'needs_review' => count(array_filter($items, fn ($i) => $i['needs_update'])), 'target_reached' => $hits,
                'invalidated' => count(array_filter($completed, fn ($i) => $i['metrics']['strict_outcome'] === 'invalidated')),
                'ended' => count(array_filter($completed, fn ($i) => $i['metrics']['strict_outcome'] === 'holding_ended')),
                'target_rate' => ['hits' => $hits, 'eligible' => count($completed), 'rate' => $completed ? round(100 * $hits / count($completed), 1) : null],
                'avg_mfe_pct' => $average('mfe_pct'), 'avg_mae_pct' => $average('mae_pct'),
                'avg_outcome_move_pct' => $average('outcome_move_pct'),
                'avg_initial_entry_distance_pct' => $initialDistances ? round(array_sum($initialDistances) / count($initialDistances), 3) : null];
        }
        $collection = Cache::get('swing-research:last:'.$ownerId, []);

        return ['prepared' => $prepared, 'enabled' => (bool) config('swing_research.enabled'), 'demonstration' => false,
            'filters' => $filters, 'variants' => SwingResearchRules::variants(), 'summary' => $summary, 'groups' => $groups,
            'reviewed_groups' => $reviewedGroups, 'without_plan_groups' => $withoutPlanGroups,
            'collection' => $collection, 'warnings' => array_values(array_unique([...$warnings, ...($collection['warnings'] ?? [])]))];
    }

    private function measurement(object $group, object $case, array $state, $bars): array
    {
        $levels = $state['levels'] ?? [];
        $sample = ['id' => $case->id, 'mode' => 'swing', 'horizon' => $group->horizon, 'symbol' => $group->symbol,
            'session' => $group->source_date, 'rule_version' => $group->research_version, 'direction' => $state['direction'] ?? null,
            'native_status' => $case->status, 'signal_at' => empty($state['qualified_at']) ? null : $this->closeAt($state['qualified_at'])->toIso8601String(),
            'signal_price' => $state['qualified_price'] ?? null, 'available_at' => $this->iso($case->signal_recorded_at),
            'review_until' => empty($state['deadline']) ? null : $this->closeAt($state['deadline'])->toIso8601String(),
            'target' => $levels['target'] ?? null, 'invalidation' => $levels['invalidation'] ?? null,
            'entry_low' => $levels['entry_low'] ?? null, 'entry_high' => $levels['entry_high'] ?? null, 'confirmations' => [],
            'bars' => $bars->map(function ($row) {
                return [...json_decode($row->ohlc, true, flags: JSON_THROW_ON_ERROR), 'at' => $this->closeAt($row->trade_date)->toIso8601String()];
            })->all()];
        $measurement = (new SetupStudyMetrics)->measure($sample);
        if ($case->status === 'needs_update' || ($state['correction_detected'] ?? false) || ($state['evidence_gap'] ?? false)) {
            $measurement = [...$measurement, 'needs_update' => true, 'followup_complete' => false, 'status' => 'needs_update'];
        }
        $measurement['metrics']['outcome_move_pct'] = null;
        if ($measurement['followup_complete'] && ! $measurement['needs_update'] && $measurement['metrics']['strict_at']) {
            $outcomeDate = CarbonImmutable::parse($measurement['metrics']['strict_at'])->setTimezone('America/New_York')->toDateString();
            $outcomeBar = $bars->first(fn ($row) => $row->trade_date === $outcomeDate);
            if ($outcomeBar && is_numeric($state['qualified_price'] ?? null) && $state['qualified_price'] > 0) {
                $close = json_decode($outcomeBar->ohlc, true, flags: JSON_THROW_ON_ERROR)['close'];
                $direction = $state['direction'] === 'bullish' ? 1 : -1;
                $measurement['metrics']['outcome_move_pct'] = round(100 * $direction * ($close - $state['qualified_price']) / $state['qualified_price'], 4);
            }
        }
        $original = json_decode($case->original_plan, true, flags: JSON_THROW_ON_ERROR);
        $originalLevels = $original['levels'] ?? [];
        $initialClose = $original['evidence']['close'] ?? null;
        $measurement['metrics']['initial_entry_distance_pct'] = ($original['status'] ?? null) === 'watching'
            && is_numeric($initialClose) && $initialClose > 0 && isset($originalLevels['entry_low'], $originalLevels['entry_high'])
            ? 100 * max($originalLevels['entry_low'] - $initialClose, $initialClose - $originalLevels['entry_high'], 0) / $initialClose : null;

        return $measurement;
    }

    public function alerts(int $ownerId): array
    {
        $this->assertOwner($ownerId);
        if (! $this->prepared()) {
            return ['items' => [], 'unread_count' => 0, 'truncated' => false];
        }
        $query = DB::table('swing_research_cases as cases')->join('swing_research_groups as groups', 'groups.id', '=', 'cases.group_id')
            ->where('groups.owner_id', $ownerId)->whereNotNull('cases.signal_recorded_at')->where('cases.signal_recorded_at', '>=', now()->subDays(45));
        $unread = (clone $query)->whereNull('cases.alert_read_at')->count();
        $rows = $query->orderByDesc('cases.signal_recorded_at')->orderByDesc('cases.id')->limit(201)->get(['cases.*', 'groups.symbol', 'groups.horizon']);
        $items = [];
        foreach ($rows->take(200) as $case) {
            $state = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
            $items[] = ['id' => $case->id, 'symbol' => $case->symbol, 'horizon' => $case->horizon, 'variant' => $case->variant,
                'label' => SwingResearchRules::variants()[$case->variant]['label'], 'experimental' => true,
                'signal_recorded_at' => $this->iso($case->signal_recorded_at), 'alert_read_at' => $this->iso($case->alert_read_at),
                'status' => $case->status, 'outcome' => $state['outcome'] ?? null,
                'direction' => $state['direction'] ?? null, 'qualified_price' => $state['qualified_price'] ?? null,
                ...array_intersect_key($state['levels'] ?? [], array_flip(['entry_low', 'entry_high', 'target', 'invalidation', 'invalidation_side']))];
        }

        return ['items' => $items, 'unread_count' => $unread, 'truncated' => $rows->count() > 200];
    }

    public function markAlertRead(int $ownerId, string $id): void
    {
        $this->assertOwner($ownerId);
        abort_unless($this->prepared(), 404);
        $query = DB::table('swing_research_cases')->where('id', $id)->whereNotNull('signal_recorded_at')
            ->whereIn('group_id', DB::table('swing_research_groups')->where('owner_id', $ownerId)->select('id'));
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('alert_read_at')->update(['alert_read_at' => now('UTC'), 'updated_at' => now()]);
    }

    private function assertOwner(int $ownerId): void
    {
        abort_unless(SetupStudyAccess::allows(User::find($ownerId)), 403, 'This research experiment is private.');
    }

    private function prepared(): bool
    {
        return Schema::hasTable('swing_research_groups') && Schema::hasTable('swing_research_cases') && Schema::hasTable('swing_research_daily_bars');
    }

    private function closeAt(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(MarketSession::describe(CarbonImmutable::parse($date.' 12:00:00', 'America/New_York'))['closes_at'])->utc();
    }

    private function iso(?string $at): ?string
    {
        return $at ? CarbonImmutable::parse($at, 'UTC')->toIso8601String() : null;
    }

    private function normalBar(array $bar): ?array
    {
        $normal = ['date' => $bar['date']];
        foreach (['open', 'high', 'low', 'close'] as $field) {
            if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field]) || $bar[$field] <= 0) {
                return null;
            }
            $normal[$field] = (float) $bar[$field];
        }

        return $normal['high'] >= max($normal['open'], $normal['close']) && $normal['low'] <= min($normal['open'], $normal['close']) ? $normal : null;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
