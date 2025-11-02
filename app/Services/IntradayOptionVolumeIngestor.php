<?php

namespace App\Services;

use App\Models\IntradayOptionVolume;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class IntradayOptionVolumeIngestor
{
    /**
     * Persist one option contract snapshot row.
     *
     * @param  array   $contractData  one element from the API "results"
     * @param  string  $requestId     polygon response request_id
     * @param  Carbon  $capturedAt    when this snapshot was captured
     *
     * @return IntradayOptionVolume
     */
    public function ingest(array $contractData, string $requestId, Carbon $capturedAt): IntradayOptionVolume
    {
        $details = Arr::get($contractData, 'details', []);
        $day     = Arr::get($contractData, 'day', []);
        $greeks  = Arr::get($contractData, 'greeks', []);

        $symbol = Arr::get($contractData, 'underlying_asset.ticker'); // "SPY"

        $payload = [
            'symbol'            => $symbol,
            'contract_symbol'   => Arr::get($details, 'ticker'),
            'contract_type'     => Arr::get($details, 'contract_type'), // call/put
            'expiration_date'   => Arr::get($details, 'expiration_date'),
            'strike_price'      => Arr::get($details, 'strike_price'),

            'volume'            => Arr::get($day, 'volume'),
            'open_interest'     => Arr::get($contractData, 'open_interest'),

            'implied_volatility'=> Arr::get($contractData, 'implied_volatility'),

            'delta'             => Arr::get($greeks, 'delta'),
            'gamma'             => Arr::get($greeks, 'gamma'),
            'theta'             => Arr::get($greeks, 'theta'),
            'vega'              => Arr::get($greeks, 'vega'),

            'last_price'        => Arr::get($day, 'close'),
            'change'            => Arr::get($day, 'change'),
            'change_percent'    => Arr::get($day, 'change_percent'),

            'request_id'        => $requestId,
            'captured_at'       => $capturedAt,
        ];

        // upsert by (contract_symbol, captured_at) so reruns don't spam dupes
        return IntradayOptionVolume::updateOrCreate(
            [
                'contract_symbol' => $payload['contract_symbol'],
                'captured_at'     => $payload['captured_at'],
            ],
            $payload
        );
    }
}
