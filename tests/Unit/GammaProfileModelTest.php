<?php

namespace Tests\Unit;

use App\Support\WallIntelligence\GammaProfileModel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GammaProfileModelTest extends TestCase
{
    private function row(string $type = 'call', float $strike = 100, ?float $iv = .2, float $oi = 100, string $expiry = '2026-10-30'): array
    {
        return compact('type', 'strike', 'iv', 'oi', 'expiry');
    }

    private function runModel(array $rows): array
    {
        return (new GammaProfileModel)->calculate($rows, 100, CarbonImmutable::parse('2026-09-30T20:00:00Z'));
    }

    public function test_gamma_matches_closed_form_at_one_year_and_the_reference_is_on_the_curve(): void
    {
        $result = (new GammaProfileModel)->calculate([$this->row(expiry: '2027-10-01')], 100, CarbonImmutable::parse('2026-10-01T20:00:00Z'));
        // N'(0.1)/(100 * 0.2) * 100 OI * 100 shares * 100^2 * .01.
        $this->assertEqualsWithDelta(19847.62737385059, $result['net_gex_at_spot'], 1e-8);
        $this->assertSame('positive_gamma', $result['regime']);
        $this->assertSame('no_crossing', $result['flip_status']);
        $this->assertNull($result['gamma_flip']);
        $reference = array_values(array_filter($result['curve'], fn ($p) => $p['price'] === 100.0));
        $this->assertCount(1, $reference);
        $this->assertSame($result['net_gex_at_spot'], $reference[0]['net_gex']);
    }

    public function test_single_crossing_matches_analytic_equal_iv_two_strike_solution(): void
    {
        $r = $this->runModel([$this->row('put', 95), $this->row('call', 105)]);
        $expected = sqrt(95 * 105) * exp(-.5 * .2 ** 2 * 30 / 365);
        $this->assertSame('single_crossing', $r['flip_status']);
        $this->assertEqualsWithDelta($expected, $r['gamma_flip'], 1e-6);
        $this->assertSame(-1, $r['crossings'][0]['below_sign']);
        $this->assertSame(1, $r['crossings'][0]['above_sign']);
        $this->assertSame('positive_gamma', $r['regime']);
        $this->assertEqualsWithDelta(100 - $expected, $r['distance_to_flip_pct'], 1e-6);
    }

    public function test_multiple_crossings_are_preserved_and_not_collapsed_into_one_flip(): void
    {
        $r = $this->runModel([$this->row('put', 95, .1), $this->row('call', 100, .1, 180), $this->row('put', 105, .1)]);
        $this->assertSame('multiple_crossings', $r['flip_status']);
        $this->assertCount(2, $r['crossings']);
        $this->assertNull($r['gamma_flip']);
        $this->assertNull($r['distance_to_flip_pct']);
        $this->assertLessThan(100, $r['crossings'][0]['price']);
        $this->assertGreaterThan(100, $r['crossings'][1]['price']);
        $this->assertSame([1, -1], array_column($r['crossings'], 'above_sign'));
    }

    public function test_balanced_and_negative_profiles_do_not_invent_crossings(): void
    {
        $r = $this->runModel([$this->row(), $this->row('put')]);
        $this->assertSame('balanced', $r['regime']);
        $this->assertSame(0.0, $r['net_gex_at_spot']);
        $this->assertSame([], $r['crossings']);
        $r = $this->runModel([$this->row('put')]);
        $this->assertSame('negative_gamma', $r['regime']);
        $this->assertNull($r['gamma_flip']);
    }

    public function test_zero_dte_floor_is_explicit_finite_and_the_inputs_are_gated(): void
    {
        $r = $this->runModel([$this->row(expiry: '2026-09-30')]);
        $this->assertSame(1, $r['audit']['zero_dte_floor_rows']);
        $this->assertTrue(is_finite($r['net_gex_at_spot']));
        $this->assertGreaterThan(401, count($r['curve']));
        $bad = $this->runModel([$this->row(iv: null)]);
        $this->assertSame('not_ready', $bad['status']);
        $this->assertSame([], $bad['curve']);
        $this->assertNull($bad['gamma_flip']);
        $zero = $this->runModel([$this->row(oi: 0)]);
        $this->assertSame('not_ready', $zero['status']);
    }

    public function test_small_input_gaps_do_not_claim_gex_coverage_and_expired_contracts_cannot_enter(): void
    {
        $rows = array_fill(0, 20, $this->row());
        $rows[] = $this->row(iv: null, oi: 1);
        $r = $this->runModel($rows);
        $this->assertSame('ready', $r['status']);
        $this->assertSame(1, $r['audit']['excluded_rows']);
        $this->assertGreaterThan(99, $r['audit']['known_oi_coverage_pct']);
        $expired = $this->runModel([$this->row(expiry: '2026-09-29')]);
        $this->assertSame('not_ready', $expired['status']);
    }

    public function test_extreme_numeric_inputs_cannot_produce_an_unserializable_profile(): void
    {
        foreach ([1e-320, 1e308] as $iv) {
            $r = $this->runModel([$this->row(iv: $iv)]);
            $this->assertSame('not_ready', $r['status']);
            $this->assertIsString(json_encode($r, JSON_THROW_ON_ERROR));
        }
    }
}
