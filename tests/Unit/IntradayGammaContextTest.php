<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\GammaProfileModel;
use App\Support\WallIntelligence\IntradayGammaContext;
use App\Support\WallIntelligence\IntradayWallModel;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IntradayGammaContextTest extends TestCase
{
    private function row(string $type = 'call', float $strike = 100, float $oi = 100, ?float $iv = .2, string $expiry = '2026-10-02'): array
    {
        return compact('type', 'strike', 'oi', 'iv', 'expiry');
    }

    private function context(array $contracts, string $time = '10:00:00', float $spot = 100): array
    {
        $model = new IntradayWallModel;
        $basis = $model->basis('SPY', '2026-09-30', '2026-09-29', array_values(array_unique(array_column($contracts, 'expiry'))), $contracts, '7d');
        $observation = $model->observe($basis, $spot, CarbonImmutable::parse('2026-09-30 '.$time, 'America/New_York'), 'stored_quote');

        return [(new IntradayGammaContext)->build($basis, $observation), $basis, $observation];
    }

    public function test_single_crossing_matches_analytic_solution_and_wall_exposure(): void
    {
        [$context, $basis, $observation] = $this->context([$this->row('put', 95), $this->row('call', 105)]);
        $seconds = CarbonImmutable::parse('2026-10-02 16:00', 'America/New_York')->timestamp - strtotime($observation['observed_at']);
        $expected = sqrt(95 * 105) * exp(-.5 * .2 ** 2 * $seconds / (365 * 86400));
        $this->assertSame('ready', $context['status']);
        $this->assertSame('single_crossing', $context['flip_status']);
        $this->assertEqualsWithDelta($expected, $context['gamma_flip'], .000001);
        $this->assertEqualsWithDelta($observation['net_gex'], $context['net_gex_at_spot'], .000001);
        $this->assertSame(-1, $context['nearest_crossing']['below_sign']);
        $this->assertSame(1, $context['nearest_crossing']['above_sign']);
        $this->assertSame($basis['basis_key'], $context['basis_key']);
        $this->assertSame($observation['observed_at'], $context['observed_at']);
        $this->assertSame(100.0, $context['reference_price']);
        $this->assertFalse($context['historical_outcome_eligible']);
        $this->assertArrayNotHasKey('curve', $context);
    }

    public function test_no_crossing_and_balanced_profiles_never_invent_a_flip(): void
    {
        foreach (['call' => 'positive_gamma', 'put' => 'negative_gamma'] as $type => $regime) {
            [$context] = $this->context([$this->row($type)]);
            $this->assertSame($regime, $context['regime']);
            $this->assertSame('no_crossing', $context['flip_status']);
            $this->assertNull($context['nearest_crossing']);
            $this->assertNull($context['distance_to_nearest_flip_pct']);
        }
        [$balanced] = $this->context([$this->row(), $this->row('put')]);
        $this->assertSame('balanced', $balanced['regime']);
        $this->assertSame([], $balanced['crossings']);
    }

    public function test_multiple_crossings_have_nearest_context_without_claiming_one_flip(): void
    {
        [$context] = $this->context([$this->row('put', 90), $this->row('call', 100), $this->row('put', 110)]);
        $this->assertSame('multiple_crossings', $context['flip_status']);
        $this->assertCount(2, $context['crossings']);
        $this->assertNull($context['gamma_flip']);
        $this->assertNotNull($context['nearest_crossing']);
        $this->assertGreaterThan(0, $context['distance_to_nearest_flip_pct']);
    }

    public function test_intraday_has_exact_remaining_time_even_inside_last_minute(): void
    {
        [$context, , $observation] = $this->context([$this->row(expiry: '2026-09-30')], '15:59:50');
        $this->assertSame('ready', $context['status']);
        $this->assertSame(0, $context['audit']['zero_dte_floor_rows']);
        $this->assertEqualsWithDelta($observation['net_gex'], $context['net_gex_at_spot'], .000001);
        $expired = (new GammaProfileModel)->calculateIntraday([$this->row(expiry: '2026-09-30')], 100, CarbonImmutable::parse('2026-09-30 16:00', 'America/New_York'));
        $this->assertSame('not_ready', $expired['status']);
    }

    public function test_ninety_five_percent_coverage_matches_wall_capture_policy(): void
    {
        config(['wall_tracking.minimum_oi_coverage_pct' => 95]);
        $contracts = array_fill(0, 20, $this->row(oi: 48));
        $contracts[] = $this->row('put', oi: 40, iv: null);
        [$context] = $this->context($contracts);
        $this->assertSame('ready', $context['status']);
        $this->assertEqualsWithDelta(96, $context['audit']['known_oi_coverage_pct'], .001);
        $contracts[20]['oi'] = 100;
        [$rejected] = $this->context($contracts);
        $this->assertSame('not_ready', $rejected['status']);
    }

    public function test_other_basis_and_changed_aggregate_are_rejected(): void
    {
        [, $basis, $observation] = $this->context([$this->row()]);
        $other = $observation;
        $other['basis_key'] = 'other';
        $this->assertSame('observation_basis_mismatch', (new IntradayGammaContext)->build($basis, $other)['reason']);
        $observation['net_gex'] += 100;
        $this->assertSame('observation_exposure_mismatch', (new IntradayGammaContext)->build($basis, $observation)['reason']);
    }

    public function test_contract_limit_cannot_block_the_wall_observation(): void
    {
        [$context] = $this->context(array_fill(0, 30001, $this->row()));
        $this->assertSame('not_ready', $context['status']);
        $this->assertSame('profile_scope_limit', $context['reason']);
    }
}
