<?php

namespace App\Support\WallIntelligence;

use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WallIvReference
{
    /** Keep OI unchanged. Only use recorded IV for the exact expiry, strike and side. */
    public function resolve(array $contracts, array $expirationIds, string $sourceDate): array
    {
        $cutoff = CarbonImmutable::parse($sourceDate, 'America/New_York')->endOfDay()->utc();
        $needsReference = [];
        foreach ($contracts as $index => &$contract) {
            $iv = WallSnapshotContract::number($contract['iv'] ?? null);
            $writtenAt = $contract['data_timestamp'] ?? null;
            if ($iv !== null && $iv > 0 && (! $writtenAt || CarbonImmutable::parse($writtenAt, 'UTC')->lessThanOrEqualTo($cutoff))) {
                $contract['iv_data_date'] = $sourceDate;
            } elseif (($contract['oi'] ?? 0) > 0) {
                // A current-session repair of an old chain date is not prior-session IV.
                $contract['iv'] = null;
                $needsReference[$this->key($contract)] = $index;
            }
        }
        unset($contract);
        if (! $needsReference) {
            return $contracts;
        }

        $from = CarbonImmutable::parse($sourceDate, 'America/New_York');
        // At most three completed trading sessions, including the OI date.
        for ($i = 0; $i < 2; $i++) {
            $from = CarbonImmutable::parse(MarketSession::tradingDateOnOrBefore($from->subDay()), 'America/New_York');
        }
        $rows = DB::table('option_chain_data as o')->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereIn('o.expiration_id', $expirationIds)->whereBetween('o.data_date', [$from->toDateString(), $sourceDate])
            ->where('o.iv', '>', 0)->where('o.data_timestamp', '<=', $cutoff)
            ->orderByDesc('o.data_date')->orderByDesc('o.data_timestamp')->limit(30001)
            ->get(['e.expiration_date as expiry', 'o.option_type as type', 'o.strike', 'o.iv', 'o.data_date', 'o.data_timestamp']);
        if ($rows->count() > 30000) {
            return $contracts;
        }
        foreach ($rows as $row) {
            $key = $this->key((array) $row);
            if (! isset($needsReference[$key])) {
                continue;
            }
            $iv = WallSnapshotContract::number($row->iv);
            if ($iv === null || $iv <= 0) {
                continue;
            }
            $index = $needsReference[$key];
            $contracts[$index]['iv'] = $iv;
            $contracts[$index]['iv_data_date'] = (string) $row->data_date;
            $contracts[$index]['iv_recorded_at'] = (string) $row->data_timestamp;
            unset($needsReference[$key]);
            if (! $needsReference) {
                break;
            }
        }

        return $contracts;
    }

    private function key(array $contract): string
    {
        return $contract['expiry'].'|'.$contract['type'].'|'.(float) $contract['strike'];
    }
}
