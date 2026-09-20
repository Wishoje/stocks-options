<?php

namespace Tests\Unit;

use App\Support\StripePlanConfigurationVerifier;
use PHPUnit\Framework\TestCase;

class StripePlanConfigurationVerifierTest extends TestCase
{
    public function test_matching_recurring_price_has_no_findings(): void
    {
        $this->assertSame([], StripePlanConfigurationVerifier::cadenceFindings($this->plan()));
        $this->assertSame([], StripePlanConfigurationVerifier::findings(
            $this->plan(),
            'monthly',
            $this->price(),
            false,
        ));
    }

    public function test_missing_or_extra_public_cadence_fails_configuration_parity(): void
    {
        $missing = $this->plan();
        $missing['prices'] = [];
        $this->assertNotEmpty(StripePlanConfigurationVerifier::cadenceFindings($missing));

        $extra = $this->plan();
        $extra['display']['yearly'] = ['amount_minor' => 29900, 'interval' => 'year'];
        $this->assertNotEmpty(StripePlanConfigurationVerifier::cadenceFindings($extra));
    }

    public function test_mode_amount_currency_and_interval_drift_are_reported(): void
    {
        $price = $this->price();
        $price['livemode'] = true;
        $price['currency'] = 'eur';
        $price['unit_amount'] = 3000;
        $price['recurring']['interval'] = 'year';

        $findings = StripePlanConfigurationVerifier::findings($this->plan(), 'monthly', $price, false);

        $this->assertContains('Stripe Price mode does not match the requested preflight mode.', $findings);
        $this->assertContains('Displayed currency does not match Stripe.', $findings);
        $this->assertContains('Displayed amount does not match Stripe unit_amount.', $findings);
        $this->assertContains('Displayed billing interval does not match Stripe.', $findings);
    }

    private function plan(): array
    {
        return [
            'trial_days' => 7,
            'prices' => ['monthly' => 'price_test'],
            'display' => [
                'currency' => 'USD',
                'monthly' => [
                    'amount_minor' => 2999,
                    'interval' => 'month',
                ],
            ],
        ];
    }

    private function price(): array
    {
        return [
            'active' => true,
            'livemode' => false,
            'currency' => 'usd',
            'unit_amount' => 2999,
            'type' => 'recurring',
            'recurring' => [
                'interval' => 'month',
                'interval_count' => 1,
            ],
        ];
    }
}
