<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\IntradayWallModel;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\TestCase;

class IntradayWallModelTest extends TestCase
{
    private function contracts(): array
    {
        return [
            ['expiry' => '2026-10-02', 'strike' => 100., 'type' => 'call', 'oi' => 200., 'iv' => .2],
            ['expiry' => '2026-10-02', 'strike' => 95., 'type' => 'put', 'oi' => 400., 'iv' => .2],
        ];
    }

    private function observation(string $time = '10:00', ?array $contracts = null): array
    {
        $model = new IntradayWallModel;
        $basis = $model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-02'], $contracts ?? $this->contracts());

        return $model->observe($basis, 100., CarbonImmutable::parse('2026-09-30 '.$time, 'America/New_York'), 'provider');
    }

    public function test_each_expiry_uses_its_own_time_and_exposure_is_usd_per_one_percent(): void
    {
        $model = new IntradayWallModel;
        $rows = [$this->contracts()[0]];
        $observed = $this->observation(contracts: $rows);
        $seconds = CarbonImmutable::parse('2026-10-02 16:00', 'America/New_York')->timestamp - CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York')->timestamp;
        $v = .2 * sqrt($seconds / (365 * 86400));
        $gamma = exp(-.5 * (.5 * $v) ** 2) / (sqrt(2 * M_PI) * 100 * $v);
        $this->assertEqualsWithDelta($gamma * 200 * 10000, $observed['net_gex'], .000001);
        $rows[0]['expiry'] = '2026-10-09';
        $long = $model->observe($model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-09'], $rows), 100., CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York'), 'provider');
        $this->assertLessThan($observed['net_gex'], $long['net_gex']);
        $this->assertNull($observed['provenance']['greeks_source_at']);
        $this->assertSame('2026-09-29', $observed['provenance']['oi_date']);
        $this->assertFalse($observed['audit']['historical_outcome_eligible']);
    }

    public function test_invalid_iv_is_not_replaced_and_zero_oi_remains_a_real_zero(): void
    {
        $rows = $this->contracts();
        $rows[0]['iv'] = null;
        $rows[] = ['expiry' => '2026-10-02', 'strike' => 105., 'type' => 'call', 'oi' => 0., 'iv' => null];
        $result = $this->observation(contracts: $rows);
        $this->assertSame(1, $result['audit']['excluded_rows']);
        $this->assertSame([], $result['walls']['call']);
        $this->assertEquals(0, $result['strike_data'][1]['net_gex']);
        $this->assertEqualsWithDelta(66.666667, $result['audit']['oi_input_coverage_pct'], .00001);
    }

    public function test_holiday_and_invalid_spot_are_rejected(): void
    {
        $model = new IntradayWallModel;
        $basis = $model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-02'], $this->contracts());
        foreach ([0., -1., INF, NAN] as $spot) {
            try {
                $model->observe($basis, $spot, CarbonImmutable::parse('2026-09-30 10:00', 'America/New_York'), 'provider');
                $this->fail('Invalid spot accepted');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DomainException::class);
        $model->observe($basis, 100, CarbonImmutable::parse('2026-12-25 10:00', 'America/New_York'), 'provider');
    }

    public function test_five_minute_observations_measure_migration_but_one_reading_is_null(): void
    {
        $first = $this->observation();
        $last = $this->observation('10:05');
        $last['walls']['call'][0]['strike'] = 105.;
        $model = new IntradayWallModel;
        $this->assertNull($model->timeline([$first])[0]['migration']['put']['amount']);
        $segment = $model->timeline([$last, $first])[0];
        $this->assertEquals(0, $segment['migration']['put']['amount']);
        $this->assertEquals(5, $segment['migration']['call']['amount']);
        $this->assertSame('up', $segment['migration']['call']['direction']);
        $this->assertSame(2, $segment['migration']['put']['comparable_readings']);
    }

    public function test_scope_input_source_revisions_and_long_gaps_start_separate_comparisons(): void
    {
        $first = $this->observation();
        $last = $this->observation('10:05');
        $model = new IntradayWallModel;
        foreach (['scope_key' => 'scope_changed', 'basis_key' => 'inputs_changed', 'observed_at' => 'observation_gap'] as $field => $reason) {
            $changed = $last;
            $changed[$field] = $field === 'observed_at' ? '2026-09-30T15:00:00+00:00' : 'changed';
            $segments = $model->timeline([$first, $changed]);
            $this->assertCount(2, $segments);
            $this->assertSame($reason, $segments[1]['start_reason']);
            $this->assertNull($segments[1]['migration']['put']['amount']);
        }
        $changed = $last;
        $changed['provenance']['quote_source'] = 'another';
        $this->assertSame('quote_source_changed', $model->timeline([$first, $changed])[1]['start_reason']);
    }

    public function test_absent_side_interrupts_that_sides_migration(): void
    {
        $first = $this->observation();
        $middle = $this->observation('10:05');
        $last = $this->observation('10:10');
        $middle['walls']['put'] = [];
        $segment = (new IntradayWallModel)->timeline([$first, $middle, $last])[0];
        $this->assertNull($segment['migration']['put']['amount']);
        $this->assertEquals(0, $segment['migration']['call']['amount']);
    }

    public function test_input_order_is_canonical_and_changed_oi_creates_a_new_basis(): void
    {
        $model = new IntradayWallModel;
        $rows = $this->contracts();
        $a = $model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-02'], $rows);
        $b = $model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-02'], array_reverse($rows));
        $this->assertSame($a, $b);
        $rows[0]['oi']++;
        $c = $model->basis('SPY', '2026-09-30', '2026-09-29', ['2026-10-02'], $rows);
        $this->assertSame($a['scope_key'], $c['scope_key']);
        $this->assertNotSame($a['basis_key'], $c['basis_key']);
    }
}
