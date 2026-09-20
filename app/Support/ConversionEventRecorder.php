<?php

namespace App\Support;

use App\Models\ConversionEvent;
use Carbon\CarbonInterface;

final class ConversionEventRecorder
{
    private const ALLOWED_PROPERTIES = [
        'billing',
        'currency',
        'method',
        'plan',
        'state',
        'surface',
    ];

    /**
     * Persist the first confirmed occurrence for an account and event type.
     * Provider references are hashed so Stripe identifiers never enter reports.
     */
    public function recordFirst(
        int $userId,
        string $eventType,
        string $providerReference,
        string $authority,
        CarbonInterface $occurredAt,
        array $properties = [],
    ): ConversionEvent {
        $safeProperties = collect($properties)
            ->only(self::ALLOWED_PROPERTIES)
            ->filter(fn ($value) => is_string($value) && preg_match('/^[a-z0-9_.:-]{1,80}$/i', $value) === 1)
            ->all();

        return ConversionEvent::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'event_type' => $eventType,
            ],
            [
                'event_key' => hash_hmac(
                    'sha256',
                    implode('|', [$eventType, $userId, $providerReference]),
                    (string) config('app.key'),
                ),
                'authority' => $authority,
                'occurred_at' => $occurredAt,
                'properties' => $safeProperties ?: null,
            ],
        );
    }
}
