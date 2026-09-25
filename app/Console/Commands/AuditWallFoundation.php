<?php

namespace App\Console\Commands;

use App\Support\WallIntelligence\WallFoundationAudit;
use App\Support\WallIntelligence\WallObservationStore;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AuditWallFoundation extends Command
{
    protected $signature = 'walls:audit-foundation
        {--dataset=local_review : local_review or production_capture}
        {--view=latest_eod : latest_eod or next_session}
        {--timeframe=14d : Recorded expiry horizon}
        {--record : Append deduplicated audit captures to local observation storage}
        {--json : Print the complete versioned report and raw inputs}';

    protected $description = 'Audit wall calculation contracts locally without provider requests or live setup generation';

    public function handle(WallFoundationAudit $audit, WallObservationStore $store): int
    {
        if (! app()->environment('local')) {
            $this->error('Batch 1 audit and capture run only in the local environment.');

            return self::FAILURE;
        }
        $previousClock = Carbon::getTestNow();
        try {
            if (config('ui_review.now')) {
                Carbon::setTestNow(Carbon::parse(config('ui_review.now')));
            }
            $report = $audit->report($this->option('dataset'), $this->option('view'), $this->option('timeframe'));
            $recordIds = [];
            if ($this->option('record')) {
                foreach ($report['snapshots'] as $snapshot) {
                    $recordIds[] = $store->record($snapshot)->id;
                }
            }
            $report['recorded_ids'] = $recordIds;
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(['Symbol', 'Source date', 'Strikes', 'Quality', 'Leg reconciliation'], array_map(fn ($s) => [
                    $s['scope']['symbol'], $s['provenance']['source_date'] ?? 'Unavailable',
                    $s['reconciliation']['strike_count'], $s['quality']['state'],
                    $s['reconciliation']['call_minus_put_matches_net'] === null ? 'Unavailable'
                        : ($s['reconciliation']['call_minus_put_matches_net'] ? 'PASS' : 'FAIL'),
                ], $report['snapshots']));
                $this->info('No provider calls. No scores or setups generated.');
                if ($recordIds) {
                    $this->line('Audit observation IDs: '.implode(', ', $recordIds));
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            Carbon::setTestNow($previousClock);
        }
    }
}
