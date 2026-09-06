<?php

namespace App\Console\Commands;

use App\Support\EodSnapshotHealth;
use App\Support\EodSnapshotSelector;
use App\Support\Symbols;
use Illuminate\Console\Command;

class InspectEodSnapshotHealth extends Command
{
    protected $signature = 'gex:snapshot-health
        {--symbols=SPY,QQQ,IWM,TSLA,AAPL : Comma-separated symbols to inspect}
        {--anchor= : Optional historical completed-session cutoff}
        {--ratio= : Optional selector side-ratio policy to inspect}
        {--repair : Queue missing manifest repair for an already certified clean revision}';

    protected $description = 'Inspect certified EOD snapshot health; optional repair never fetches or certifies raw data';

    public function handle(EodSnapshotHealth $health, EodSnapshotSelector $selector): int
    {
        $symbols = array_values(array_unique(array_map(
            static fn ($symbol): string => Symbols::canon(trim($symbol)),
            explode(',', (string) $this->option('symbols'))
        )));
        if ($symbols === [] || count($symbols) > 100
            || array_filter($symbols, static fn ($symbol): bool => ! Symbols::isValid($symbol))) {
            $this->error('Supply between 1 and 100 valid symbols.');

            return self::FAILURE;
        }
        $ratio = $this->option('ratio');
        $anchor = $this->option('anchor');
        if ($anchor !== null && ! \App\Support\EodSnapshotManifestBuilder::isDate((string) $anchor)) {
            $this->error('The anchor must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }
        if ($ratio !== null && (! is_numeric($ratio) || ! is_finite((float) $ratio)
            || (float) $ratio < 0.01 || (float) $ratio > 1.0)) {
            $this->error('The ratio must be between 0.01 and 1.');

            return self::FAILURE;
        }
        $policy = $health->policy(
            $selector->resolvedAnchorDate($this->option('anchor')),
            $ratio !== null ? (float) $ratio : null
        );
        $rows = [];
        foreach ($symbols as $symbol) {
            $head = $health->head($symbol);
            $manifest = $health->read($symbol, $policy, requireCurrent: false);
            $repair = $this->option('repair') ? $health->requestRebuild($symbol, $policy) : null;
            $rows[] = [
                'symbol' => $symbol, 'head' => $head,
                'manifest_found' => $manifest !== null,
                'usable_for_cold_reads' => $manifest !== null && ! $manifest['dirty'],
                'expiration_count' => $manifest['expiration_count'] ?? null,
                'latest_data_timestamp' => $manifest['latest_data_timestamp'] ?? null,
                'repair_run_id' => $repair?->id,
                'state' => ! EodSnapshotHealth::enabled() ? 'disabled'
                    : ($head === null ? 'no_certified_generation'
                        : ($head['dirty'] ? 'raw_mutation_unpublished'
                            : ($manifest === null ? 'manifest_missing_or_invalid' : 'ready'))),
            ];
        }
        $this->line(json_encode([
            'enabled' => EodSnapshotHealth::enabled(), 'read_enabled' => EodSnapshotHealth::readsEnabled(), 'policy' => $policy,
            'repair_requested' => (bool) $this->option('repair'), 'symbols' => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
