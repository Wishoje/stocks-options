<?php

namespace App\Support;

use App\Models\User;
use Laravel\Cashier\Subscription;

final class ProductAccess
{
    /**
     * Return the shared access facts used by web navigation and route gates.
     * Generic trials grant product access but still need a paid checkout.
     *
     * Cashier's valid()/subscribed() helpers deliberately treat several
     * provider states as active. Product routes need a narrower, fail-closed
     * decision, so every caller uses the explicit state below instead.
     *
     * @return array{has_access: bool, subscribed: bool, on_trial: bool, on_generic_trial: bool, needs_checkout: bool, subscription_state: string}
     */
    public static function for(?User $user): array
    {
        if (! $user) {
            return [
                'has_access' => false,
                'subscribed' => false,
                'on_trial' => false,
                'on_generic_trial' => false,
                'needs_checkout' => false,
                'subscription_state' => 'none',
            ];
        }

        $subscriptionName = (string) config('plans.default_subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);
        $subscriptionState = self::subscriptionState($subscription);
        $subscriptionHasAccess = in_array(
            $subscriptionState,
            ['active', 'trialing', 'grace_period'],
            true,
        );

        // A generic trial is the pre-subscription access path. Once a local
        // provider row exists, its explicit state is authoritative so an
        // adverse subscription cannot fall through to a stale generic trial.
        $onGenericTrial = $subscription === null && $user->onGenericTrial();

        return [
            'has_access' => $subscriptionHasAccess || $onGenericTrial,
            'subscribed' => $subscriptionHasAccess,
            'on_trial' => $subscriptionState === 'trialing',
            'on_generic_trial' => $onGenericTrial,
            'needs_checkout' => ! $subscriptionHasAccess,
            'subscription_state' => $subscriptionState,
        ];
    }

    public static function subscriptionState(?Subscription $subscription): string
    {
        if (! $subscription) {
            return 'none';
        }

        $providerStatus = strtolower(trim((string) $subscription->stripe_status));

        return match (true) {
            $subscription->onGracePeriod()
                && in_array($providerStatus, ['active', 'trialing', 'canceled'], true) => 'grace_period',
            $subscription->onGracePeriod() => 'status_conflict',
            $subscription->ended()
                && in_array($providerStatus, ['canceled', 'incomplete_expired'], true) => 'ended',
            $subscription->ended() => 'status_conflict',
            default => match ($providerStatus) {
                'active' => $subscription->onTrial() ? 'trialing' : 'active',
                'trialing' => 'trialing',
                'past_due' => 'past_due',
                'incomplete' => 'incomplete',
                'paused' => 'paused',
                'unpaid' => 'unpaid',
                'incomplete_expired' => 'incomplete_expired',
                'canceled' => 'canceled',
                default => 'unknown',
            },
        };
    }
}
