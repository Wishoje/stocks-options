<?php

namespace App\Console\Commands;

use App\Jobs\SendLifecycleEmailJob;
use App\Models\User;
use Illuminate\Console\Command;

class RunLifecycleEmailAutomation extends Command
{
    protected $signature = 'emails:lifecycle-run';

    protected $description = 'Dispatch lifecycle email checks (cancellation confirmation and winback).';

    public function handle(): int
    {
        $subName = config('plans.default_subscription_name');
        $processed = 0;

        User::query()
            ->whereNotNull('stripe_id')
            ->cursor()
            ->each(function (User $user) use ($subName, &$processed) {
                $sub = $user->subscription($subName);

                if (! $sub) {
                    return;
                }

                if ($sub->onGracePeriod()) {
                    $anchor = $sub->ends_at?->timestamp ?? now()->timestamp;
                    SendLifecycleEmailJob::dispatch(
                        $user->id,
                        "cancellation_confirmation:{$sub->id}:{$anchor}",
                        'cancellation_confirmation'
                    );
                }

                if (
                    $sub->ended()
                    && $sub->ends_at
                    && $sub->ends_at->lte(now()->subDays(7))
                    && !($user->subscribed($subName) || $user->onTrial($subName))
                ) {
                    SendLifecycleEmailJob::dispatch(
                        $user->id,
                        "cancellation_winback:{$sub->id}:{$sub->ends_at->timestamp}",
                        'cancellation_winback'
                    );
                }

                $processed++;
            });

        $this->info("Lifecycle email scan dispatched for {$processed} users.");

        return self::SUCCESS;
    }
}
