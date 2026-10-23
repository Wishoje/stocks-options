<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallInteractionCheckpoint;
use App\Support\WallIntelligence\WallInteractionDetector;
use PHPUnit\Framework\TestCase;

class WallInteractionCheckpointTest extends TestCase
{
    public function test_checkpoint_preserves_assessed_states_events_seeds_and_exact_source_identities(): void
    {
        $analysis = ['schema_version' => WallInteractionDetector::SCHEMA, 'symbol' => 'SPY',
            'session' => '2026-10-22', 'timeframe' => '7d', 'as_of' => '2026-10-22T19:50:00Z',
            'price_evidence_hash' => str_repeat('a', 64), 'rules' => ['version' => 'unchanged'],
            'current' => ['put' => ['status' => 'testing', 'wall_identity' => 'original']],
            'events' => [['status' => 'touch', 'bar_id' => 'original-bar']],
            'bars' => array_fill(0, 78, ['id' => 'original-bar', 'c' => 100]),
            'readings' => array_fill(0, 152, ['evidence' => str_repeat('x', 1000)]),
            'setups' => ['current' => ['put' => ['status' => 'conditions_met', 'entry_zone' => ['low' => 100, 'high' => 101]]],
                'review_seeds' => [['id' => 'first-confirmation', 'target' => ['strike' => 105]]],
                'readings' => array_fill(0, 152, ['evidence' => str_repeat('y', 2000)])]];
        $observations = [['evidence_hash' => str_repeat('b', 64)], ['evidence_hash' => str_repeat('c', 64)], ['evidence_hash' => str_repeat('b', 64)]];
        $checkpoint = (new WallInteractionCheckpoint)->build($analysis, $observations);
        $expected = $analysis;
        unset($expected['bars'], $expected['readings'], $expected['setups']['readings']);
        $this->assertSame($expected, array_diff_key($checkpoint, ['storage' => true]));
        $this->assertSame([str_repeat('b', 64), str_repeat('c', 64)], $checkpoint['storage']['source_observation_hashes']);
        $this->assertSame(78, $checkpoint['storage']['source_bar_count']);
        $this->assertSame(152, $checkpoint['storage']['interaction_reading_count']);
        $this->assertSame(152, $checkpoint['storage']['setup_reading_count']);
        $this->assertLessThan(strlen(json_encode($analysis)) * .02, strlen(json_encode($checkpoint)));
        $this->assertCount(152, $analysis['readings']);
    }

    public function test_unidentified_or_unsupported_inputs_keep_the_full_assessment(): void
    {
        $analysis = ['schema_version' => WallInteractionDetector::SCHEMA, 'price_evidence_hash' => str_repeat('a', 64),
            'bars' => [['c' => 100]], 'readings' => [['status' => 'testing']]];
        $projector = new WallInteractionCheckpoint;
        $this->assertSame($analysis, $projector->build($analysis, []));
        foreach ([[], ['evidence_hash' => 'invalid']] as $observation) {
            $this->assertSame($analysis, $projector->build($analysis, [$observation]));
        }
        foreach (['price_evidence_hash' => 'invalid', 'schema_version' => 'future-schema'] as $key => $value) {
            $changed = [...$analysis, $key => $value];
            $this->assertSame($changed, $projector->build($changed, [['evidence_hash' => str_repeat('b', 64)]]));
        }
    }
}
