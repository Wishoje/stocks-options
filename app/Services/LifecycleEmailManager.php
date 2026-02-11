<?php

namespace App\Services;

use App\Models\LifecycleEmailLog;
use App\Models\User;
use App\Notifications\LifecycleEmailNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LifecycleEmailManager
{
    public function sendOnce(User $user, string $eventKey, string $template, array $context = []): bool
    {
        try {
            return DB::transaction(function () use ($user, $eventKey, $template, $context) {
                $alreadySent = LifecycleEmailLog::query()
                    ->where('user_id', $user->id)
                    ->where('event_key', $eventKey)
                    ->exists();

                if ($alreadySent) {
                    return false;
                }

                LifecycleEmailLog::query()->create([
                    'user_id' => $user->id,
                    'event_key' => $eventKey,
                    'sent_at' => now(),
                    'context' => $context ?: null,
                ]);

                $user->notify(new LifecycleEmailNotification($template, $context));

                return true;
            }, 3);
        } catch (QueryException $e) {
            // Unique violation race: another worker already sent it.
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return false;
            }

            throw $e;
        }
    }
}

