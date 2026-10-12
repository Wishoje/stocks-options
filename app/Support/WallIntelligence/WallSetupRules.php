<?php

namespace App\Support\WallIntelligence;

final class WallSetupRules
{
    public const VERSION = 'conditional-walls.v1';

    public static function profile(): array
    {
        return [
            'rule_version' => self::VERSION,
            'interaction_rule_version' => WallInteractionRules::VERSION,
            'confirmation' => [
                'continuation' => 'acceptance_then_retest_then_failure_then_further_close',
                'reversal' => 'touch_then_two_consecutive_closes_away',
                'reclaim' => 'two_original_side_closes_then_separate_retest_and_close_away',
            ],
            'entry_band_multiples' => [1, 3],
            'invalidation' => 'one_completed_close_beyond_opposite_touch_band_edge',
            'minimum_target_to_invalidation_ratio' => 1.5,
            'target_basis' => 'nearest_ranked_wall_in_direction_at_confirmation',
            'minimum_model_oi_coverage_pct' => 98,
            'minimum_wall_observations' => 2,
            'minimum_wall_duration_minutes' => 5,
            'strong_persistence_observations' => 3,
            'strong_persistence_minutes' => 10,
            'reversal_confirmation_minutes' => 15,
            'reclaim_retest_minutes' => 15,
            'entry_window_minutes' => 15,
            'continuation_confirmation_minutes' => 30,
            'session_expiry' => 'regular_session_close',
            'missed_entry_policy' => 'terminal_until_new_price_sequence',
            'terminal_states' => ['entry_missed', 'invalidated', 'expired', 'no_setup', 'target_reached'],
            'evidence_rubric' => 'three_checks.v1',
            'evidence_checks' => ['ordered_price_confirmation', 'wall_persistence', 'supported_geometry'],
            'evidence_grades' => ['strong' => 3, 'moderate' => 2, 'weak' => [0, 1]],
            'interpretation' => 'Initial rule-based scenarios at recorded times. Evidence grades are not win probabilities or validated trading performance.',
        ];
    }
}
