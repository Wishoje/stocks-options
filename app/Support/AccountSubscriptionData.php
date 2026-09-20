<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

final class AccountSubscriptionData
{
    /**
     * Build the local billing facts shown on the account page.
     *
     * This deliberately avoids a live Stripe request. Cashier's persisted
     * subscription is the authority for access and action availability.
     *
     * @return array<string, bool|string|null>
     */
    public static function for(User $user): array
    {
        $subscriptionName = (string) config('plans.default_subscription_name', 'default');
        $subscription = $user->subscription($subscriptionName);
        $access = ProductAccess::for($user);
        $configuredOffer = ConfiguredBillingOffer::forPriceId($subscription?->stripe_price);
        $planKey = $configuredOffer['plan'] ?? null;
        $billing = $configuredOffer['billing'] ?? null;
        $onSubscriptionTrial = (bool) $access['on_trial'];
        $onGenericTrial = (bool) $access['on_generic_trial'];
        $state = $onGenericTrial ? 'generic_trial' : (string) $access['subscription_state'];

        return [
            'exists' => $subscription !== null,
            'plan_name' => self::planName($subscription, $planKey, $onGenericTrial),
            'billing_interval' => is_string($billing) ? ucfirst($billing) : null,
            'state' => $state,
            'status' => $subscription?->stripe_status,
            'status_label' => self::statusLabel($state),
            'active' => in_array($state, ['active', 'trialing', 'grace_period'], true),
            'has_access' => (bool) $access['has_access'],
            'needs_checkout' => (bool) $access['needs_checkout'],
            'on_trial' => $onSubscriptionTrial || $onGenericTrial,
            'on_generic_trial' => $onGenericTrial,
            'on_grace_period' => $state === 'grace_period',
            'trial_ends_at' => self::date($onSubscriptionTrial
                ? $subscription?->trial_ends_at
                : ($onGenericTrial ? $user->trial_ends_at : null)),
            'ends_at' => self::date($subscription?->ends_at),
            // The local subscription row does not contain the next invoice
            // date. Do not guess it from the configured cadence.
            'next_charge_at' => null,
            'can_open_portal' => $user->hasStripeId(),
            'can_cancel' => in_array($state, ['active', 'trialing'], true),
            'can_resume' => $state === 'grace_period',
        ];
    }

    private static function statusLabel(string $state): string
    {
        return match ($state) {
            'active' => 'Active',
            'trialing' => 'Trial active',
            'generic_trial' => 'Account trial',
            'grace_period' => 'Cancels at period end',
            'past_due' => 'Payment past due',
            'incomplete' => 'Setup incomplete',
            'paused' => 'Paused',
            'unpaid' => 'Unpaid',
            'incomplete_expired' => 'Setup expired',
            'canceled' => 'Canceled; end pending',
            'ended' => 'Ended',
            'status_conflict' => 'Provider status conflict',
            'unknown' => 'Provider status unknown',
            default => 'No subscription',
        };
    }

    private static function planName(
        ?Subscription $subscription,
        ?string $planKey,
        bool $onGenericTrial,
    ): ?string {
        if ($planKey !== null) {
            return (string) config("plans.plans.$planKey.label", 'Subscription');
        }

        if ($subscription) {
            return 'Subscription';
        }

        return $onGenericTrial ? 'Account trial' : null;
    }

    private static function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
