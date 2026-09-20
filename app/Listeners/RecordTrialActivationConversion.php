<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\ConfiguredBillingOffer;
use App\Support\ConversionEventRecorder;
use App\Support\ConversionMeasurementWindow;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

final class RecordTrialActivationConversion
{
    public function __construct(private readonly ConversionEventRecorder $events) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        if ((string) data_get($payload, 'type', '') !== 'customer.subscription.created') {
            return;
        }

        $customerId = (string) data_get($payload, 'data.object.customer', '');
        $providerEventId = (string) data_get($payload, 'id', '');
        $subscriptionId = (string) data_get($payload, 'data.object.id', '');
        if ($customerId === '' || $providerEventId === '' || $subscriptionId === '') {
            return;
        }

        $user = Cashier::findBillable($customerId);
        if (! $user instanceof User) {
            return;
        }

        $occurredAt = CarbonImmutable::createFromTimestampUTC(
            max(1, (int) data_get($payload, 'created', now()->timestamp)),
        );
        if (! ConversionMeasurementWindow::includes($occurredAt)) {
            return;
        }

        $payloadStatus = (string) data_get($payload, 'data.object.status', '');
        $payloadTrialEnd = (int) data_get($payload, 'data.object.trial_end', 0);
        if (! in_array($payloadStatus, ['trialing', 'active'], true) || $payloadTrialEnd <= $occurredAt->timestamp) {
            return;
        }

        $subscription = $user->subscriptions()->where('stripe_id', $subscriptionId)->first();
        if (
            ! $subscription
            || ! in_array((string) $subscription->stripe_status, ['trialing', 'active'], true)
            || ! $subscription->trial_ends_at
            || $subscription->trial_ends_at->timestamp <= $occurredAt->timestamp
        ) {
            return;
        }

        $this->events->recordFirst(
            userId: $user->id,
            eventType: 'trial_activation_confirmed',
            providerReference: $providerEventId,
            authority: 'stripe_webhook_after_cashier',
            occurredAt: $occurredAt,
            properties: [
                'state' => 'trial',
                ...ConfiguredBillingOffer::forPriceId($subscription->stripe_price),
            ],
        );
    }
}
