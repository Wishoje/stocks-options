<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\WallSetupLifecycle;
use PHPUnit\Framework\TestCase;

class WallSetupLifecycleTest extends TestCase
{
    public function test_projection_keeps_exact_setup_identity_and_distinguishes_qualification_review_and_result(): void
    {
        $analysis = ['symbol' => 'SPY', 'session' => '2026-10-26', 'timeframe' => '7d',
            'evaluated_through' => '2026-10-26T15:00:00Z', 'setups' => ['current' => ['put' => ['id' => 'new', 'status' => 'conditions_met']]]];
        $case = ['id' => 'old', 'side' => 'put', 'strike' => 100., 'outcome' => 'in_progress', 'qualified' => true,
            'observed_at' => '2026-10-26T14:30:00Z', 'qualified_at' => '2026-10-26T14:30:00Z',
            'outcome_at' => null, 'reference_close' => 100.1, 'reason' => 'Following later closes.',
            'pattern' => 'reversal', 'direction' => 'bullish', 'entry_zone' => ['low' => 100.05, 'high' => 100.15],
            'invalidation' => ['price' => 99.95], 'target' => ['strike' => 101.]];
        $new = [...$case, 'id' => 'new'];
        $done = [...$case, 'id' => 'done', 'outcome' => 'target_reached'];
        $unknown = [...$case, 'id' => 'unknown', 'outcome' => 'future_outcome'];
        $result = (new WallSetupLifecycle)->build($analysis, ['cases' => [$case, $new, $done, $unknown]]);
        $rows = array_column($result['items'], null, 'id');
        $this->assertSame('in_progress', $rows['old']['status_group']);
        $this->assertSame('conditions_met', $rows['new']['status_group']);
        $this->assertSame('finished', $rows['done']['status_group']);
        $this->assertSame('needs_update', $rows['unknown']['status_group']);
        $this->assertSame(hash('sha256', json_encode(['SPY', '2026-10-26', 'setup', 'old'])), $rows['old']['alert_id']);
        $this->assertArrayNotHasKey('follow_up_bars', $rows['old']);
        $this->assertArrayNotHasKey('bars', $result);
        $this->assertArrayNotHasKey('evidence_bar_ids', $rows['old']);
    }

    public function test_closed_entry_window_is_follow_up_and_session_close_is_a_distinct_result(): void
    {
        $analysis = ['symbol' => 'SPY', 'session' => '2026-10-26', 'timeframe' => '7d',
            'evaluated_through' => '2026-10-26T19:00:00Z', 'setups' => ['current' => ['put' => ['id' => 'original', 'status' => 'conditions_met']]]];
        $case = ['id' => 'original', 'side' => 'put', 'strike' => 100., 'outcome' => 'in_progress', 'qualified' => true,
            'observed_at' => '2026-10-26T14:30:00Z', 'qualified_at' => '2026-10-26T14:30:00Z',
            'entry_expires_at' => '2026-10-26T14:45:00Z', 'outcome_at' => null, 'reference_close' => 100.1, 'reason' => 'Following original levels.'];
        $result = (new WallSetupLifecycle)->build($analysis, ['cases' => [$case]]);
        $this->assertSame('in_progress', $result['items'][0]['status_group']);
        $case['outcome'] = 'session_ended';
        $result = (new WallSetupLifecycle)->build($analysis, ['cases' => [$case]]);
        $this->assertSame('finished', $result['items'][0]['status_group']);
        $this->assertSame('session_ended', $result['items'][0]['outcome']);
    }
}
