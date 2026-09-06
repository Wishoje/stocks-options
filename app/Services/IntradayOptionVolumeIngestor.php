<?php

namespace App\Services;

use App\Models\IntradayOptionVolume;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IntradayOptionVolumeIngestor
{
    public function __construct(private readonly string $table = 'intraday_option_volumes') {}

    /** Persist bounded chunks; successful chunks survive a later chunk failure. */
    public function ingestMany(iterable $contracts, string $requestId, Carbon $capturedAt): int
    {
        $size = max(1, min(1000, (int) config('intraday_ingestion.chunk_size', 250)));
        $count = 0;
        $rows = [];
        $timestamp = now()->format('Y-m-d H:i:s');

        foreach ($contracts as $contract) {
            if (! is_array($contract)) {
                throw new InvalidArgumentException('Intraday contract must be an array.');
            }
            if (! config('intraday_ingestion.bulk_enabled', true)) {
                $this->ingest($contract, $requestId, $capturedAt);
                $count++;
                continue;
            }

            $payload = $this->normalize($contract, $requestId, $capturedAt);
            foreach (['symbol', 'contract_symbol', 'contract_type', 'expiration_date', 'strike_price'] as $required) {
                if ($payload[$required] === null || $payload[$required] === '') {
                    throw new InvalidArgumentException('Intraday contract missing '.$required.'.');
                }
            }
            // Preserve Eloquent date serialization without database reads per row.
            $row = (new IntradayOptionVolume)->fill($payload)->getAttributes();
            $row['created_at'] = $timestamp;
            $row['updated_at'] = $timestamp;
            $rows[] = $row;
            $count++;
            if (count($rows) >= $size) {
                $this->writeChunk($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->writeChunk($rows);
        }

        return $count;
    }

    private function writeChunk(array $rows): void
    {
        $updates = array_values(array_diff(array_keys($rows[0]), [
            'contract_symbol', 'captured_at', 'created_at',
        ]));
        DB::transaction(fn () => DB::table($this->table)->upsert(
            $rows, ['contract_symbol', 'captured_at'], $updates
        ), 3);
    }

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
        $payload = $this->normalize($contractData, $requestId, $capturedAt);

        return (new IntradayOptionVolume)->setTable($this->table)->newQuery()->updateOrCreate(
            ['contract_symbol' => $payload['contract_symbol'], 'captured_at' => $payload['captured_at']],
            $payload
        );
    }

    private function normalize(array $contractData, string $requestId, Carbon $capturedAt): array
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

        return $payload;
    }
}
