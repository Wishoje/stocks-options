<?php

namespace Tests\Unit;

use App\Support\Swing\SwingIdeaRules;
use Tests\Fixtures\SwingInput;
use Tests\TestCase;

class SwingIdeaRulesTest extends TestCase
{
    public function test_both_horizons_use_signed_exposure_daily_trend_and_supported_geometry(): void
    {
        foreach (['short', 'position'] as $horizon) {
            $plan = (new SwingIdeaRules)->propose(SwingInput::make(), $horizon);
            $this->assertSame('watching', $plan['status']);
            $this->assertSame('bullish', $plan['direction']);
            $this->assertSame(111., $plan['levels']['target']);
            $this->assertLessThan(0, $plan['evidence']['net_gex']);
            $this->assertGreaterThanOrEqual(1.5, $plan['evidence']['target_room_ratio']);
            $this->assertEqualsWithDelta(11.1111, $plan['evidence']['oi_change']['pct'], .001);
        }
    }

    public function test_a_nearer_obstacle_or_rolling_off_target_cannot_be_skipped(): void
    {
        $input = SwingInput::make();
        $near = $input['strikes'][1];
        $near['strike'] = 108.;
        $input['strikes'][] = $near;
        $this->assertSame('no_idea', (new SwingIdeaRules)->propose($input, 'short')['status']);
        $input = SwingInput::make();
        foreach ($input['strikes'] as &$row) {
            $row['expirations'][0]['expiration'] = '2026-10-30';
        }
        $this->assertStringContainsString('expires', (new SwingIdeaRules)->propose($input, 'short')['reason']);
    }

    public function test_mixed_dates_price_gaps_bad_ohlc_and_unknown_metrics_are_not_filled(): void
    {
        $input = SwingInput::make();
        unset($input['prices'][40]);
        $this->assertSame('no_idea', (new SwingIdeaRules)->propose($input, 'short')['status']);
        $input = SwingInput::make();
        $input['prices'][69]['low'] = null;
        $this->assertSame('no_idea', (new SwingIdeaRules)->propose($input, 'short')['status']);
        $input = SwingInput::make();
        $input['strikes'][0]['expirations'][0]['data_date'] = '2026-10-22';
        $this->assertSame('no_idea', (new SwingIdeaRules)->propose($input, 'short')['status']);
        $input = SwingInput::make();
        $input['dex'] = [];
        $this->assertNull((new SwingIdeaRules)->propose($input, 'short')['evidence']['dex_shares']);
    }

    public function test_daily_lifecycle_freezes_levels_ignores_wicks_and_never_reopens_terminal_ideas(): void
    {
        $rules = new SwingIdeaRules;
        $plan = $rules->propose(SwingInput::make(), 'short');
        $levels = $plan['levels'];
        $plan = $rules->advance($plan, ['date' => '2026-10-26', 'open' => 106., 'high' => 112., 'low' => 105.9, 'close' => 106.5]);
        $this->assertSame('conditions_met', $plan['status']);
        $this->assertNull($plan['outcome']); // A target wick on the qualification day is not a later target close.
        $plan = $rules->advance($plan, ['date' => '2026-10-27', 'open' => 106.5, 'high' => 112., 'low' => 104., 'close' => 108.]);
        $this->assertSame('in_progress', $plan['status']);
        $plan = $rules->advance($plan, ['date' => '2026-10-28', 'open' => 108., 'high' => 112., 'low' => 107., 'close' => 111.]);
        $this->assertSame('target_reached', $plan['outcome']);
        $this->assertSame($levels, $plan['levels']);
        $this->assertSame($plan, $rules->advance($plan, ['date' => '2026-10-29', 'close' => 90.]));
    }

    public function test_bearish_geometry_and_close_based_invalidation_mirror_bullish_rules(): void
    {
        $input = SwingInput::make();
        foreach ($input['prices'] as &$bar) {
            [$bar['high'], $bar['low']] = [200 - $bar['low'], 200 - $bar['high']];
            $bar['open'] = 200 - $bar['open'];
            $bar['close'] = 200 - $bar['close'];
        }
        foreach ($input['strikes'] as &$row) {
            $row['strike'] = 200 - $row['strike'];
            $row['expirations'][0]['net_gex'] *= -1;
        }
        $rules = new SwingIdeaRules;
        $plan = $rules->propose($input, 'short');
        $this->assertSame('bearish', $plan['direction']);
        $this->assertSame(89., $plan['levels']['target']);
        $plan = $rules->advance($plan, ['date' => '2026-10-26', 'high' => 94.1, 'low' => 93., 'close' => 93.5]);
        $this->assertSame('conditions_met', $plan['status']);
        $plan = $rules->advance($plan, ['date' => '2026-10-27', 'high' => 97., 'low' => 93., 'close' => 96.]);
        $this->assertSame('invalidated', $plan['outcome']);
    }

    public function test_missing_session_or_price_evidence_cannot_claim_progress_and_activation_is_causal(): void
    {
        $rules = new SwingIdeaRules;
        $plan = $rules->propose(SwingInput::make(), 'short');
        $this->assertSame('needs_update', $rules->advance($plan, ['date' => '2026-10-27', 'close' => 106.5])['status']);
        $plan['activation_session'] = '2026-10-27';
        $next = $rules->advance($plan, ['date' => '2026-10-26', 'high' => 107., 'low' => 105.9, 'close' => 106.5]);
        $this->assertSame('watching', $next['status']);
        $this->assertNull($next['qualified_at']);
        $this->assertSame('2026-11-27', SwingIdeaRules::sessionAfter('2026-11-25', 1));
    }
}
