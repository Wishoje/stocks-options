<?php

namespace App\Support\WallIntelligence;

use App\Models\WallObservation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

final class WallObservationStore
{
    public function record(array $snapshot): WallObservation
    {
        if (($snapshot['schema_version'] ?? null) !== WallSnapshotContract::SCHEMA
            || ($snapshot['model_version'] ?? null) !== WallSnapshotContract::MODEL
            || ! in_array($snapshot['provenance']['dataset'] ?? null, ['local_review', 'production_capture'], true)
            || ($snapshot['quality']['actionable'] ?? true) !== false
            || ($snapshot['quality']['historical_outcome_eligible'] ?? true) !== false) {
            throw new DomainException('Batch 1 accepts only versioned, non-actionable audit captures.');
        }
        $content = $snapshot;
        // Reading the same facts again must not fabricate another market event.
        unset($content['provenance']['captured_at'], $content['provenance']['generated_at']);
        $hash = WallSnapshotContract::hash($content);
        if ($existing = WallObservation::where('content_hash', $hash)->first()) {
            return $existing;
        }
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        try {
            return WallObservation::create([
                'content_hash' => $hash, 'scope_key' => $snapshot['scope_key'],
                'symbol' => $snapshot['scope']['symbol'], 'dataset' => $snapshot['provenance']['dataset'],
                'schema_version' => $snapshot['schema_version'], 'model_version' => $snapshot['model_version'],
                'observation_kind' => 'audit_capture', 'analysis_session' => $snapshot['analysis_session'],
                'source_date' => $snapshot['provenance']['source_date'], 'observed_at' => null,
                'captured_at' => CarbonImmutable::parse($snapshot['provenance']['captured_at'])->utc(),
                'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'quality_state' => $snapshot['quality']['state'],
                'historical_outcome_eligible' => false, 'payload_bytes' => strlen($json), 'payload_json' => $json,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return WallObservation::where('content_hash', $hash)->first() ?? throw $exception;
        }
    }
}
