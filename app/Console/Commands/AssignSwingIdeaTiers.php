<?php

namespace App\Console\Commands;

use App\Support\CoordinationCache;
use App\Support\Swing\SwingIdeaTier;
use App\Support\Swing\SwingReactionRules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AssignSwingIdeaTiers extends Command
{
    protected $signature = 'swing:assign-tiers {--limit=200 : Existing ideas to label, at most 500} {--dry-run : Preview without writing metadata}';

    protected $description = 'Assign frozen structure tiers without changing swing levels, price events or outcomes';

    public function handle(): int
    {
        if (! Schema::hasTable('swing_reaction_ideas')) {
            return self::FAILURE;
        }
        $lock = CoordinationCache::store()->lock('swing:reaction:collect:v2', 180);
        if (! $lock->get()) {
            $this->info('A swing collection is running. Retry after it finishes.');

            return self::SUCCESS;
        }
        try {
            $ids = DB::table('swing_reaction_ideas')->where('rule_version', SwingReactionRules::VERSION)
                ->whereNull('current_state->tier->version')->orderBy('created_at')->orderBy('id')
                ->limit(max(1, min(500, (int) $this->option('limit'))))->pluck('id');
            $counts = [];
            foreach ($ids as $id) {
                DB::transaction(function () use ($id, &$counts) {
                    $row = DB::table('swing_reaction_ideas')->where('id', $id)->lockForUpdate()->first();
                    $state = json_decode($row->current_state, true, flags: JSON_THROW_ON_ERROR);
                    if (isset($state['tier']['version'])) {
                        return;
                    }
                    $original = json_decode($row->original_plan, true, flags: JSON_THROW_ON_ERROR);
                    $tier = (new SwingIdeaTier)->assess($original);
                    $counts[$tier['key']] = ($counts[$tier['key']] ?? 0) + 1;
                    if ($this->option('dry-run')) {
                        return;
                    }
                    $now = CarbonImmutable::now('UTC');
                    $state['tier'] = [...$tier, 'assigned_at' => $now->toIso8601String(),
                        'basis_date' => $row->source_date];
                    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    DB::table('swing_reaction_ideas')->where('id', $id)->update(['current_state' => $json, 'updated_at' => $now->toDateTimeString()]);
                    DB::table('swing_reaction_reviews')->insert(['idea_id' => $id, 'observed_at' => $now->toDateTimeString(),
                        'recorded_at' => $now->toDateTimeString(), 'state_json' => $json]);
                });
            }
            $this->line(json_encode(['dry_run' => (bool) $this->option('dry-run'), 'tiers' => $counts]));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
