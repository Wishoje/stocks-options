<?php

namespace App\Support;

final class ConfiguredBillingOffer
{
    /**
     * Return public plan labels for a configured Stripe price without exposing
     * the provider identifier in analytics storage.
     *
     * @return array{plan?: string, billing?: string}
     */
    public static function forPriceId(?string $priceId): array
    {
        if (! is_string($priceId) || $priceId === '') {
            return [];
        }

        foreach ((array) config('plans.plans', []) as $plan => $definition) {
            foreach ((array) data_get($definition, 'prices', []) as $billing => $configuredPriceId) {
                if (is_string($configuredPriceId) && hash_equals($configuredPriceId, $priceId)) {
                    return [
                        'plan' => (string) $plan,
                        'billing' => (string) $billing,
                    ];
                }
            }
        }

        return [];
    }
}
