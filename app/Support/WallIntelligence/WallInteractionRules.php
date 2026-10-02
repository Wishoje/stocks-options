<?php

namespace App\Support\WallIntelligence;

/** Change the version whenever a detection threshold or transition changes. */
final class WallInteractionRules
{
    public const VERSION = 'completed-5m.v1';

    public static function profile(): array
    {
        return [
            'rule_version' => self::VERSION,
            'price_input' => 'completed_unadjusted_ohlc',
            'symbol_class' => 'US_equities_and_ETFs',
            'bar_interval_minutes' => 5,
            'session' => 'regular_market_hours',
            'touch_tolerance_bps' => 5,
            'minimum_tolerance_usd' => .01,
            'approach_tolerance_multiple' => 3,
            'acceptance_closes' => 2,
            'acceptance_minutes' => 10,
            'reclaim_closes' => 2,
            'retest_window_minutes' => 30,
            'retest_requires_prior_acceptance' => true,
            'event_timestamp' => 'completed_bar_end',
            'reaction_window_minutes' => 15,
            'continuation_tolerance_multiple' => 1,
            'maximum_wall_age_seconds' => 900,
            'maximum_bar_age_seconds' => 600,
            'gap_policy' => 'reset_without_filling',
            'wall_change_policy' => 'reset_on_strike_basis_scope_or_source_change',
            'session_expiry' => 'regular_session_close',
            'break_invalidation' => 'two_original_side_closes_or_context_reset',
            'calibration' => 'initial_descriptive_rules_not_validated_trading_edge',
        ];
    }
}
