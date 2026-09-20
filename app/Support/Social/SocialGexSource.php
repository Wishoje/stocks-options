<?php

namespace App\Support\Social;

use App\Http\Controllers\GexController;
use App\Support\EodViewContext;
use DomainException;
use Illuminate\Http\Request;

class SocialGexSource
{
    public static function expectedDate(string $session): string
    {
        return EodViewContext::previousSession($session);
    }

    public function capture(string $symbol, string $session): array
    {
        // Consume the same published response, expiry scope, and quality facts as the dashboard.
        $response = app(GexController::class)->getGexLevels(Request::create('/api/gex-levels', 'GET', [
            'symbol' => $symbol, 'timeframe' => '14d', 'view' => 'next_session', 'session_date' => $session,
        ]));
        $payload = $response->getData(true);
        $expected = self::expectedDate($session);
        if ($response->getStatusCode() !== 200 || empty($payload['strike_data'])
            || ($payload['data_date'] ?? null) !== $expected
            || ($payload['social_quality']['missing_expiration_count'] ?? 0) > 0
            || ($payload['social_quality']['source_dates'] ?? []) !== [$expected]) {
            throw new DomainException("A complete {$expected} snapshot is required for every included expiry. Regenerate after the EOD data is ready.");
        }
        foreach ($payload['strike_data'] as $row) {
            foreach (['strike', 'net_gex'] as $field) {
                if (! is_numeric($row[$field] ?? null) || ! is_finite((float) $row[$field])) {
                    throw new DomainException('The snapshot contains incomplete chart readings.');
                }
            }
        }

        return $payload;
    }
}
