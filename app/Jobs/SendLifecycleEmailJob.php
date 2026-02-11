<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LifecycleEmailManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendLifecycleEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $eventKey,
        public string $template,
        public array $context = [],
    ) {
    }

    public function handle(LifecycleEmailManager $emailManager): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        if (! $this->shouldSend($user)) {
            return;
        }

        $emailManager->sendOnce($user, $this->eventKey, $this->template, $this->context);
    }

    private function shouldSend(User $user): bool
    {
        $subName = config('plans.default_subscription_name');
        $sub = $user->subscription($subName);

        return match ($this->template) {
            'welcome_signup' => true,
            'checkout_reminder_1', 'checkout_reminder_2' => !($user->subscribed($subName) || $user->onTrial($subName)),
            'trial_started' => $user->onTrial($subName),
            'payment_failed_1', 'payment_failed_2', 'payment_failed_3' => $this->hasPaymentIssue($sub?->stripe_status),
            'cancellation_confirmation' => (bool) $sub?->onGracePeriod(),
            'cancellation_winback' => $sub
                && $sub->ended()
                && $sub->ends_at
                && $sub->ends_at->lte(now()->subDays(7))
                && !($user->subscribed($subName) || $user->onTrial($subName)),
            default => true,
        };
    }

    private function hasPaymentIssue(?string $stripeStatus): bool
    {
        return in_array((string) $stripeStatus, ['past_due', 'unpaid', 'incomplete'], true);
    }
}
