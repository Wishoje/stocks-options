<?php

namespace App\Services\SetupStudy;

use App\Support\SetupStudy\SetupStudyMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupStudyService
{
    /** Bounded archival collection from existing evidence only, never a provider or queue. */
    public function collect(int $ownerId, int $limit = 12, bool $backfill = false): array
    {
        if (! Schema::hasTable('setup_study_records')) {
            return ['prepared' => false, 'symbols' => 0, 'records' => 0, 'warnings' => ['Apply the setup-study migration to start keeping study records.']];
        }
        $lock = Cache::lock('setup-study:collect:'.$ownerId, 120);
        if (! $lock->get()) {
            return ['prepared' => true, 'busy' => true, 'symbols' => 0, 'records' => 0, 'warnings' => ['Study collection is already running.']];
        }
        try {
            $symbols = DB::table('watchlists')->where('user_id', $ownerId)->distinct()->pluck('symbol')->all();
            $open = DB::table('setup_study_records')->where('owner_id', $ownerId)
                ->where('session', '>=', now()->subDays(45)->toDateString())->distinct()->pluck('symbol')->all();
            $symbols = array_values(array_unique(array_merge($symbols, $open)));
            sort($symbols, SORT_STRING);
            if (! $symbols) {
                return ['prepared' => true, 'symbols' => 0, 'records' => 0, 'warnings' => []];
            }
            $key = 'setup-study:cursor:'.$ownerId;
            $cursor = (int) Cache::get($key, 0) % count($symbols);
            $selected = array_slice(array_merge($symbols, $symbols), $cursor, min(count($symbols), max(1, min(20, $limit))));
            $now = CarbonImmutable::now('UTC');
            $through = $now->setTimezone('America/New_York')->toDateString();
            $coveredKey = 'setup-study:covered:'.$ownerId;
            $covered = Cache::get($coveredKey, []);
            $initial = $backfill || array_diff($selected, array_keys($covered)) !== [];
            $previous = $initial ? null : min(array_intersect_key($covered, array_flip($selected)));
            $intradayFrom = $initial ? $now->subDays(14)->toDateString()
                : max($now->subDays(14)->toDateString(), min($now->subDays(3)->toDateString(), CarbonImmutable::parse($previous)->subDay()->toDateString()));
            $warnings = [];
            $samples = [];
            foreach ([
                [IntradayStudySource::class, $intradayFrom],
                [SwingStudySource::class, $now->subDays(45)->toDateString()],
            ] as [$source, $from]) {
                $result = app($source)->read($selected, $from, $through);
                $warnings = array_merge($warnings, $result['warnings'] ?? []);
                if ($result['truncated'] ?? false) {
                    $warnings[] = 'A source reached its collection limit. Counts cover only the retained records collected.';
                }
                array_push($samples, ...$result['samples']);
            }
            $existingRows = DB::table('setup_study_records')->where('owner_id', $ownerId)
                ->whereIn('sample_id', array_column($samples, 'id'))->get()->keyBy('sample_id');
            $writes = [];
            foreach ($samples as $sample) {
                $measurement = (new SetupStudyMetrics)->measure($sample, $now);
                $studyDate = ! empty($sample['signal_at']) ? CarbonImmutable::parse($sample['signal_at'])->setTimezone('America/New_York')->toDateString()
                    : (! empty($sample['first_recorded_at']) ? CarbonImmutable::parse($sample['first_recorded_at'])->setTimezone('America/New_York')->toDateString() : $sample['session']);
                $measurement['study_date'] = $studyDate;
                $cohort = $sample['mode'] === 'intraday' ? 'intraday' : 'swing_'.($sample['horizon'] === 'short' ? 'short' : 'position');
                $existing = $existingRows->get($sample['id']);
                $measurement['source'] = [...($sample['source'] ?? []),
                    'assessment_hash' => $sample['assessment_hash'] ?? $sample['source_hash'] ?? null,
                    'price_hash' => $sample['price_evidence_hash'] ?? null,
                    'availability_basis' => 'first retained publication',
                    'retrospective' => ! $existing && ! empty($sample['signal_at']) && CarbonImmutable::parse($sample['signal_at'])->lt($now->subDay()),
                ];
                $measurement['collected_at'] = $now->toIso8601String();
                if ($existing) {
                    $old = json_decode($existing->payload, true, flags: JSON_THROW_ON_ERROR);
                    $measurement['source']['retrospective'] = $old['source']['retrospective'] ?? false;
                    // Never replace previously complete evidence with a shorter retained history.
                    if ($old['source']['correction_detected'] ?? false) {
                        $measurement = $old;
                    } elseif (($old['followup_complete'] ?? false) && ! $measurement['followup_complete']) {
                        $conflict = $measurement['needs_update'] && ($sample['native_status'] ?? null) === 'needs_update';
                        $measurement = $old;
                        if ($conflict) {
                            $measurement['needs_update'] = true;
                            $measurement['followup_complete'] = false;
                            $measurement['status'] = 'needs_update';
                            $measurement['source']['correction_detected'] = true;
                        }
                    } elseif (($old['followup_complete'] ?? false) && $measurement['followup_complete']
                        && array_intersect_key($old['metrics'], array_flip(['mfe_r', 'mae_r', 'strict_outcome', 'strict_at']))
                            !== array_intersect_key($measurement['metrics'], array_flip(['mfe_r', 'mae_r', 'strict_outcome', 'strict_at']))) {
                        $measurement = [...$old, 'needs_update' => true, 'followup_complete' => false, 'status' => 'needs_update'];
                        $measurement['source']['correction_detected'] = true;
                    }
                }
                $writes[] = ['owner_id' => $ownerId, 'sample_id' => $sample['id'],
                    'cohort' => $cohort, 'symbol' => $sample['symbol'], 'session' => $studyDate,
                    'rule_version' => $sample['rule_version'], 'payload' => json_encode($measurement, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'collected_at' => $now->toDateTimeString(), 'created_at' => $existing?->created_at ?? $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
            }
            foreach (array_chunk($writes, 100) as $chunk) {
                DB::table('setup_study_records')->upsert($chunk, ['owner_id', 'sample_id'],
                    ['cohort', 'symbol', 'session', 'rule_version', 'payload', 'collected_at', 'updated_at']);
            }
            Cache::put($key, ($cursor + count($selected)) % count($symbols), now()->addDays(30));
            Cache::put($coveredKey, array_replace($covered, array_fill_keys($selected, $through)), now()->addDays(30));
            $state = ['prepared' => true, 'symbols' => count($selected), 'total_symbols' => count($symbols),
                'records' => count($writes), 'warnings' => array_values(array_unique($warnings)), 'collected_at' => $now->toIso8601String()];
            Cache::put('setup-study:last:'.$ownerId, $state, now()->addDays(7));

            return $state;
        } finally {
            $lock->release();
        }
    }

    /** Page requests read compact archived metrics, with no provider calls or recalculation. */
    public function report(int $ownerId, array $filters): array
    {
        $prepared = Schema::hasTable('setup_study_records');
        $rows = [];
        $warnings = [];
        $last = null;
        if ($prepared) {
            $query = DB::table('setup_study_records')->where('owner_id', $ownerId)->where('cohort', $filters['cohort'])
                ->whereBetween('session', [$filters['from'], $filters['to']]);
            if (! empty($filters['symbol'])) {
                $query->where('symbol', $filters['symbol']);
            }
            $records = $query->orderByDesc('session')->orderByDesc('id')->limit(5001)->get(['payload', 'collected_at']);
            if ($records->count() > 5000) {
                $warnings[] = 'The report is limited to 5,000 records. Narrow the dates or select a symbol.';
            }
            foreach ($records->take(5000) as $record) {
                $row = json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR);
                if (! empty($filters['direction']) && $row['direction'] !== $filters['direction']) {
                    continue;
                }
                $rows[] = $row;
                $last = max($last ?? '', $record->collected_at);
            }
        } else {
            $warnings[] = 'Study storage is not installed yet. Apply the migration before collection.';
        }
        $lastCollection = Cache::get('setup-study:last:'.$ownerId, []);
        $warnings = array_values(array_unique(array_merge($warnings, $lastCollection['warnings'] ?? [])));

        return ['filters' => $filters, 'prepared' => $prepared, 'generated_at' => now('UTC')->toIso8601String(),
            'last_collected_at' => $last ? CarbonImmutable::parse($last, 'UTC')->toIso8601String() : null,
            'collection_enabled' => (bool) config('setup_study.enabled'), 'collection' => $lastCollection,
            'summary' => $this->summarize($rows), 'rows' => $rows, 'breakdowns' => [
                'patterns' => $this->breakdown($rows, 'pattern'), 'symbols' => $this->breakdown($rows, 'symbol')],
            'warnings' => $warnings,
            'methodology' => [
                'version' => SetupStudyMetrics::VERSION,
                'reference' => 'Moves start at the recorded qualifying close. Prices are the underlying stock or ETF, not option returns or executed fills.',
                'risk' => '1R is the distance from that close to the original invalidation price. +0.5R and +1R use completed closes before target, invalidation or the review deadline.',
                'denominator' => 'Outcome rates use only completed, continuous follow-ups. Open and incomplete records are counted separately. Later wins never erase an earlier invalidation.',
                'entry' => 'Entry revisit means a later full bar overlaps the original entry area after the assessment was available. It does not establish an executed entry.',
                'timing' => 'Intraday evidence is delayed. Signal time and first retained publication time are shown separately. Backfilled records are labelled retrospective.',
                'publication' => 'After-publication target rate includes only completed continuous plans still open when the app received the assessment. Earlier targets and invalidations are counted separately and remain in the all-signal response rates.',
                'scope' => 'Your saved symbols at collection time, plus symbols already in this study. This is a selected sample, not all market opportunities.',
                'retention' => 'Compact study records are kept independently of raw observation retention. Repeated confirmations of the same active intraday levels are grouped.',
            ]];
    }

    public function summarize(array $rows): array
    {
        $qualified = array_values(array_filter($rows, fn ($r) => $r['qualified'] ?? false));
        $completed = array_values(array_filter($qualified, fn ($r) => ($r['followup_complete'] ?? false) && ! ($r['needs_update'] ?? false)));
        $observable = array_values(array_filter($completed, fn ($r) => $r['metrics']['entry_observable'] ?? false));
        $openWhenPublished = array_values(array_filter($completed, fn ($r) => ($r['metrics']['publication_timing'] ?? '') === 'open_at_publication'));
        $rate = function (array $eligible, callable $hit): array {
            $n = count($eligible);
            $hits = count(array_filter($eligible, $hit));

            return ['hits' => $hits, 'eligible' => $n, 'rate' => $n ? round(100 * $hits / $n, 1) : null];
        };
        $average = function (string $key) use ($completed): ?float {
            $values = array_filter(array_column(array_column($completed, 'metrics'), $key), fn ($v) => is_numeric($v));

            return count($values) ? round(array_sum($values) / count($values), 3) : null;
        };

        return ['total' => count($rows), 'qualified' => count($qualified),
            'watching' => count(array_filter($rows, fn ($r) => ($r['status'] ?? '') === 'watching')),
            'in_progress' => count(array_filter($qualified, fn ($r) => ($r['status'] ?? '') === 'in_progress')),
            'completed' => count($completed), 'needs_update' => count(array_filter($rows, fn ($r) => $r['needs_update'] ?? false)),
            'strict_target' => $rate($completed, fn ($r) => $r['metrics']['strict_outcome'] === 'target_reached'),
            'favorable_05r' => $rate($completed, fn ($r) => $r['metrics']['reached_05r'] === true),
            'favorable_1r' => $rate($completed, fn ($r) => $r['metrics']['reached_1r'] === true),
            'entry_revisit' => $rate($observable, fn ($r) => $r['metrics']['entry_revisited'] === true),
            'after_publication_target' => $rate($openWhenPublished, fn ($r) => $r['metrics']['strict_outcome'] === 'target_reached'),
            'resolved_before_publication' => count(array_filter($qualified, fn ($r) => ($r['metrics']['publication_timing'] ?? '') === 'resolved_before_publication')),
            'publication_timing_unknown' => count(array_filter($qualified, fn ($r) => ($r['metrics']['publication_timing'] ?? 'timing_unknown') === 'timing_unknown')),
            'avg_mfe_r' => $average('mfe_r'), 'avg_mae_r' => $average('mae_r')];
    }

    private function breakdown(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[$key] ?? 'Other'][] = $row;
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $label => $items) {
            $result[] = ['label' => $label, ...$this->summarize($items)];
        }

        return $result;
    }
}
