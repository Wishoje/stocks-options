<?php

namespace App\Services\SetupStudy;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reads original saved qualifications; it never reconstructs an assessment. */
final class IntradayStudySource
{
    private const CHECKPOINT_LIMIT = 20000;

    private const PAGE_SIZE = 10;

    private const SEED_BYTES = 2097152;

    private const LIFECYCLE_BYTES = 1048576;

    private const RETAINED_BYTES = 8388608;

    private const ORIGINAL_LIMIT = 2000;

    private const STATE_LIMIT = 4000;

    private const CONFIRMATION_LIMIT = 100;

    public function read(array $symbols, string $from, string $through): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            fn ($symbol) => is_string($symbol) ? strtoupper(trim($symbol)) : '', $symbols
        ))));
        sort($symbols, SORT_STRING);
        if ($symbols === [] || $from > $through) {
            return ['samples' => [], 'truncated' => false, 'warnings' => []];
        }

        $truncated = false;
        $warnings = [];
        $originals = [];
        $nativeStates = [];
        $calendars = [];
        $retainedBytes = 0;
        foreach ($this->checkpoints($symbols, $from, $through, $truncated, $warnings) as $record) {
            if ($this->synthetic((array) $record)) {
                continue;
            }
            $session = $record->analysis_session;
            $calendar = $calendars[$session] ??= MarketSession::describe(CarbonImmutable::parse($session.' 12:00', 'America/New_York'));
            if (! $calendar['is_trading_day'] || ! is_string($record->timeframe) || $record->timeframe === ''
                || ! is_string($record->rule_version) || $record->rule_version === '') {
                continue;
            }
            $scope = [$record->symbol, $session, $record->timeframe, $record->rule_version];
            foreach ($this->decode($record->lifecycle) as $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $stateKey = $this->hash([...$scope, $item['id']]);
                    if (isset($nativeStates[$stateKey]) || count($nativeStates) < self::STATE_LIMIT) {
                        $nativeStates[$stateKey] = array_intersect_key($item, array_flip(['outcome', 'outcome_at']));
                    } else {
                        $truncated = true;
                        $warnings['states'] = 'Intraday lifecycle identity limit reached.';
                    }
                }
            }
            foreach ($this->decode($record->seeds) as $seed) {
                if (! is_array($seed) || ! $this->qualified($seed, $record, $calendar)) {
                    continue;
                }
                $nativeKey = $this->hash([...$scope, $seed['id']]);
                if (! isset($originals[$nativeKey])) {
                    $compact = $this->compactSeed($seed);
                    $bytes = strlen(json_encode($compact, JSON_THROW_ON_ERROR));
                    if (count($originals) >= self::ORIGINAL_LIMIT || $bytes > 8192 || $retainedBytes + $bytes > self::RETAINED_BYTES) {
                        $truncated = true;
                        $warnings['originals'] = 'Intraday original-assessment memory limit reached; some saved assessments were omitted.';

                        continue;
                    }
                    $retainedBytes += $bytes;
                    // Never retain the checkpoint object: its seed/lifecycle strings are cumulative histories.
                    $metadata = (object) array_intersect_key((array) $record, array_flip(['id', 'symbol', 'analysis_session',
                        'timeframe', 'rule_version', 'recorded_at', 'content_hash', 'price_hash']));
                    $originals[$nativeKey] = ['seed' => $compact, 'record' => $metadata, 'calendar' => $calendar,
                        'native_key' => $nativeKey, 'confirmations' => []];
                }
                $frozen = $originals[$nativeKey]['seed'];
                if ($this->levels($seed) === $this->levels($frozen)) {
                    $at = $this->iso($seed['observed_at']);
                    if (isset($originals[$nativeKey]['confirmations'][$at])
                        || count($originals[$nativeKey]['confirmations']) < self::CONFIRMATION_LIMIT) {
                        $originals[$nativeKey]['confirmations'][$at] = true;
                    } else {
                        $truncated = true;
                        $warnings['confirmations'] = 'Intraday repeated-confirmation limit reached.';
                    }
                }
            }
        }

        $bundles = $this->priceBundles($symbols, $from, $through, $calendars, $truncated, $warnings);
        uasort($originals, fn ($a, $b) => strcmp($this->iso($a['seed']['observed_at']), $this->iso($b['seed']['observed_at']))
            ?: strcmp($a['native_key'], $b['native_key']));
        $samples = [];
        $active = [];
        $terminalTimes = [];
        foreach ($originals as $original) {
            ['seed' => $seed, 'record' => $record, 'calendar' => $calendar, 'native_key' => $nativeKey] = $original;
            $key = $this->hash([$record->symbol, $record->analysis_session, $record->timeframe,
                $seed['side'], $seed['direction'], (float) $seed['strike'],
                (float) $seed['entry_zone']['low'], (float) $seed['entry_zone']['high'],
                (float) $seed['invalidation']['price'], (float) $seed['target']['strike'], $record->rule_version]);
            $signal = $this->iso($seed['observed_at']);
            $previous = $active[$key] ?? null;
            // A recorded terminal result closes an episode before a later confirmation.
            if ($previous === null || (isset($terminalTimes[$previous]) && $signal > $terminalTimes[$previous])) {
                $id = $this->hash(['intraday', $key, $signal]);
                $active[$key] = $id;
                $bundle = $bundles[$record->symbol.'|'.$record->analysis_session] ?? [];
                $samples[$id] = [
                    'id' => $id, 'mode' => 'intraday', 'horizon' => 'session', 'symbol' => $record->symbol,
                    'session' => $record->analysis_session, 'timeframe' => $record->timeframe,
                    'direction' => $seed['direction'], 'side' => $seed['side'], 'pattern' => $seed['pattern'],
                    'rule_version' => $record->rule_version, 'signal_at' => $signal,
                    'available_at' => $this->iso($record->recorded_at), 'signal_price' => (float) $seed['close'],
                    'entry_low' => (float) $seed['entry_zone']['low'], 'entry_high' => (float) $seed['entry_zone']['high'],
                    'invalidation' => (float) $seed['invalidation']['price'],
                    'invalidation_side' => $seed['direction'] === 'bullish' ? 'below' : 'above',
                    'target' => (float) $seed['target']['strike'], 'review_until' => $this->iso($calendar['closes_at']),
                    'native_outcome' => null, 'native_status' => $seed['status'], 'confirmations' => [],
                    'bars' => $bundle['bars'] ?? [], 'source_id' => $record->id, 'source_hash' => $record->content_hash,
                    'assessment_hash' => $record->content_hash, 'native_ids' => [], 'source_hashes' => [],
                    'source_price_evidence_hash' => $record->price_hash,
                    'price_evidence_hash' => $bundle['hash'] ?? null, 'price_recorded_at' => $bundle['recorded_at'] ?? null,
                    'evidence_grade' => $seed['evidence_grade'] ?? null, 'review_basis' => 'saved_assessment',
                    'evidence' => ['grade' => $seed['evidence_grade'] ?? null, 'checks' => $seed['evidence_checks'] ?? [],
                        'persistence' => $seed['persistence'] ?? null, 'target_room_ratio' => $seed['target_room_ratio'] ?? null,
                        'price_basis' => 'completed_five_minute_close', 'scope' => $seed['scope']],
                ];
            }
            $id = $active[$key];
            $sample = &$samples[$id];
            $sample['confirmations'] += $original['confirmations'];
            $sample['native_ids'][$seed['id']] = true;
            // Only each original assessment's source hash is provenance; repeat checkpoint hashes add no original evidence.
            $sample['source_hashes'][$record->content_hash] = true;
            $state = $nativeStates[$nativeKey] ?? [];
            $outcome = $state['outcome'] ?? $seed['status'];
            if (! isset($terminalTimes[$id])) {
                $sample['native_outcome'] = $outcome;
            }
            if (in_array($outcome, ['target_reached', 'invalidated', 'session_ended', 'expired', 'entry_missed', 'no_setup'], true)) {
                $at = $this->iso($state['outcome_at'] ?? $seed['terminal_at'] ?? null);
                if ($at !== null && (! isset($terminalTimes[$id]) || $at < $terminalTimes[$id])) {
                    $terminalTimes[$id] = $at;
                    $sample['native_outcome'] = $outcome;
                }
            }
            unset($sample);
        }
        foreach ($samples as &$sample) {
            foreach (['confirmations', 'native_ids', 'source_hashes'] as $field) {
                $sample[$field] = array_keys($sample[$field]);
                sort($sample[$field], SORT_STRING);
            }
        }
        unset($sample);
        usort($samples, fn ($a, $b) => strcmp($a['signal_at'], $b['signal_at']) ?: strcmp($a['id'], $b['id']));

        return ['samples' => $samples, 'truncated' => $truncated,
            'warnings' => array_values($warnings)];
    }

    private function scope(array $symbols, string $from, string $through, string $dataset, string $schema): Builder
    {
        return DB::table('wall_observations')->whereIn('symbol', $symbols)->where('dataset', $dataset)
            ->where('schema_version', $schema)->whereBetween('analysis_session', [$from, $through]);
    }

    /** Explicit keyset pages also bound MySQL's buffered PDO result, unlike cursor(). */
    private function checkpoints(array $symbols, string $from, string $through, bool &$truncated, array &$warnings): \Generator
    {
        $base = $this->scope($symbols, $from, $through, 'wall_interactions', 'wall-interactions.v1')
            ->whereNotNull('payload_json->setups->review_seeds');
        $lastAt = null;
        $lastId = 0;
        $seen = 0;
        do {
            $query = (clone $base)->orderBy('recorded_at')->orderBy('id')
                ->limit(min(self::PAGE_SIZE, self::CHECKPOINT_LIMIT - $seen + 1));
            if ($lastAt !== null) {
                $query->where(fn ($q) => $q->where('recorded_at', '>', $lastAt)
                    ->orWhere(fn ($tie) => $tie->where('recorded_at', $lastAt)->where('id', '>', $lastId)));
            }
            $query->select(['id', 'symbol', 'analysis_session', 'content_hash', 'recorded_at',
                'payload_json->timeframe as timeframe', 'payload_json->setups->rule_version as rule_version',
                'payload_json->price_evidence_hash as price_hash', 'payload_json->state as state',
                'payload_json->dataset as source_dataset', 'payload_json->demonstration as demonstration',
                'payload_json->setup_reviews->demonstration as review_demonstration']);
            $this->boundedProjection($query, 'payload_json->setups->review_seeds', 'seeds', self::SEED_BYTES);
            $this->boundedProjection($query, 'payload_json->setup_lifecycle->items', 'lifecycle', self::LIFECYCLE_BYTES);
            $rows = $query->get();
            $count = $rows->count();
            foreach ($rows as $record) {
                if (++$seen > self::CHECKPOINT_LIMIT) {
                    $truncated = true;
                    $warnings['checkpoints'] = 'Intraday checkpoint limit reached; later saved assessments were omitted.';

                    return;
                }
                $lastAt = $record->recorded_at;
                $lastId = $record->id;
                if ($record->seeds_oversized || $record->lifecycle_oversized) {
                    $truncated = true;
                    $warnings['payload'] = 'Oversized intraday checkpoint fields were omitted to keep reads bounded.';
                }
                yield $record;
            }
            unset($rows);
        } while ($count === self::PAGE_SIZE);
    }

    /** Reject an oversized JSON field inside SQL, before PDO buffers it in PHP. */
    private function boundedProjection(Builder $query, string $path, string $alias, int $limit): void
    {
        $expression = $query->getGrammar()->wrap($path);
        $length = DB::connection()->getDriverName() === 'sqlite'
            ? 'LENGTH(CAST('.$expression.' AS BLOB))' : 'OCTET_LENGTH('.$expression.')';
        $query->selectRaw('CASE WHEN '.$length.' <= '.$limit.' THEN '.$expression.' ELSE NULL END AS '.$alias)
            ->selectRaw('CASE WHEN '.$length.' > '.$limit.' THEN 1 ELSE 0 END AS '.$alias.'_oversized');
    }

    private function compactSeed(array $seed): array
    {
        $compact = array_intersect_key($seed, array_flip(['id', 'side', 'strike', 'pattern', 'direction', 'status',
            'observed_at', 'confirmed_at', 'terminal_at', 'close', 'wall_identity', 'evidence_grade',
            'scope', 'evidence_checks', 'persistence', 'target_room_ratio']));
        $compact['entry_zone'] = array_intersect_key($seed['entry_zone'], array_flip(['low', 'high']));
        $compact['invalidation'] = ['price' => $seed['invalidation']['price']];
        $compact['target'] = ['strike' => $seed['target']['strike']];

        return $compact;
    }

    private function qualified(array $seed, object $record, array $calendar): bool
    {
        if (($seed['status'] ?? null) !== 'conditions_met' && ($seed['ever_eligible'] ?? false) !== true) {
            return false;
        }
        if ($this->synthetic($seed) || empty($seed['id']) || ! is_string($seed['id']) || ! is_string($seed['status'] ?? null)
            || ! is_string($seed['wall_identity'] ?? null) || $seed['wall_identity'] === ''
            || ! in_array($seed['direction'] ?? null, ['bullish', 'bearish'], true)
            || ! in_array($seed['side'] ?? null, ['put', 'call'], true)
            || ! in_array($seed['pattern'] ?? null, ['reversal', 'reclaim', 'continuation'], true)
            || ($seed['scope']['symbol'] ?? null) !== $record->symbol
            || ($seed['scope']['session'] ?? null) !== $record->analysis_session
            || ($seed['scope']['timeframe'] ?? null) !== $record->timeframe
            || ($seed['scope']['units'] ?? null) !== 'USD_per_1pct_move') {
            return false;
        }
        foreach ([$seed['strike'] ?? null, $seed['close'] ?? null, $seed['entry_zone']['low'] ?? null,
            $seed['entry_zone']['high'] ?? null, $seed['invalidation']['price'] ?? null, $seed['target']['strike'] ?? null] as $number) {
            if (! is_numeric($number) || ! is_finite((float) $number) || $number <= 0) {
                return false;
            }
        }
        $at = $this->iso($seed['observed_at'] ?? null);
        $confirmed = $this->iso($seed['confirmed_at'] ?? null);
        $recorded = $this->iso($record->recorded_at);
        $low = (float) $seed['entry_zone']['low'];
        $high = (float) $seed['entry_zone']['high'];

        return $at !== null && $confirmed !== null && $recorded !== null && $confirmed <= $at
            && $confirmed >= $this->iso($calendar['opens_at']) && $at <= $this->iso($calendar['closes_at'])
            && $at <= $recorded && $low <= $high
            && ($seed['direction'] === 'bullish'
                ? $seed['invalidation']['price'] < $low && $seed['target']['strike'] > $high
                : $seed['invalidation']['price'] > $high && $seed['target']['strike'] < $low);
    }

    private function priceBundles(array $symbols, string $from, string $through, array $calendars, bool &$truncated, array &$warnings): array
    {
        $latest = $this->scope($symbols, $from, $through, 'intraday_price_bars', 'wall-price-bars.v1')
            ->selectRaw('MAX(id) as id')->groupBy('symbol', 'analysis_session');
        $bundles = [];
        $lastId = 0;
        do {
            $query = DB::table('wall_observations')->whereIn('id', $latest)->where('id', '>', $lastId)
                ->orderBy('id')->limit(self::PAGE_SIZE)->select(['id', 'symbol', 'analysis_session', 'content_hash', 'recorded_at']);
            $this->boundedProjection($query, 'payload_json->bars', 'bars', 131072);
            $rows = $query->get();
            $count = $rows->count();
            foreach ($rows as $row) {
                $lastId = $row->id;
                if ($row->bars_oversized || count($bundles) >= 512) {
                    $truncated = true;
                    $warnings['prices'] = 'Intraday price-evidence memory limit reached; some price evidence was omitted.';
                    if (count($bundles) >= 512) {
                        break 2;
                    }

                    continue;
                }
                $calendar = $calendars[$row->analysis_session] ?? MarketSession::describe(CarbonImmutable::parse($row->analysis_session.' 12:00', 'America/New_York'));
                $open = strtotime($calendar['opens_at'] ?? '') ?: PHP_INT_MAX;
                $close = min(strtotime($calendar['closes_at'] ?? '') ?: 0, strtotime($row->recorded_at) ?: 0);
                $bars = $this->decode($row->bars);
                $counts = array_count_values(array_map(fn ($bar) => is_array($bar) ? (string) ($bar['t'] ?? '') : '', $bars));
                $normalized = [];
                foreach ($bars as $bar) {
                    if (! is_array($bar) || ! is_numeric($bar['t'] ?? null)
                        || (float) $bar['t'] != (int) $bar['t'] || (int) $bar['t'] % 300000 !== 0
                        || ($counts[(string) $bar['t']] ?? 0) !== 1) {
                        continue;
                    }
                    $start = (int) ($bar['t'] / 1000);
                    if ($start < $open || $start + 300 > $close) {
                        continue;
                    }
                    foreach (['o', 'h', 'l', 'c'] as $field) {
                        if (! is_numeric($bar[$field] ?? null) || ! is_finite((float) $bar[$field]) || $bar[$field] <= 0) {
                            continue 2;
                        }
                    }
                    if ($bar['l'] > min($bar['o'], $bar['c']) || $bar['h'] < max($bar['o'], $bar['c']) || $bar['l'] > $bar['h']) {
                        continue;
                    }
                    $normalized[] = ['at' => gmdate('c', $start + 300), 'open' => (float) $bar['o'],
                        'high' => (float) $bar['h'], 'low' => (float) $bar['l'], 'close' => (float) $bar['c']];
                }
                usort($normalized, fn ($a, $b) => strcmp($a['at'], $b['at']));
                $bundles[$row->symbol.'|'.$row->analysis_session] = ['bars' => $normalized,
                    'hash' => $row->content_hash, 'recorded_at' => $this->iso($row->recorded_at)];
            }
            unset($rows);
        } while ($count === self::PAGE_SIZE);

        return $bundles;
    }

    private function synthetic(array $value): bool
    {
        return in_array($value['state'] ?? null, ['demonstration', 'synthetic_review'], true)
            || in_array($value['source_dataset'] ?? $value['dataset'] ?? null, ['synthetic_review', 'demonstration'], true)
            || in_array($value['provenance']['quote_source'] ?? null, ['synthetic_review', 'demonstration'], true)
            || in_array($value['demonstration'] ?? false, [true, 1, '1', 'true'], true)
            || in_array($value['review_demonstration'] ?? false, [true, 1, '1', 'true'], true);
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }

    private function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strtotime($value) === false) {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc()->toIso8601String();
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function levels(array $seed): array
    {
        return [$seed['side'], $seed['direction'], $seed['wall_identity'], (float) $seed['strike'],
            (float) $seed['entry_zone']['low'], (float) $seed['entry_zone']['high'],
            (float) $seed['invalidation']['price'], (float) $seed['target']['strike']];
    }
}
