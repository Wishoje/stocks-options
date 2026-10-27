<?php

namespace Tests\Feature;

use App\Services\SetupStudy\SwingStudySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SwingStudySourceTest extends TestCase
{
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_180000_create_swing_ideas_tables.php'))->up();
        (require database_path('migrations/2025_09_26_045222_create_prices_daily.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 22:00:00', 'UTC'));
        Http::preventStrayRequests();
        Bus::fake();
    }

    public function test_finished_plan_keeps_first_qualification_and_frozen_evidence_without_writes(): void
    {
        $original = $this->plan();
        $qualified = [...$original, 'status' => 'conditions_met', 'qualified_at' => '2026-10-26',
            'qualified_price' => 106.5, 'deadline' => '2026-11-02'];
        $finished = [...$qualified, 'status' => 'finished', 'outcome' => 'target_reached',
            'levels' => [...$original['levels'], 'target' => 999.], 'evidence' => ['atr14' => 999.]];
        $id = $this->insert($original, $finished, '2026-10-28 20:20:00');
        $this->review($id, '2026-10-26', $qualified, '2026-10-26 20:20:00');
        $this->review($id, '2026-10-28', $finished, '2026-10-28 20:20:00');
        $this->price('2026-10-26', 106.5);
        $this->price('2026-10-27', 107.);
        $this->price('2026-10-28', 111.);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = (new SwingStudySource)->read(['SPY'], '2026-10-23', '2026-10-30');
        $this->assertCount(1, $result['samples']);
        $sample = $result['samples'][0];
        $this->assertSame($id, $sample['id']);
        $this->assertSame('2026-10-26T20:00:00+00:00', $sample['signal_at']);
        $this->assertSame('2026-10-26T20:20:00+00:00', $sample['available_at']);
        $this->assertSame('2026-10-23T20:30:00+00:00', $sample['first_recorded_at']);
        $this->assertSame('2026-11-02T21:00:00+00:00', $sample['review_until']);
        $this->assertSame([$sample['signal_at']], $sample['confirmations']);
        $this->assertSame(106.5, $sample['signal_price']);
        $this->assertSame(111., $sample['target']);
        $this->assertSame($original['evidence'], $sample['evidence']);
        $this->assertSame($original, $sample['source']['original_plan']);
        $this->assertSame('swing_idea_reviews', $sample['source']['qualification']['table']);
        $this->assertSame('2026-10-26', $sample['source']['qualification']['data_date']);
        $this->assertSame('finished', $sample['native_status']);
        $this->assertSame('target_reached', $sample['native_outcome']);
        $this->assertSame(['2026-10-27T20:00:00+00:00', '2026-10-28T20:00:00+00:00'], array_column($sample['bars'], 'at'));
        $this->assertFalse($result['truncated']);
        $this->assertSame([], $result['warnings']);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_watching_and_ended_unqualified_plans_remain_in_funnel_but_no_idea_does_not(): void
    {
        $original = $this->plan();
        $this->insert($original);
        $this->insert([...$original, 'horizon' => 'position', 'direction' => 'bearish'],
            [...$original, 'status' => 'finished', 'outcome' => 'entry_missed']);
        $this->insert([...$original, 'status' => 'no_idea']);
        $other = [...$original, 'symbol' => 'QQQ'];
        $this->insert($other);

        $result = (new SwingStudySource)->read(['spy'], '2026-10-23', '2026-10-30');
        $this->assertCount(2, $result['samples']);
        $samples = collect($result['samples'])->keyBy('horizon');
        $this->assertSame('watching', $samples['short']['native_status']);
        $this->assertSame('entry_missed', $samples['position']['native_outcome']);
        $this->assertSame('put', $samples['short']['side']);
        $this->assertSame('call', $samples['position']['side']);
        $this->assertSame('90d', $samples['position']['timeframe']);
        foreach ($samples as $sample) {
            $this->assertNull($sample['signal_at']);
            $this->assertNull($sample['signal_price']);
            $this->assertNull($sample['review_until']);
            $this->assertSame([], $sample['confirmations']);
            $this->assertSame([], $sample['bars']);
        }
    }

    public function test_historical_read_does_not_borrow_later_recorded_qualification_or_current_outcome(): void
    {
        $original = $this->plan();
        $finished = [...$original, 'status' => 'finished', 'qualified_at' => '2026-10-26',
            'qualified_price' => 106.5, 'deadline' => '2026-11-02', 'outcome' => 'target_reached'];
        $id = $this->insert($original, $finished, '2026-10-28 20:20:00');
        $this->review($id, '2026-10-26', $finished, '2026-10-28 20:20:00');
        $late = [...$original, 'first_recorded_at' => '2026-10-28T20:30:00+00:00'];
        $this->insert($late);

        $result = (new SwingStudySource)->read(['SPY'], '2026-10-23', '2026-10-27');
        $this->assertCount(1, $result['samples']);
        $sample = $result['samples'][0];
        $this->assertNull($sample['signal_at']);
        $this->assertSame('watching', $sample['native_status']);
        $this->assertNull($sample['native_outcome']);
    }

    public function test_activation_and_publication_prevent_backdated_qualification(): void
    {
        $original = [...$this->plan(), 'activation_session' => '2026-10-27'];
        $bad = [...$original, 'status' => 'conditions_met', 'qualified_at' => '2026-10-26',
            'qualified_price' => 106.5, 'deadline' => '2026-11-02'];
        $id = $this->insert($original, $bad);
        $this->review($id, '2026-10-26', $bad, '2026-10-26 20:20:00');
        $original = [...$this->plan(), 'first_recorded_at' => '2026-10-27T20:30:00+00:00'];
        $this->insert($original, [...$bad, 'activation_session' => '2026-10-26']);

        $result = (new SwingStudySource)->read(['SPY'], '2026-10-23', '2026-10-30');
        $this->assertCount(2, $result['samples']);
        $this->assertSame([null, null], array_column($result['samples'], 'signal_at'));
        $this->assertSame([null, null], array_column($result['samples'], 'signal_price'));
        $this->assertStringContainsString('could not be verified', implode(' ', $result['warnings']));
    }

    public function test_current_state_fallback_uses_saved_time_and_warns_when_review_is_missing(): void
    {
        $original = $this->plan();
        $this->insert($original, [...$original, 'status' => 'finished', 'qualified_at' => '2026-10-26',
            'qualified_price' => 106.5, 'deadline' => '2026-11-02', 'outcome' => 'expired']);

        $result = (new SwingStudySource)->read(['SPY'], '2026-10-23', '2026-10-30');
        $this->assertSame('2026-10-26T20:00:00+00:00', $result['samples'][0]['signal_at']);
        $this->assertSame('2026-10-28T20:20:00+00:00', $result['samples'][0]['available_at']);
        $this->assertTrue($result['samples'][0]['source']['qualification']['availability_estimated']);
        $this->assertStringContainsString('no recorded review', implode(' ', $result['warnings']));
    }

    public function test_early_close_and_invalid_daily_bars_use_exchange_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 22:00:00', 'UTC'));
        $original = [...$this->plan(), 'source_date' => '2026-11-25', 'first_recorded_at' => '2026-11-25T21:30:00+00:00',
            'activation_session' => '2026-11-27'];
        $qualified = [...$original, 'status' => 'conditions_met', 'qualified_at' => '2026-11-27',
            'qualified_price' => 106.5, 'deadline' => '2026-12-04'];
        $id = $this->insert($original, $qualified, '2026-11-27 18:20:00');
        $this->review($id, '2026-11-27', $qualified, '2026-11-27 18:20:00');
        $this->price('2026-11-28', 107.);
        $this->price('2026-11-30', 107.);

        $result = (new SwingStudySource)->read(['SPY'], '2026-11-25', '2026-11-30');
        $this->assertSame('2026-11-27T18:00:00+00:00', $result['samples'][0]['signal_at']);
        $this->assertSame(['2026-11-30T21:00:00+00:00'], array_column($result['samples'][0]['bars'], 'at'));
    }

    public function test_source_window_and_plan_count_are_bounded_and_report_truncation(): void
    {
        $this->insert([...$this->plan(), 'source_date' => '2026-08-01', 'first_recorded_at' => '2026-08-01T20:30:00+00:00']);
        for ($i = 0; $i < 501; $i++) {
            $this->insert($this->plan());
        }
        $result = (new SwingStudySource)->read(['SPY'], '2026-08-01', '2026-10-30');
        $this->assertTrue($result['truncated']);
        $this->assertCount(500, $result['samples']);
        $this->assertStringContainsString('60 calendar days', implode(' ', $result['warnings']));
        $this->assertStringContainsString('500 published plans', implode(' ', $result['warnings']));
        $this->assertSame(['2026-10-23'], array_values(array_unique(array_column($result['samples'], 'session'))));
    }

    public function test_missing_storage_and_invalid_windows_return_empty_results_without_providers(): void
    {
        Schema::dropIfExists('swing_idea_reviews');
        Schema::dropIfExists('swing_ideas');
        $source = new SwingStudySource;
        $this->assertSame([], $source->read(['SPY'], '2026-10-23', '2026-10-30')['samples']);
        $this->assertNotEmpty($source->read(['SPY'], '2026-02-31', '2026-10-30')['warnings']);
        $this->assertSame([], $source->read([], '2026-10-23', '2026-10-30')['warnings']);
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    private function plan(): array
    {
        return ['symbol' => 'SPY', 'horizon' => 'short', 'source_date' => '2026-10-23',
            'rule_version' => 'swing-wall-reclaim.v1.1', 'status' => 'watching', 'direction' => 'bullish',
            'pattern' => 'wall_pullback_reclaim', 'activation_session' => '2026-10-26',
            'first_recorded_at' => '2026-10-23T20:30:00+00:00', 'source_version' => 'gex-one', 'volatility_version' => 'vol-one',
            'levels' => ['wall' => 106., 'entry_low' => 106.2, 'entry_high' => 106.9,
                'invalidation' => 104.5, 'invalidation_side' => 'below', 'target' => 111.],
            'evidence' => ['atr14' => 2., 'sma20' => 105., 'sma50' => 103., 'dex_shares' => 10000.,
                'volatility' => ['iv1m' => .2, 'rv20' => .18, 'vrp' => .02]],
            'qualified_at' => null, 'qualified_price' => null, 'deadline' => null, 'outcome' => null];
    }

    private function insert(array $original, ?array $state = null, string $updated = '2026-10-28 20:20:00'): string
    {
        $state ??= $original;
        $id = hash('sha256', (string) ++$this->sequence);
        DB::table('swing_ideas')->insert(['id' => $id, 'symbol' => $original['symbol'], 'horizon' => $original['horizon'],
            'rule_version' => $original['rule_version'], 'source_date' => $original['source_date'], 'source_hash' => str_repeat('a', 64),
            'status' => $state['status'], 'reviewed_through' => $state['qualified_at'] ?? $original['source_date'],
            'original_plan' => json_encode($original, JSON_PRESERVE_ZERO_FRACTION),
            'current_state' => json_encode($state, JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => CarbonImmutable::parse($original['first_recorded_at'])->format('Y-m-d H:i:s'), 'updated_at' => $updated]);

        return $id;
    }

    private function review(string $id, string $date, array $assessment, string $recorded): void
    {
        DB::table('swing_idea_reviews')->insert(['idea_id' => $id, 'data_date' => $date,
            'evidence_hash' => str_repeat('b', 64), 'assessment' => json_encode($assessment), 'recorded_at' => $recorded]);
    }

    private function price(string $date, float $close): void
    {
        DB::table('prices_daily')->insert(['symbol' => 'SPY', 'trade_date' => $date,
            'open' => $close - .1, 'high' => $close + 1., 'low' => $close - 1., 'close' => $close]);
    }
}
