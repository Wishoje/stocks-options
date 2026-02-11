<?php

namespace App\Listeners;

use App\Jobs\SendLifecycleEmailJob;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

class HandleCashierWebhookLifecycleEmails
{
    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $type = data_get($payload, 'type');
        $customerId = data_get($payload, 'data.object.customer');

        if (! $type || ! $customerId) {
            return;
        }

        $user = Cashier::findBillable($customerId);

        if (! $user instanceof User) {
            return;
        }

        if ($type === 'customer.subscription.created') {
            $this->dispatchTrialStarted($user->id, (string) data_get($payload, 'data.object.id'));

            return;
        }

        if ($type === 'customer.subscription.updated') {
            if ((bool) data_get($payload, 'data.object.cancel_at_period_end')) {
                $this->dispatchCancellationConfirmation($user->id, $payload);
            }

            return;
        }

        if ($type === 'invoice.payment_failed') {
            $this->dispatchPaymentFailureSequence($user->id, $payload);

            return;
        }

        if ($type === 'customer.subscription.deleted') {
            $this->dispatchCancellationConfirmation($user->id, $payload);
            $this->dispatchWinback($user->id, (string) data_get($payload, 'data.object.id'));
        }
    }

    private function dispatchTrialStarted(int $userId, string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }

        SendLifecycleEmailJob::dispatch(
            $userId,
            "trial_started:{$subscriptionId}",
            'trial_started'
        );
    }

    private function dispatchCancellationConfirmation(int $userId, array $payload): void
    {
        $subscriptionId = (string) data_get($payload, 'data.object.id', 'unknown');
        $anchor = (string) data_get($payload, 'data.object.current_period_end', data_get($payload, 'data.object.canceled_at', now()->timestamp));

        SendLifecycleEmailJob::dispatch(
            $userId,
            "cancellation_confirmation:{$subscriptionId}:{$anchor}",
            'cancellation_confirmation'
        );
    }

    private function dispatchWinback(int $userId, string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }

        SendLifecycleEmailJob::dispatch(
            $userId,
            "cancellation_winback:{$subscriptionId}",
            'cancellation_winback'
        )->delay(now()->addDays(7));
    }

    private function dispatchPaymentFailureSequence(int $userId, array $payload): void
    {
        $invoiceId = (string) data_get($payload, 'data.object.id', Str::uuid()->toString());

        SendLifecycleEmailJob::dispatch(
            $userId,
            "payment_failed_1:{$invoiceId}",
            'payment_failed_1',
            ['invoice_id' => $invoiceId]
        );

        SendLifecycleEmailJob::dispatch(
            $userId,
            "payment_failed_2:{$invoiceId}",
            'payment_failed_2',
            ['invoice_id' => $invoiceId]
        )->delay(now()->addDay());

        SendLifecycleEmailJob::dispatch(
            $userId,
            "payment_failed_3:{$invoiceId}",
            'payment_failed_3',
            ['invoice_id' => $invoiceId]
        )->delay(now()->addDays(3));
    }
}

