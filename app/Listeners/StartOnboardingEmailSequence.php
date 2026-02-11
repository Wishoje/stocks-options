<?php

namespace App\Listeners;

use App\Jobs\SendLifecycleEmailJob;
use Illuminate\Auth\Events\Registered;

class StartOnboardingEmailSequence
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user?->id) {
            return;
        }

        $anchor = $user->created_at?->timestamp ?? now()->timestamp;

        SendLifecycleEmailJob::dispatch(
            $user->id,
            "welcome_signup:{$anchor}",
            'welcome_signup'
        );

        SendLifecycleEmailJob::dispatch(
            $user->id,
            "checkout_reminder_1:{$anchor}",
            'checkout_reminder_1'
        )->delay(now()->addHour());

        SendLifecycleEmailJob::dispatch(
            $user->id,
            "checkout_reminder_2:{$anchor}",
            'checkout_reminder_2'
        )->delay(now()->addDay());
    }
}

