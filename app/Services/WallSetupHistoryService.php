<?php

namespace App\Services;

use App\Support\WallIntelligence\WallSetupOutcomeEvaluator;
use App\Support\WallIntelligence\WallSetupRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class WallSetupHistoryService
{
    private const CHECKPOINT_LIMIT = 4096;

    private const PAGE_SIZE = 5;

    private const SEED_FIELD_BYTES = 2097152;

    private const SEED_BYTES = 8192;

    private const SEED_LIMIT = 512;

    private const REGISTRY_BYTES = 1048576;

    public function read(array $interaction): array
    {
        $evaluator = new WallSetupOutcomeEvaluator;
        $truncated = false;
        $seeds = $this->savedSeeds($interaction, $truncated);
        foreach ($evaluator->seeds($interaction['setups']) as $seed) {
            // Saved scenarios are never replaced by a later retrospective replay.
            if (! isset($seeds[$seed['id']])) {
                $seeds[$seed['id']] = [...$seed, 'review_basis' => 'session_replay'];
            }
        }
        $result = $evaluator->analyze($interaction, array_values($seeds));
        $result['capture_limit_reached'] = $truncated;

        return $result;
    }

    /** Append-only source IDs make the optional cache a resumable registry, not a replacement for evidence. */
    private function savedSeeds(array $interaction, bool &$truncated): array
    {
        if (empty($interaction['session'])) {
            return [];
        }
        $key = 'walls:saved-seeds:v2:'.hash('sha256', json_encode([$interaction['symbol'], $interaction['session'],
            $interaction['timeframe'], WallSetupRules::VERSION, WallSetupOutcomeEvaluator::VERSION]));
        $cached = null;
        try {
            $cached = Cache::get($key);
        } catch (\Throwable) {
            // A missing cache only means a bounded rescan of the retained source.
        }
        $registry = is_array($cached) && is_int($cached['last_id'] ?? null) && $cached['last_id'] >= 0
            && is_array($cached['seeds'] ?? null) && count($cached['seeds']) <= self::SEED_LIMIT
            && strlen(json_encode($cached)) <= self::REGISTRY_BYTES ? $cached
                : ['last_id' => 0, 'seeds' => [], 'omitted' => false];
        $seeds = $registry['seeds'];
        $retained = strlen(json_encode($seeds));
        $metadata = DB::table('wall_observations')->where('symbol', $interaction['symbol'])
            ->where('dataset', 'wall_interactions')->where('analysis_session', $interaction['session'])
            ->where('schema_version', 'wall-interactions.v1')->where('id', '>', $registry['last_id'])
            ->orderBy('id')->limit(self::CHECKPOINT_LIMIT + 1)->get(['id']);
        $truncated = ($registry['omitted'] ?? false) || $metadata->count() > self::CHECKPOINT_LIMIT;
        if ($metadata->count() > self::CHECKPOINT_LIMIT) {
            $metadata->pop();
        }
        foreach ($metadata->chunk(self::PAGE_SIZE) as $page) {
            $query = DB::table('wall_observations')->whereIn('id', $page->pluck('id')->all())
                ->select(['id', 'content_hash', 'recorded_at', 'payload_json->timeframe as timeframe',
                    'payload_json->setups->rule_version as rule_version', 'payload_json->price_evidence_hash as price_hash']);
            $this->boundedSeeds($query);
            $records = $query->get()->keyBy('id');
            foreach ($page as $identity) {
                $registry['last_id'] = (int) $identity->id;
                $record = $records->get($identity->id);
                if (! $record || $record->timeframe !== $interaction['timeframe'] || $record->rule_version !== WallSetupRules::VERSION) {
                    continue;
                }
                if ($record->seeds_oversized) {
                    $registry['omitted'] = $truncated = true;

                    continue;
                }
                $rows = json_decode($record->seeds ?? '[]', true);
                foreach (is_array($rows) ? $rows : [] as $seed) {
                    if (! is_array($seed) || ! is_string($seed['id'] ?? null) || $seed['id'] === ''
                        || ($seed['scope']['symbol'] ?? null) !== $interaction['symbol']
                        || ($seed['scope']['session'] ?? null) !== $interaction['session']
                        || ($seed['scope']['timeframe'] ?? null) !== $interaction['timeframe']) {
                        continue;
                    }
                    $id = $seed['id'];
                    if (($seeds[$id]['status'] ?? null) === 'conditions_met') {
                        continue;
                    }
                    $entry = [...$this->compact($seed), 'review_basis' => 'saved_assessment',
                        'first_recorded_at' => CarbonImmutable::parse($record->recorded_at, 'UTC')->toIso8601String(),
                        'assessment_hash' => $record->content_hash, 'source_price_evidence_hash' => $record->price_hash];
                    $size = strlen(json_encode($entry));
                    $nextSize = $retained - (isset($seeds[$id]) ? strlen(json_encode($seeds[$id])) + strlen($id) + 4 : 0)
                        + $size + strlen($id) + 4;
                    if ($size > self::SEED_BYTES || (! isset($seeds[$id]) && count($seeds) >= self::SEED_LIMIT)
                        || $nextSize > self::REGISTRY_BYTES - 1024) {
                        $registry['omitted'] = $truncated = true;

                        continue;
                    }
                    $seeds[$id] = $entry;
                    $retained = $nextSize;
                }
            }
            unset($records, $rows);
        }
        $registry['seeds'] = $seeds;
        if ($metadata->isNotEmpty() && strlen(json_encode($registry)) <= self::REGISTRY_BYTES) {
            try {
                Cache::put($key, $registry, now()->addHours(36));
            } catch (\Throwable) {
                // Original evidence remains authoritative when cache writes fail.
            }
        }

        return $seeds;
    }

    /** Only primary-key pages parse JSON. Reject huge fields before PDO buffers them. */
    private function boundedSeeds(Builder $query): void
    {
        $expression = $query->getGrammar()->wrap('payload_json->setups->review_seeds');
        $length = DB::connection()->getDriverName() === 'sqlite'
            ? 'LENGTH(CAST('.$expression.' AS BLOB))' : 'OCTET_LENGTH('.$expression.')';
        $query->selectRaw('CASE WHEN '.$length.' <= '.self::SEED_FIELD_BYTES.' THEN '.$expression.' ELSE NULL END AS seeds')
            ->selectRaw('CASE WHEN '.$length.' > '.self::SEED_FIELD_BYTES.' THEN 1 ELSE 0 END AS seeds_oversized');
    }

    private function compact(array $seed): array
    {
        return array_intersect_key($seed, array_flip(['id', 'side', 'strike', 'pattern', 'direction', 'status',
            'observed_at', 'confirmed_at', 'terminal_at', 'close', 'wall_identity', 'evidence_grade', 'reason',
            'scope', 'confirmation_scope', 'confirmation_wall_hash', 'assessment_id', 'expires_at',
            'entry_zone', 'invalidation', 'target']));
    }
}
