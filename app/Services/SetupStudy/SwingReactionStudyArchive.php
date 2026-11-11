<?php

namespace App\Services\SetupStudy;

use App\Support\SetupStudy\SwingReactionStudyMetrics;
use App\Support\Swing\SwingReactionRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Compact incremental archive; avoids replaying legacy intraday history on every swing tick. */
final class SwingReactionStudyArchive
{
    public function collect(int $ownerId): int
    {
        if (! Schema::hasTable('setup_study_records') || ! Schema::hasTable('swing_reaction_ideas')) {
            return 0;
        }
        $lock = Cache::lock('setup-study:collect:'.$ownerId, 120);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $pending = DB::table('swing_reaction_ideas as ideas')
                ->leftJoin('setup_study_records as study', fn ($join) => $join->on('study.sample_id', '=', 'ideas.id')->where('study.owner_id', $ownerId))
                ->where('ideas.rule_version', SwingReactionRules::VERSION)->where('ideas.source_date', '>=', now()->subDays(45)->toDateString())
                ->where(fn ($q) => $q->whereNull('study.id')->orWhereColumn('ideas.updated_at', '>', 'study.collected_at'))
                ->orderBy('study.collected_at')->orderBy('ideas.id')->limit(200)->get(['ideas.id', 'ideas.symbol']);
            if ($pending->isEmpty()) {
                return 0;
            }
            $ids = array_fill_keys($pending->pluck('id')->all(), true);
            $existing = DB::table('setup_study_records')->where('owner_id', $ownerId)->whereIn('sample_id', array_keys($ids))->get()->keyBy('sample_id');
            $now = CarbonImmutable::now('UTC');
            $writes = [];
            foreach (array_chunk($pending->pluck('symbol')->unique()->all(), 20) as $symbols) {
                $read = app(SwingReactionStudySource::class)->read($symbols, $now->subDays(45)->toDateString(), $now->setTimezone('America/New_York')->toDateString());
                foreach ($read['samples'] as $sample) {
                    if (! isset($ids[$sample['id']])) {
                        continue;
                    }
                    $measurement = (new SwingReactionStudyMetrics)->measure($sample, $now);
                    $date = CarbonImmutable::parse($sample['signal_at'] ?? $sample['first_recorded_at'])->setTimezone('America/New_York')->toDateString();
                    $measurement['study_date'] = $date;
                    $measurement['collected_at'] = $now->toIso8601String();
                    $measurement['source'] = [...$sample['source'], 'availability_basis' => 'first retained publication', 'retrospective' => false];
                    $writes[] = ['owner_id' => $ownerId, 'sample_id' => $sample['id'], 'symbol' => $sample['symbol'],
                        'cohort' => 'swing_'.$sample['horizon'], 'session' => $date, 'rule_version' => $sample['rule_version'],
                        'payload' => json_encode($measurement, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                        'collected_at' => $now->toDateTimeString(), 'created_at' => $existing->get($sample['id'])?->created_at ?? $now->toDateTimeString(),
                        'updated_at' => $now->toDateTimeString()];
                }
            }
            foreach (array_chunk($writes, 100) as $chunk) {
                DB::table('setup_study_records')->upsert($chunk, ['owner_id', 'sample_id'],
                    ['cohort', 'symbol', 'session', 'rule_version', 'payload', 'collected_at', 'updated_at']);
            }

            return count($writes);
        } finally {
            $lock->release();
        }
    }
}
