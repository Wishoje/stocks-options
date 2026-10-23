<?php

namespace App\Support\WallIntelligence;

/** Preserve assessed states and source identities without copying derived session histories. */
final class WallInteractionCheckpoint
{
    public function build(array $analysis, array $observations): array
    {
        $hash = fn ($value) => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value);
        // Keep the full assessment if its original inputs cannot be identified.
        if (($analysis['schema_version'] ?? null) !== WallInteractionDetector::SCHEMA
            || ! $hash($analysis['price_evidence_hash'] ?? null) || $observations === []) {
            return $analysis;
        }
        $identities = [];
        foreach ($observations as $observation) {
            if (! $hash($observation['evidence_hash'] ?? null)) {
                return $analysis;
            }
            $identities[] = $observation['evidence_hash'];
        }
        $analysis['storage'] = [
            'schema_version' => 'wall-interaction-checkpoint.v1',
            'source_observation_hashes' => array_values(array_unique($identities)),
            'derived_history_fields' => ['bars', 'readings', 'setups.readings'],
            'source_bar_count' => count($analysis['bars'] ?? []),
            'interaction_reading_count' => count($analysis['readings'] ?? []),
            'setup_reading_count' => count($analysis['setups']['readings'] ?? []),
        ];
        unset($analysis['bars'], $analysis['readings'], $analysis['setups']['readings']);

        return $analysis;
    }
}
