<?php

namespace App\Listeners;

use App\Models\ConversionEvent;
use App\Models\User;
use App\Support\ConfiguredBillingOffer;
use App\Support\ConversionEventRecorder;
use App\Support\ConversionMeasurementWindow;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

final class RecordBillingConversionEvents
{
    public function __construct(private readonly ConversionEventRecorder $events) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = (string) data_get($payload, 'type', '');
        $customerId = (string) data_get($payload, 'data.object.customer', '');
        $providerEventId = (string) data_get($payload, 'id', '');

        if ($type !== 'invoice.payment_succeeded' || $customerId === '' || $providerEventId === '') {
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

        $paid = (int) data_get($payload, 'data.object.amount_paid', 0);
        $status = (string) data_get($payload, 'data.object.status', '');
        $subscriptionId = (string) (
            data_get($payload, 'data.object.subscription')
            ?: data_get($payload, 'data.object.parent.subscription_details.subscription', '')
        );
        if ($paid <= 0 || $status !== 'paid' || $subscriptionId === '') {
            return;
        }

        $subscription = $user->subscriptions()
            ->where('stripe_id', $subscriptionId)
            ->first();
        if (! $subscription) {
            return;
        }

        $qualifyingTrial = ConversionEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'trial_activation_confirmed')
            ->exists();
        $createdInCohort = $subscription->created_at
            && ConversionMeasurementWindow::includes($subscription->created_at);
        if (! $qualifyingTrial && ! $createdInCohort) {
            return;
        }

        $offer = ConfiguredBillingOffer::forPriceId($subscription->stripe_price);

        $this->events->recordFirst(
            userId: $user->id,
            eventType: 'paid_activation_confirmed',
            providerReference: $providerEventId,
            authority: 'stripe_webhook',
            occurredAt: $occurredAt,
            properties: [
                'state' => 'paid',
                'currency' => strtolower((string) data_get($payload, 'data.object.currency', 'usd')),
                ...$offer,
            ],
        );
    }
}
