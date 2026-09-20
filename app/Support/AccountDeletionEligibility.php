<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Laravel\Cashier\Subscription;

final class AccountDeletionEligibility
{
    private const TERMINAL_PROVIDER_STATUSES = [
        'canceled',
        'incomplete_expired',
    ];

    /**
     * Determine eligibility from the subscription rows already locked by the caller.
     *
     * @param  iterable<int, Subscription>  $subscriptions
     */
    public static function isBlocked(iterable $subscriptions, ?CarbonInterface $at = null): bool
    {
        $at ??= now();

        foreach ($subscriptions as $subscription) {
            if (! in_array($subscription->stripe_status, self::TERMINAL_PROVIDER_STATUSES, true)) {
                return true;
            }

            if ($subscription->ends_at === null || $subscription->ends_at->isAfter($at)) {
                return true;
            }
        }

        return false;
    }

    public static function validationMessage(): string
    {
        return 'A billing record attached to this account has not reached a verified terminal state. Resolve it in Billing and wait for the saved end date before deleting your account.';
    }
}
