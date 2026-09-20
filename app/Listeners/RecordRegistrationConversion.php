<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\ConversionEventRecorder;
use App\Support\ConversionMeasurementWindow;
use Illuminate\Auth\Events\Registered;

final class RecordRegistrationConversion
{
    public function __construct(private readonly ConversionEventRecorder $events) {}

    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $occurredAt = $event->user->created_at ?? now('UTC');
        if (! ConversionMeasurementWindow::includes($occurredAt)) {
            return;
        }

        $this->events->recordFirst(
            userId: $event->user->id,
            eventType: 'registration_confirmed',
            providerReference: 'user:'.$event->user->id,
            authority: 'fortify',
            occurredAt: $occurredAt,
            properties: ['method' => 'email'],
        );
    }
}
