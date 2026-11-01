<?php

namespace App\Services\IntradayResearch;

use App\Models\User;
use App\Services\SetupStudy\IntradayStudySource;
use App\Support\IntradayResearch\IntradayResearchRules;
use App\Support\MarketSession;
use App\Support\SetupStudy\SetupStudyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Owner-only paired research over saved assessments and immutable retained bars. */
class IntradayResearchService
{
    private const OPEN = ['watching', 'waiting', 'conditions_met', 'in_progress'];

    public static function collectionAllowed(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now('UTC');
        $session = MarketSession::describe($at);

        return $session['is_trading_day'] && $at->gte(CarbonImmutable::parse($session['opens_at'])->addMinutes(15))
            && $at->lte(CarbonImmutable::parse($session['closes_at'])->addMinutes(30));
    }

    public function collect(int $ownerId, int $limit = 100): array
    {
        $this->assertOwner($ownerId);
        if (! $this->prepared()) {
            return ['prepared' => false, 'symbols' => 0, 'groups' => 0, 'warnings' => ['Apply the intraday-research migration before collection.']];
        }
        $result = ['prepared' => true, 'symbols' => 0, 'total_symbols' => 0, 'groups' => 0, 'failed' => 0, 'warnings' => []];
        $now = CarbonImmutable::now('UTC');
        if (! config('intraday_research.enabled') || ! self::collectionAllowed($now)) {
            return [...$result, 'skipped' => true, 'warnings' => ['Collection is disabled or outside the regular-session follow-up window.']];
        }
        $lock = Cache::lock('intraday-research:collect:'.$ownerId, 90);
        if (! $lock->get()) {
            return [...$result, 'busy' => true, 'warnings' => ['Research collection is already running.']];
        }
        try {
            $session = $now->setTimezone('America/New_York')->toDateString();
            $saved = DB::table('watchlists')->where('user_id', $ownerId)->distinct()->pluck('symbol')->all();
            $open = DB::table('intraday_research_groups as groups')->join('intraday_research_cases as cases', 'cases.group_id', '=', 'groups.id')
                ->where('groups.owner_id', $ownerId)->where('groups.session', $session)
                ->where(function ($query) {
                    $query->whereIn('cases.status', self::OPEN)->orWhere(function ($review) {
                        $review->where('cases.status', 'needs_update')->where(function ($correction) {
                            $correction->whereNull('cases.current_state->correction_detected')->orWhere('cases.current_state->correction_detected', false);
                        });
                    });
                })
                ->distinct()->pluck('groups.symbol')->all();
            $symbols = array_values(array_filter(array_unique([...$saved, ...$open]), fn ($s) => is_string($s) && preg_match('/^[A-Z0-9.^-]{1,16}$/D', $s)));
            sort($symbols, SORT_STRING);
            $result['total_symbols'] = count($symbols);
            $rotationKey = 'intraday-research:rotation:'.$ownerId.':'.$session;
            $rotation = $symbols ? (int) Cache::get($rotationKey, 0) % count($symbols) : 0;
            $selected = array_slice(array_merge($symbols, $symbols), $rotation, min(count($symbols), max(1, min(200, $limit))));
            $started = microtime(true);
            foreach ($selected as $symbol) {
                if (microtime(true) - $started >= 45) {
                    $result['warnings'][] = 'The 45-second collection budget was reached; the next batch resumes the rotation.';
                    break;
                }
                $result['symbols']++;
                try {
                    $after = (int) DB::table('intraday_research_cursors')->where('owner_id', $ownerId)->where('symbol', $symbol)
                        ->where('session', $session)->value('last_checkpoint_id');
                    // Exactly one bounded delta read; it includes the newest saved price bundle even with no new checkpoint.
                    $source = app(IntradayStudySource::class)->readIncremental([$symbol], $session, $after);
                    $result['groups'] += $this->collectSymbol($ownerId, $symbol, $session, $source, $after, in_array($symbol, $saved, true), CarbonImmutable::now('UTC'));
                    foreach ($source['warnings'] ?? [] as $warning) {
                        $result['warnings'][] = $symbol.': '.$warning;
                    }
                    if ($source['truncated'] ?? false) {
                        $result['warnings'][] = $symbol.': saved evidence was truncated; the next collection resumes the checkpoint cursor.';
                    }
                } catch (\Throwable $error) {
                    $result['failed']++;
                    $result['warnings'][] = $symbol.': collection failed ('.class_basename($error).'); the checkpoint cursor was preserved.';
                    Log::warning('intraday-research.collection-failed', ['symbol' => $symbol, 'exception' => get_class($error)]);
                }
            }
            Cache::put($rotationKey, $symbols ? ($rotation + $result['symbols']) % count($symbols) : 0, $now->addDays(2));
            $result['warnings'] = array_values(array_unique($result['warnings']));
            $result['collected_at'] = $now->toIso8601String();
            Cache::put('intraday-research:last:'.$ownerId, $result, $now->addDays(7));

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function collectSymbol(int $ownerId, string $symbol, string $session, array $source, int $after, bool $canSeed, CarbonImmutable $now): int
    {
        return DB::transaction(function () use ($ownerId, $symbol, $session, $source, $after, $canSeed, $now) {
            [$bars, $revisions] = $this->pinBars($ownerId, $symbol, $session, $source['bars'] ?? [], $source['price_recorded_at'] ?? null, $now);
            $created = 0;
            $changedSources = [];
            $known = DB::table('intraday_research_groups')->where('owner_id', $ownerId)->where('symbol', $symbol)
                ->where('session', $session)->lockForUpdate()->get()->keyBy('id');
            $nativeGroups = [];
            foreach ($known as $group) {
                $saved = json_decode($group->original_sample, true, flags: JSON_THROW_ON_ERROR);
                foreach ($saved['native_ids'] ?? [] as $nativeId) {
                    $nativeGroups[$saved['signal_at'].'|'.$nativeId] = $group->id;
                }
            }
            foreach ($source['samples'] ?? [] as $sample) {
                if (! is_array($sample) || ($sample['symbol'] ?? null) !== $symbol || ($sample['session'] ?? null) !== $session
                    || ! is_string($sample['id'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $sample['id'])) {
                    throw new \UnexpectedValueException('Invalid saved research identity.');
                }
                unset($sample['bars']);
                $id = hash('sha256', $this->json([$ownerId, $sample['id'], IntradayResearchRules::VERSION]));
                $hash = $this->sampleHash($sample);
                $group = $known->get($id);
                if ($group) {
                    if ($group->source_hash !== $hash) {
                        $changedSources[$id] = true;
                    }

                    continue;
                }
                $correctionOf = null;
                foreach ($sample['native_ids'] ?? [] as $nativeId) {
                    $correctionOf ??= $nativeGroups[($sample['signal_at'] ?? '').'|'.$nativeId] ?? null;
                }
                if ($correctionOf !== null) {
                    // Geometry is part of the source's episode ID. A changed ID for the same native assessment is a correction.
                    $changedSources[$correctionOf] = true;

                    continue;
                }
                if (! $canSeed) {
                    continue;
                }
                DB::table('intraday_research_groups')->insert(['id' => $id, 'owner_id' => $ownerId, 'sample_id' => $sample['id'],
                    'symbol' => $symbol, 'session' => $session, 'rule_version' => IntradayResearchRules::VERSION,
                    'source_hash' => $hash, 'original_sample' => $this->json($sample), 'created_at' => $now, 'updated_at' => $now]);
                foreach (array_keys(IntradayResearchRules::variants()) as $variant) {
                    $plan = IntradayResearchRules::create($sample, $now, $variant);
                    DB::table('intraday_research_cases')->insert(['id' => hash('sha256', $id.':'.$variant), 'group_id' => $id,
                        'variant' => $variant, 'original_plan' => $this->json($plan), 'current_state' => $this->json($plan),
                        'status' => $plan['status'], 'created_at' => $now, 'updated_at' => $now]);
                }
                $known->put($id, (object) ['id' => $id, 'source_hash' => $hash]);
                foreach ($sample['native_ids'] ?? [] as $nativeId) {
                    $nativeGroups[($sample['signal_at'] ?? '').'|'.$nativeId] = $id;
                }
                $created++;
            }
            $groups = DB::table('intraday_research_groups')->where('owner_id', $ownerId)->where('symbol', $symbol)->where('session', $session)->get();
            $cases = DB::table('intraday_research_cases')->whereIn('group_id', $groups->pluck('id'))->get()->groupBy('group_id');
            foreach ($groups as $group) {
                $original = json_decode($group->original_sample, true, flags: JSON_THROW_ON_ERROR);
                foreach ($cases->get($group->id, collect()) as $case) {
                    $old = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
                    if ($old['correction_detected'] ?? false) {
                        continue;
                    }
                    $corrected = isset($changedSources[$group->id]);
                    $end = $old['outcome_at'] ?? ($bars ? end($bars)['at'] : $original['review_until']);
                    foreach ($revisions as $at) {
                        if (CarbonImmutable::parse($at)->gte(CarbonImmutable::parse($original['signal_at']))
                            && CarbonImmutable::parse($at)->lte(CarbonImmutable::parse($end))) {
                            $corrected = true;
                        }
                    }
                    $isNew = $case->created_at === $now->toDateTimeString();
                    // Missing delayed bars can arrive in a later collection. Retry their frozen plan without clearing correction quarantines.
                    $retryReview = $case->status === 'needs_update';
                    if (! $corrected && ! $isNew && ! $retryReview && ! in_array($case->status, self::OPEN, true)) {
                        continue;
                    }
                    $plan = json_decode($case->original_plan, true, flags: JSON_THROW_ON_ERROR);
                    if (! empty($old['qualified_recorded_at'])) {
                        $plan['qualified_recorded_at'] = $old['qualified_recorded_at'];
                    }
                    $state = IntradayResearchRules::evaluate($plan, $bars, $now);
                    if (($state['metrics']['qualified'] ?? false) && empty($state['qualified_recorded_at'])) {
                        $state['qualified_recorded_at'] = $old['qualified_recorded_at'] ?? $now->toIso8601String();
                        $state['signal_available_at'] = $state['qualified_recorded_at'];
                    }
                    if ($corrected) {
                        $state = [...$state, 'status' => 'needs_update', 'correction_detected' => true, 'notification_eligible' => false,
                            'reason' => 'Saved source inputs or retained bars changed. The original assessment, plan, and bars are preserved for review.'];
                        $state['metrics'] = [...($state['metrics'] ?? []), 'needs_update' => true, 'followup_complete' => false];
                    }
                    $recorded = $case->notification_recorded_at;
                    $event = $case->notification_event;
                    // Evaluate the entire known sequence before deciding whether a new conditions-met event exists.
                    if ($recorded === null && $case->variant === 'post_publication_retest' && ($state['notification_eligible'] ?? false)
                        && ! ($source['truncated'] ?? false) && $this->notificationCurrent($state, $bars, $now)) {
                        $recorded = $now->toDateTimeString();
                        $event = $this->json($this->notificationEvent($case->id, $case->variant, $symbol, $session, $state, $now));
                    }
                    $json = $this->json($state);
                    if ($json !== $case->current_state || $recorded !== $case->notification_recorded_at) {
                        DB::table('intraday_research_cases')->where('id', $case->id)->update(['current_state' => $json,
                            'status' => $state['status'], 'notification_recorded_at' => $recorded, 'notification_event' => $event, 'updated_at' => $now]);
                    }
                }
            }
            // Cursor movement shares the transaction with every sample, paired case, and immutable bar write.
            DB::table('intraday_research_cursors')->updateOrInsert(['owner_id' => $ownerId, 'symbol' => $symbol, 'session' => $session],
                ['last_checkpoint_id' => max($after, (int) ($source['last_checkpoint_id'] ?? $after)), 'updated_at' => $now, 'created_at' => $now]);

            return $created;
        });
    }

    private function notificationCurrent(array $state, array $bars, CarbonImmutable $now): bool
    {
        if (! $bars || ! in_array($state['status'] ?? null, ['conditions_met', 'in_progress'], true)
            || empty($state['signal_at']) || ! empty($state['outcome']) || ! empty($state['outcome_at'])
            || ($state['metrics']['needs_update'] ?? false) || ($state['metrics']['evidence_gap'] ?? false)
            || ! MarketSession::describe($now)['is_rth']) {
            return false;
        }
        $latest = CarbonImmutable::parse(end($bars)['at']);

        return $latest->lte($now->subSeconds(900)) && $latest->gte($now->subSeconds(1320));
    }

    /** Only mature completed regular-session bars enter the immutable archive. */
    private function pinBars(int $ownerId, string $symbol, string $session, array $source, ?string $priceRecordedAt, CarbonImmutable $now): array
    {
        $calendar = MarketSession::describe($now);
        $open = CarbonImmutable::parse($calendar['opens_at']);
        $close = CarbonImmutable::parse($calendar['closes_at']);
        $cutoff = $now->subSeconds(900);
        $existing = DB::table('intraday_research_bars')->where('owner_id', $ownerId)->where('symbol', $symbol)
            ->where('session', $session)->get()->keyBy('bar_at');
        $seen = [];
        $observed = [];
        foreach ($source as $bar) {
            if (! is_array($bar) || ! is_string($bar['at'] ?? null)) {
                continue;
            }
            try {
                $at = CarbonImmutable::parse($bar['at'], 'UTC')->utc();
            } catch (\Throwable) {
                continue;
            }
            if ($at->gt($cutoff) || $at->gt($close) || $at->lte($open) || $at->timestamp % 300 !== 0) {
                continue;
            }
            if (isset($seen[$at->timestamp])) {
                throw new \UnexpectedValueException('Duplicate completed price bar.');
            }
            $seen[$at->timestamp] = true;
            $normal = ['at' => $at->toIso8601String()];
            foreach (['open', 'high', 'low', 'close'] as $field) {
                if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field]) || $bar[$field] <= 0) {
                    continue 2;
                }
                $normal[$field] = (float) $bar[$field];
            }
            if ($normal['high'] < max($normal['open'], $normal['close']) || $normal['low'] > min($normal['open'], $normal['close']) || $normal['low'] > $normal['high']) {
                continue;
            }
            $observed[$at->toDateTimeString()] = true;
            $json = $this->json($normal);
            $hash = hash('sha256', $json);
            $previous = $existing->get($at->toDateTimeString());
            if (! $previous) {
                DB::table('intraday_research_bars')->insert(['owner_id' => $ownerId, 'symbol' => $symbol, 'session' => $session,
                    'bar_at' => $at, 'ohlc' => $json, 'evidence_hash' => $hash, 'first_recorded_at' => $now]);
            } elseif ($previous->evidence_hash !== $hash) {
                $revision = $previous->revision ? json_decode($previous->revision, true, flags: JSON_THROW_ON_ERROR) : [];
                $revision['first_detected_at'] ??= $now->toIso8601String();
                $revision = [...$revision, 'last_observed_at' => $now->toIso8601String(), 'latest_hash' => $hash, 'latest_ohlc' => $normal];
                DB::table('intraday_research_bars')->where('id', $previous->id)->update(['revision' => $this->json($revision)]);
            }
        }
        $recorded = null;
        try {
            $recorded = $priceRecordedAt ? CarbonImmutable::parse($priceRecordedAt, 'UTC') : null;
        } catch (\Throwable) {
            // Missing or malformed receipt metadata cannot establish that an omission is a newer correction.
        }
        if ($observed && $recorded && $recorded->lte($now)) {
            $range = array_keys($observed);
            sort($range, SORT_STRING);
            foreach ($existing as $previous) {
                if (isset($observed[$previous->bar_at]) || $previous->bar_at < $range[0] || $previous->bar_at > end($range)
                    || $recorded->lte(CarbonImmutable::parse($previous->first_recorded_at, 'UTC'))) {
                    continue;
                }
                $revision = $previous->revision ? json_decode($previous->revision, true, flags: JSON_THROW_ON_ERROR) : [];
                $revision['first_detected_at'] ??= $now->toIso8601String();
                $revision = [...$revision, 'last_observed_at' => $now->toIso8601String(), 'omitted_from_newer_bundle' => true,
                    'source_recorded_at' => $recorded->toIso8601String()];
                DB::table('intraday_research_bars')->where('id', $previous->id)->update(['revision' => $this->json($revision)]);
            }
        }
        $rows = DB::table('intraday_research_bars')->where('owner_id', $ownerId)->where('symbol', $symbol)->where('session', $session)->orderBy('bar_at')->get();

        return [$rows->map(fn ($row) => json_decode($row->ohlc, true, flags: JSON_THROW_ON_ERROR))->all(),
            $rows->whereNotNull('revision')->map(fn ($row) => $this->iso($row->bar_at))->all()];
    }

    /** A report reads only compact saved state. It never reads prices or source assessments. */
    public function report(int $ownerId, array $filters): array
    {
        $this->assertOwner($ownerId);
        $prepared = $this->prepared();
        $groups = $warnings = $measurements = [];
        if ($prepared) {
            $query = DB::table('intraday_research_groups')->where('owner_id', $ownerId)->whereBetween('session', [$filters['from'], $filters['to']]);
            if (! empty($filters['symbol'])) {
                $query->where('symbol', $filters['symbol']);
            }
            $rows = $query->orderByDesc('session')->orderByDesc('created_at')->limit(1001)->get();
            if ($rows->count() > 1000) {
                $warnings[] = 'The report is limited to 1,000 paired groups. Narrow the dates or choose a symbol; displayed totals cover those groups.';
            }
            $rows = $rows->take(1000);
            $cases = DB::table('intraday_research_cases')->whereIn('group_id', $rows->pluck('id'))->get()->groupBy('group_id');
            foreach ($rows as $group) {
                $source = json_decode($group->original_sample, true, flags: JSON_THROW_ON_ERROR);
                $items = [];
                foreach ($cases->get($group->id, collect()) as $case) {
                    $state = json_decode($case->current_state, true, flags: JSON_THROW_ON_ERROR);
                    $items[] = ['id' => $case->id, 'variant' => $case->variant, 'label' => IntradayResearchRules::variants()[$case->variant]['label'],
                        'status' => $case->status, 'state' => $state, 'notification_recorded_at' => $this->iso($case->notification_recorded_at),
                        'alert_read_at' => $this->iso($case->alert_read_at)];
                    $measurements[$case->variant][] = $state;
                }
                $groups[] = ['id' => $group->id, 'symbol' => $group->symbol, 'session' => $group->session,
                    'created_at' => $this->iso($group->created_at), 'source_signal_at' => $source['signal_at'] ?? null,
                    'source_available_at' => $source['available_at'] ?? null, 'cases' => $items];
            }
        }
        $summary = [];
        foreach (array_keys(IntradayResearchRules::variants()) as $variant) {
            $items = $measurements[$variant] ?? [];
            $completed = array_values(array_filter($items, fn ($state) => ($state['metrics']['followup_complete'] ?? false)
                && ! ($state['metrics']['needs_update'] ?? false) && ! ($state['metrics']['evidence_gap'] ?? false) && ($state['status'] ?? '') !== 'needs_update'));
            $countOutcome = fn ($outcome) => count(array_filter($completed, fn ($state) => ($state['outcome'] ?? null) === $outcome));
            $hits = $countOutcome('target_reached');
            $average = function ($key) use ($completed) {
                $values = array_values(array_filter(array_map(fn ($state) => $state['metrics'][$key] ?? null, $completed), fn ($v) => is_numeric($v) && is_finite((float) $v)));

                return $values ? round(array_sum($values) / count($values), 3) : null;
            };
            $summary[] = ['variant' => $variant, 'total' => count($items),
                'qualified' => count(array_filter($items, fn ($s) => (bool) ($s['metrics']['qualified'] ?? false))),
                'not_qualified' => count(array_filter($items, fn ($s) => ($s['status'] ?? '') === 'not_qualified')),
                'waiting' => count(array_filter($items, fn ($s) => in_array($s['status'] ?? '', ['watching', 'waiting'], true))),
                'in_progress' => count(array_filter($items, fn ($s) => in_array($s['status'] ?? '', ['conditions_met', 'in_progress'], true))),
                'completed' => count($completed), 'needs_review' => count(array_filter($items, fn ($s) => ($s['metrics']['needs_update'] ?? false) || ($s['status'] ?? '') === 'needs_update')),
                'target_rate' => ['hits' => $hits, 'eligible' => count($completed), 'rate' => $completed ? round(100 * $hits / count($completed), 1) : null],
                'target_reached' => $hits, 'invalidated' => $countOutcome('invalidated'),
                'ended' => $countOutcome('session_ended') + $countOutcome('holding_ended'), 'avg_outcome_move_pct' => $average('outcome_move_pct'),
                'avg_mfe_pct' => $average('mfe_pct'), 'avg_mae_pct' => $average('mae_pct')];
        }
        $collection = Cache::get('intraday-research:last:'.$ownerId, []);

        return ['prepared' => $prepared, 'enabled' => (bool) config('intraday_research.enabled'), 'demonstration' => false,
            'filters' => $filters, 'variants' => IntradayResearchRules::variants(), 'summary' => $summary, 'groups' => $groups,
            'collection' => $collection, 'warnings' => array_values(array_unique([...$warnings, ...($collection['warnings'] ?? [])]))];
    }

    public function alerts(int $ownerId): array
    {
        $this->assertOwner($ownerId);
        if (! $this->prepared()) {
            return ['items' => [], 'unread_count' => 0, 'truncated' => false];
        }
        $query = DB::table('intraday_research_cases as cases')->join('intraday_research_groups as groups', 'groups.id', '=', 'cases.group_id')
            ->where('groups.owner_id', $ownerId)->where('cases.variant', 'post_publication_retest')
            ->whereNotNull('cases.notification_recorded_at')->whereNotNull('cases.notification_event')
            ->where('cases.notification_recorded_at', '>=', now()->subDays(14));
        $unread = (clone $query)->whereNull('cases.alert_read_at')->count();
        $rows = $query->orderByDesc('cases.notification_recorded_at')->orderByDesc('cases.id')->limit(201)->get(['cases.*', 'groups.symbol', 'groups.session']);
        $items = [];
        foreach ($rows->take(200) as $case) {
            $event = json_decode($case->notification_event, true, flags: JSON_THROW_ON_ERROR);
            $items[] = [...$event, 'alert_read_at' => $this->iso($case->alert_read_at)];
        }

        return ['items' => $items, 'unread_count' => $unread, 'truncated' => $rows->count() > 200];
    }

    public function markAlertRead(int $ownerId, string $id): void
    {
        $this->assertOwner($ownerId);
        abort_unless($this->prepared(), 404);
        $query = DB::table('intraday_research_cases')->where('id', $id)->whereNotNull('notification_recorded_at')->whereNotNull('notification_event')
            ->whereIn('group_id', DB::table('intraday_research_groups')->where('owner_id', $ownerId)->select('id'));
        abort_unless((clone $query)->exists(), 404);
        $query->whereNull('alert_read_at')->update(['alert_read_at' => now('UTC'), 'updated_at' => now()]);
    }

    private function notificationEvent(string $id, string $variant, string $symbol, string $session, array $state, CarbonImmutable $recorded): array
    {
        return ['id' => $id, 'symbol' => $symbol, 'session' => $session, 'variant' => $variant,
            'label' => IntradayResearchRules::variants()[$variant]['label'], 'mode' => 'intraday', 'experimental' => true,
            'status' => 'conditions_met', 'notification_type' => 'conditions_met',
            'notification_recorded_at' => $recorded->toIso8601String(), 'signal_recorded_at' => $recorded->toIso8601String(),
            'direction' => $state['direction'] ?? null, 'signal_at' => $state['signal_at'] ?? null,
            'qualified_at' => $state['signal_at'] ?? null, 'qualified_price' => $state['signal_price'] ?? null,
            ...array_intersect_key($state, array_flip(['entry_low', 'entry_high', 'target', 'invalidation', 'invalidation_side']))];
    }

    private function sampleHash(array $sample): string
    {
        // A delta's receipt time belongs to that slice. Preserve the first archived receipt without treating later receipts as revisions.
        $fields = ['id', 'symbol', 'session', 'timeframe', 'direction', 'side', 'pattern', 'rule_version', 'signal_at',
            'signal_price', 'entry_low', 'entry_high', 'invalidation', 'invalidation_side', 'target', 'review_until', 'entry_expires_at', 'evidence'];

        return hash('sha256', $this->json(array_intersect_key($sample, array_flip($fields))));
    }

    private function assertOwner(int $ownerId): void
    {
        abort_unless(SetupStudyAccess::allows(User::find($ownerId)), 403, 'This research experiment is private.');
    }

    private function prepared(): bool
    {
        return Schema::hasTable('intraday_research_groups') && Schema::hasTable('intraday_research_cases')
            && Schema::hasTable('intraday_research_bars') && Schema::hasTable('intraday_research_cursors');
    }

    private function iso(?string $at): ?string
    {
        return $at ? CarbonImmutable::parse($at, 'UTC')->utc()->toIso8601String() : null;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
