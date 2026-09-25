<?php

namespace App\Support\WallIntelligence;

use App\Http\Controllers\GexController;
use App\Models\WallObservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class WallFoundationAudit
{
    public function report(string $dataset = 'local_review', string $view = 'latest_eod', string $timeframe = '14d'): array
    {
        if (! in_array($dataset, ['local_review', 'production_capture'], true)
            || ! in_array($view, ['latest_eod', 'next_session'], true)
            || ! in_array($timeframe, ['0d', '1d', '7d', '14d', '30d', '90d'], true)) {
            throw new InvalidArgumentException('Unsupported audit scope.');
        }
        $capture = null;
        if ($dataset === 'production_capture') {
            $path = config('wall_foundation.production_capture_path');
            if (! is_file($path)) {
                throw new InvalidArgumentException('No production capture is installed locally.');
            }
            $capture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($capture['format'] ?? null) !== 'wall-foundation-capture.v1'
                || ($capture['timeframe'] ?? null) !== $timeframe) {
                throw new InvalidArgumentException('The production capture does not contain this timeframe.');
            }
        }
        $generatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $snapshots = [];
        foreach (config('wall_foundation.symbols') as $symbol) {
            $input = $capture !== null
                ? ($capture['items'][$view][$symbol] ?? ['status' => 404, 'payload' => []])
                : $this->readPublished($symbol, $timeframe, $view);
            $snapshots[] = (new WallSnapshotContract)->fromLegacyGex($input['payload'], [
                'symbol' => $symbol, 'timeframe' => $timeframe, 'view' => $view, 'dataset' => $dataset,
                'captured_at' => $capture['captured_at'] ?? $generatedAt,
                'generated_at' => $generatedAt, 'http_status' => $input['status'],
            ]);
        }
        $storageReady = Schema::hasTable('wall_observations');
        $payloadBytes = array_sum(array_map(fn ($s) => strlen(json_encode($s, JSON_THROW_ON_ERROR)), $snapshots));
        $interval = (int) config('wall_foundation.planned_interval_minutes');
        $dailyObservations = count($snapshots) * (int) ceil(390 / $interval);

        return [
            'schema_version' => WallSnapshotContract::SCHEMA, 'dataset' => $dataset,
            'generated_at' => $generatedAt, 'captured_at' => $capture['captured_at'] ?? $generatedAt,
            'review_clock' => app()->environment('local') ? config('ui_review.now') : null,
            'source_note' => $dataset === 'production_capture'
                ? 'Recorded production responses copied for local review. This is not a live feed or a historical intraday series.'
                : 'Existing local database evaluated under the local review clock. Missing symbols are not automatically fetched.',
            'snapshots' => $snapshots,
            'findings' => $this->findings(),
            'storage' => [
                'ready' => $storageReady, 'collector_enabled' => false,
                'audit_records' => $storageReady ? WallObservation::where('dataset', $dataset)->count() : 0,
                'outcome_eligible_records' => $storageReady ? WallObservation::where('dataset', $dataset)->where('historical_outcome_eligible', true)->count() : 0,
                'payload_bytes_for_selected_scope' => $payloadBytes,
                'planning' => [
                    'symbols' => count($snapshots), 'horizons' => 1, 'session_minutes' => 390,
                    'interval_minutes' => $interval, 'observations_per_session' => $dailyObservations,
                    'retention_days' => config('wall_foundation.planned_retention_days'),
                    'estimated_payload_bytes_per_session' => (int) ceil($payloadBytes * 390 / $interval),
                    'note' => 'Planning estimate from these payload sizes. Excludes indexes, backups and price-bar storage. No scheduler or automatic pruning is enabled.',
                ],
            ],
            'provider_access' => [
                'new_provider_requests' => 0, 'new_subscription_required_for_batch_1' => false,
                'future_entitlements' => 'Not verified. Trade/quote history and underlying price-bar access must be checked before the dependent batches.',
            ],
        ];
    }

    protected function readPublished(string $symbol, string $timeframe, string $view): array
    {
        $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'view' => $view,
        ]));

        return ['status' => $response->getStatusCode(), 'payload' => $response->getData(true)];
    }

    private function findings(): array
    {
        return [
            ['code' => 'gex_units', 'title' => 'GEX units need an explicit conversion',
                'detail' => 'The legacy formula is gamma × OI × 100 × spot². The new per-1% measure applies a 0.01 factor. Existing dashboard and export fields keep their original values.',
                'source' => 'GexController::buildGexPayload', 'next_batch' => '2'],
            ['code' => 'hvl_not_flip', 'title' => 'HVL and modeled gamma flip are separate',
                'detail' => 'Legacy HVL selects a sign transition between strike bars and falls back to the first strike. A spot-scenario zero crossing requires a new calculation; gamma_flip remains null.',
                'source' => 'GexController::findHVL', 'next_batch' => '4'],
            ['code' => 'intraday_assumptions', 'title' => 'Intraday repricing needs stronger provenance',
                'detail' => 'The current model combines EOD OI with a common expiry approximation and can fall back to spot 100 and IV 20%. Its delta field uses a zero baseline. These outputs are not certified wall migration history.',
                'source' => 'IntradayController::repricedGexCompute', 'next_batch' => '3–4'],
            ['code' => 'history_overwrite', 'title' => 'Existing scanner records are not intraday history',
                'detail' => 'The current writer replaces each symbol/date/timeframe record. New audit storage is append-only and deduplicates repeated evidence; proper market-observation capture starts in Batch 3.',
                'source' => 'ComputeSymbolWallSnapshots::handle', 'next_batch' => '3'],
            ['code' => 'dex_scope', 'title' => 'DEX and gamma currently use different scopes',
                'detail' => 'Existing DEX sums delta × OI × 100 in share equivalents. The positioning gamma context uses a fixed 14-day scope; neither is silently attached to another selected expiry set.',
                'source' => 'ComputePositioningJob / PositioningController', 'next_batch' => '7'],
            ['code' => 'timestamp_gaps', 'title' => 'Source date is not an observation timestamp',
                'detail' => 'The legacy published response does not certify spot/Greeks observation times or a separate provider OI date. The contract preserves these as null with reasons.',
                'source' => 'GexController / AiExportBuilder', 'next_batch' => '3'],
            ['code' => 'export_compatibility', 'title' => 'Existing consumers stay compatible',
                'detail' => 'This separate versioned contract preserves the complete raw GEX response. Dashboard, scanner, AI export and social-post field meanings are unchanged.',
                'source' => 'WallService / AiExportBuilder / SocialGexSource', 'next_batch' => '2–6'],
        ];
    }
}
