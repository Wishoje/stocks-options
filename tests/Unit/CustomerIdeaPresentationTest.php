<?php

namespace Tests\Unit;

use App\Support\CustomerIdeaPresentation;
use PHPUnit\Framework\TestCase;

class CustomerIdeaPresentationTest extends TestCase
{
    public function test_recorded_ideas_keep_their_levels_but_not_private_outcomes(): void
    {
        $row = ['id' => 'original', 'symbol' => 'SPY', 'side' => 'put', 'strike' => 100.,
            'session' => '2026-10-23', 'timeframe' => '7d', 'status_group' => 'finished', 'qualified' => true,
            'outcome' => 'target_reached', 'outcome_at' => '2026-10-23T18:00:00Z',
            'observed_at' => '2026-10-23T14:00:00Z', 'qualified_at' => '2026-10-23T14:00:00Z',
            'reason' => 'A later close reached the target.',
            'setup' => ['entry_zone' => ['low' => 100., 'high' => 101.], 'target' => ['strike' => 110.], 'invalidation' => ['price' => 99.]]];
        $unqualified = [...$row, 'qualified' => false, 'outcome' => 'expired'];
        $data = ['items' => [['status' => 'conditions_met', 'setup' => [...$row['setup'], 'expires_at' => '2026-10-23T14:15:00Z']]],
            'lifecycle_items' => [$row, $unqualified], 'alert_history' => [['status' => 'conditions_met', 'lifecycle' => $row]]];

        $public = CustomerIdeaPresentation::watchlist($data);

        $this->assertSame('conditions_met', $public['lifecycle_items'][0]['status_group']);
        $this->assertCount(1, $public['lifecycle_items']);
        $this->assertSame('2026-10-23T14:15:00Z', $public['items'][0]['setup']['expires_at']);
        $this->assertSame($row['setup'], $public['lifecycle_items'][0]['setup']);
        $this->assertArrayNotHasKey('outcome', $public['alert_history'][0]['lifecycle']);
        $this->assertStringNotContainsString('target_reached', json_encode($public));
        $this->assertSame('target_reached', $data['lifecycle_items'][0]['outcome']);
    }

    public function test_timeline_keeps_the_original_qualification_instead_of_relabeling_the_target_close(): void
    {
        $qualified = ['id' => 'idea', 'status' => 'conditions_met', 'status_at_observation' => 'conditions_met',
            'entry_eligible_at_observation' => true, 'observed_at' => '2026-10-23T14:00:00Z', 'close' => 100.5,
            'entry_zone' => ['low' => 100., 'high' => 101.], 'target' => ['strike' => 110.], 'invalidation' => ['price' => 99.]];
        $ended = [...$qualified, 'status' => 'target_reached', 'status_at_observation' => 'target_reached',
            'entry_eligible_at_observation' => false, 'close' => 110.5, 'observed_at' => '2026-10-23T15:00:00Z'];
        $data = ['wall_interaction' => ['setup_reviews' => ['summary' => ['qualified_outcomes' => ['target_reached' => 1]]],
            'setups' => ['current' => ['put' => $ended], 'readings' => [$qualified, $ended],
                'rules' => ['terminal_states' => ['target_reached', 'invalidated']]]]];

        $public = CustomerIdeaPresentation::timeline($data);
        $idea = $public['wall_interaction']['setups']['current']['put'];

        $this->assertArrayNotHasKey('setup_reviews', $public['wall_interaction']);
        $this->assertSame('conditions_met', $idea['status']);
        $this->assertSame(100.5, $idea['close']);
        $this->assertSame($qualified['observed_at'], $idea['observed_at']);
        $this->assertFalse($idea['entry_eligible_at_observation']);
        $this->assertFalse($idea['actionable']);
        $this->assertStringNotContainsString('target_reached', json_encode($public));
        $this->assertSame('target_reached', $data['wall_interaction']['setups']['current']['put']['status']);
    }

    public function test_confirmation_without_a_proven_qualifying_entry_does_not_become_conditions_met(): void
    {
        $row = ['id' => 'candidate', 'status' => 'invalidated', 'confirmed_at' => '2026-10-23T14:00:00Z',
            'entry_eligible_at_observation' => false];
        $public = CustomerIdeaPresentation::timeline(['wall_interaction' => ['setups' => ['current' => ['put' => $row], 'readings' => [$row]]]]);

        $this->assertSame('watch', $public['wall_interaction']['setups']['current']['put']['status']);
    }

    public function test_watch_ideas_keep_the_actual_next_price_threshold_and_confirmation_count(): void
    {
        $condition = ['version' => 'wall-next-condition.v1', 'setup_status' => 'watch', 'code' => 'reversal_close',
            'threshold' => 100.05, 'completed_closes' => 1, 'required_closes' => 2,
            'text' => 'Wait for a second completed close above 100.05.'];
        $row = ['status' => 'watch', 'next_condition' => $condition];
        $public = CustomerIdeaPresentation::timeline(['wall_interaction' => ['setups' => ['current' => ['put' => $row]]]]);

        $this->assertSame($condition, $public['wall_interaction']['setups']['current']['put']['next_condition']);
        $this->assertSame($public, CustomerIdeaPresentation::timeline($public));
    }

    public function test_swing_availability_counts_trading_sessions_without_resetting_on_qualification(): void
    {
        $original = ['symbol' => 'SPY', 'horizon' => 'short', 'source_date' => '2026-11-24',
            'activation_session' => '2026-11-25', 'levels' => ['target' => 110., 'invalidation' => 99.],
            'status' => 'watching', 'entry_deadline' => '2026-11-30'];
        $state = [...$original, 'status' => 'finished', 'outcome' => 'invalidated', 'reason' => 'Stopped out.',
            'qualified_at' => '2026-11-27', 'qualified_price' => 100.5, 'deadline' => '2026-12-04'];

        $public = CustomerIdeaPresentation::swing($original, $state);

        $this->assertSame('2026-11-25', $public['idea_date']);
        $this->assertSame('2026-12-02', $public['available_through']);
        $this->assertSame('conditions_met', $public['status']);
        $this->assertSame('2026-11-27', $public['qualified_at']);
        $this->assertSame($original['levels'], $public['levels']);
        foreach (['outcome', 'deadline', 'entry_deadline', 'outcome_at'] as $field) {
            $this->assertArrayNotHasKey($field, $public);
        }
        $this->assertStringNotContainsString('Stopped out', json_encode($public));
    }
}
