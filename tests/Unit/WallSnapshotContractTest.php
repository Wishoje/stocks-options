<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallSnapshotContract;
use PHPUnit\Framework\TestCase;

class WallSnapshotContractTest extends TestCase
{
    private function input(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/wall-foundation.json'), true);
    }

    private function snapshot(?array $input = null, array $changes = []): array
    {
        return (new WallSnapshotContract)->fromLegacyGex($input ?? $this->input(), [...[
            'symbol' => 'SPY', 'timeframe' => '14d', 'view' => 'latest_eod', 'dataset' => 'local_review',
            'captured_at' => '2026-08-21T12:00:00+00:00', 'http_status' => 200,
        ], ...$changes]);
    }

    public function test_additive_normalization_preserves_raw_fields_and_reconciles_legs(): void
    {
        $s = $this->snapshot();
        $this->assertSame($this->input(), $s['raw']['gex_response']);
        $this->assertSame(-7000000.0, $s['measurements']['raw_net_gex']);
        $this->assertSame(-70000.0, $s['measurements']['complete_net_gex_per_1pct']);
        $this->assertSame(-100000.0, $s['normalized_by_strike'][0]['net_gex']);
        $this->assertTrue($s['reconciliation']['call_minus_put_matches_net']);
        $this->assertTrue($s['quality']['source_inputs_complete']);
        $this->assertSame('review_only', $s['quality']['state']);
        $this->assertFalse($s['quality']['actionable']);
        $this->assertFalse($s['quality']['historical_outcome_eligible']);
    }

    public function test_missing_provenance_and_unimplemented_scores_are_never_fabricated(): void
    {
        $s = $this->snapshot();
        foreach (['spot_timestamp', 'greeks_timestamp', 'oi_date', 'observed_at', 'reference_spot'] as $field) {
            $this->assertNull($s['provenance'][$field]);
            $this->assertNotEmpty($s['unavailable_reasons'][$field]);
        }
        $this->assertSame(560.0, $s['measurements']['legacy_hvl']);
        $this->assertNull($s['measurements']['gamma_flip']);
        $this->assertNull($s['measurements']['strength_score']);
        $this->assertNull($s['measurements']['evidence_grade']);
    }

    public function test_input_gaps_keep_available_estimates_separate_from_complete_values(): void
    {
        $input = $this->input();
        $input['social_quality']['publishable'] = false;
        $input['social_quality']['missing_input_rows'] = 2;
        $s = $this->snapshot($input);
        $this->assertSame('partial', $s['quality']['state']);
        $this->assertContains('missing_contract_inputs', $s['quality']['reasons']);
        $this->assertSame(-70000.0, $s['measurements']['available_input_net_gex_per_1pct']);
        $this->assertNull($s['measurements']['complete_net_gex_per_1pct']);
    }

    public function test_missing_snapshot_is_unavailable_instead_of_zero(): void
    {
        $s = $this->snapshot(['error' => 'Preparing'], ['http_status' => 202]);
        $this->assertSame('unavailable', $s['quality']['state']);
        $this->assertNull($s['measurements']['raw_net_gex']);
        $this->assertNull($s['reconciliation']['call_minus_put_matches_net']);
        $this->assertNull($s['measurements']['put_wall']);
    }

    public function test_real_zero_is_retained_but_invalid_values_block_a_total(): void
    {
        $input = $this->input();
        foreach ($input['strike_data'] as &$row) {
            $row['net_gex'] = $row['call_gex'] = $row['put_gex'] = 0;
        }
        unset($row);
        $this->assertSame(0.0, $this->snapshot($input)['measurements']['complete_net_gex_per_1pct']);
        $input['strike_data'][0]['net_gex'] = null;
        $s = $this->snapshot($input);
        $this->assertNull($s['measurements']['raw_net_gex']);
        $this->assertNull($s['normalized_by_strike'][0]['net_gex']);
        $this->assertNull(WallSnapshotContract::number(INF));
        $this->assertNull(WallSnapshotContract::number(NAN));
    }

    public function test_scope_identity_ignores_expiry_order_but_not_rolls_horizons_or_views(): void
    {
        $first = $this->snapshot();
        $input = $this->input();
        $input['expiration_dates'] = array_reverse($input['expiration_dates']);
        $this->assertTrue(WallSnapshotContract::scopeMatches($first, $this->snapshot($input)));
        $input['expiration_dates'][] = '2026-09-04';
        $this->assertFalse(WallSnapshotContract::scopeMatches($first, $this->snapshot($input)));
        $this->assertFalse(WallSnapshotContract::scopeMatches($first, $this->snapshot(changes: ['timeframe' => '7d'])));
        $this->assertFalse(WallSnapshotContract::scopeMatches($first, $this->snapshot(changes: ['view' => 'next_session'])));
    }

    public function test_mixed_dates_and_scope_mismatches_block_complete_measurements(): void
    {
        $input = $this->input();
        $input['social_quality']['source_dates'][] = '2026-08-19';
        $input['symbol'] = 'QQQ';
        $s = $this->snapshot($input);
        $this->assertContains('mixed_source_dates', $s['quality']['reasons']);
        $this->assertContains('scope_mismatch', $s['quality']['reasons']);
        $this->assertNull($s['measurements']['complete_net_gex_per_1pct']);
    }

    public function test_distance_and_magnitude_changes_follow_the_declared_contract(): void
    {
        $this->assertEqualsWithDelta(0.04361733, WallSnapshotContract::distancePct(550, 550.24), 0.000001);
        $this->assertNull(WallSnapshotContract::distancePct(550, 0));
        $this->assertNull(WallSnapshotContract::distancePct(null, 550));
        $this->assertSame(20.0, WallSnapshotContract::magnitudeChangePct(-120, -100, true));
        $this->assertSame(20.0, WallSnapshotContract::magnitudeChangePct(120, -100, true));
        $this->assertNull(WallSnapshotContract::magnitudeChangePct(120, 0, true));
        $this->assertNull(WallSnapshotContract::magnitudeChangePct(120, 100, false));
    }
}
