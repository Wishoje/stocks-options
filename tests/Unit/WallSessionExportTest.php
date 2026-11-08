<?php

namespace Tests\Unit;

use App\Services\AiExportBuilder;
use App\Services\IntradayWallTracker;
use App\Support\WallIntelligence\WallSetupOutcomeEvaluator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WallSessionExportTest extends TestCase
{
    public function test_ai_export_keeps_idea_conditions_and_hides_private_outcome_reviews(): void
    {
        Http::preventStrayRequests();
        $payload = ['symbol' => 'SPY', 'session' => '2026-10-27', 'timeframe' => '7d',
            'wall_interaction' => ['setups' => ['rule_version' => 'conditional-walls.v1'], 'setup_reviews' => [
                'rule_version' => WallSetupOutcomeEvaluator::VERSION, 'review_window' => 'regular_session_close',
                'wall_change_policy' => 'continue_original_qualified_levels',
                'summary' => ['qualified' => 1, 'qualified_outcomes' => ['in_progress' => 1]],
                'cases' => [['id' => 'original', 'outcome' => 'in_progress', 'target' => ['strike' => 101],
                    'invalidation' => ['price' => 99.95], 'entry_expires_at' => '2026-10-27T14:15:00Z',
                    'review_until' => '2026-10-27T20:00:00Z']],
            ]]];
        $this->mock(IntradayWallTracker::class)->shouldReceive('history')->once()->with('SPY', null, '7d')->andReturn($payload);
        $item = app(AiExportBuilder::class)->build(['SPY'], ['intraday_walls'], '14d')['items'][0];
        $this->assertTrue($item['intraday_walls']['ok']);
        $this->assertSame('ideas.v1', $item['intraday_walls']['data']['presentation']);
        $this->assertArrayNotHasKey('setup_reviews', $item['intraday_walls']['data']['wall_interaction']);
        $this->assertArrayNotHasKey('outcome_rule_version', $item['summary']['intraday_setups']);
        $this->assertArrayNotHasKey('recorded_reviews', $item['summary']['intraday_setups']);
        Http::assertNothingSent();
    }
}
