<?php

namespace App\Support;

final class StripePlanConfigurationVerifier
{
    /**
     * @return list<string>
     */
    public static function cadenceFindings(array $plan): array
    {
        $priceKeys = array_keys((array) data_get($plan, 'prices', []));
        $displayKeys = array_keys(array_filter(
            (array) data_get($plan, 'display', []),
            fn ($value): bool => is_array($value),
        ));
        sort($priceKeys);
        sort($displayKeys);

        if ($priceKeys === []) {
            return ['Plan has no configured billing cadences.'];
        }

        return $priceKeys === $displayKeys
            ? []
            : ['Stripe price cadences and public display cadences do not match exactly.'];
    }

    /**
     * @return list<string>
     */
    public static function findings(array $plan, string $billing, array $price, bool $expectLive): array
    {
        $display = (array) data_get($plan, "display.$billing", []);
        $currency = strtolower((string) data_get($plan, 'display.currency', ''));
        $expectedAmount = data_get($display, 'amount_minor');
        $expectedInterval = (string) data_get($display, 'interval', '');
        $findings = [];

        if (data_get($price, 'active') !== true) {
            $findings[] = 'Stripe Price is not active.';
        }
        if ((bool) data_get($price, 'livemode', false) !== $expectLive) {
            $findings[] = 'Stripe Price mode does not match the requested preflight mode.';
        }
        if ($currency === '' || strtolower((string) data_get($price, 'currency', '')) !== $currency) {
            $findings[] = 'Displayed currency does not match Stripe.';
        }
        if (! is_int($expectedAmount) || data_get($price, 'unit_amount') !== $expectedAmount) {
            $findings[] = 'Displayed amount does not match Stripe unit_amount.';
        }
        if (data_get($price, 'type') !== 'recurring') {
            $findings[] = 'Stripe Price is not recurring.';
        }
        if ($expectedInterval === '' || data_get($price, 'recurring.interval') !== $expectedInterval) {
            $findings[] = 'Displayed billing interval does not match Stripe.';
        }
        if ((int) data_get($price, 'recurring.interval_count', 0) !== 1) {
            $findings[] = 'Stripe recurring interval_count must be 1.';
        }
        if ((int) data_get($plan, 'trial_days', 0) < 1) {
            $findings[] = 'Configured trial_days must be a positive integer.';
        }

        return $findings;
    }
}
