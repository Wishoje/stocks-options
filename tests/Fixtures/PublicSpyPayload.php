<?php

namespace Tests\Fixtures;

final class PublicSpyPayload
{
    public static function make(): array
    {
        return [
            'symbol' => 'SPY', 'timeframe' => '14d', 'data_date' => '2026-10-23',
            'view_context' => ['view' => 'latest_eod', 'source_date' => '2026-10-23'],
            'expiration_dates' => ['2026-10-23', '2026-10-26', '2026-10-30'],
            'strike_data' => [
                ['strike' => 595, 'net_gex' => -10000000000., 'call_gex' => 5, 'put_gex' => 9],
                ['strike' => 600, 'net_gex' => -2000000000.],
                ['strike' => 605, 'net_gex' => 5000000000.],
            ],
            'social_quality' => ['publishable' => true, 'source_dates' => ['2026-10-23'], 'missing_expiration_count' => 0],
            'run' => ['user_id' => 123, 'internal_trace' => 'DO-NOT-EXPOSE'],
            'bootstrap' => ['private' => 'DO-NOT-EXPOSE'], 'total_open_interest' => 123456,
        ];
    }
}
