<?php

namespace App\Support\Social;

use App\Http\Controllers\GexController;
use App\Support\EodSnapshotSelector;
use App\Support\GexExpirationUniverse;
use App\Support\MarketSession;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Reuse the dashboard calculator with an explicit immutable session anchor. */
class SocialGexSource extends GexController
{
    public static function expectedDate(string $session): string
    {
        return MarketSession::tradingDateOnOrBefore(CarbonImmutable::parse($session, 'America/New_York')->subDay());
    }

    public function capture(string $symbol, string $session): array
    {
        $at = CarbonImmutable::parse($session, 'America/New_York')->setTime(8, 30);
        if (! MarketSession::isTradingDay($at)) {
            throw new DomainException('This date is not a market trading day.');
        }
        $expected = self::expectedDate($session);

        // One repeatable-read transaction keeps the eligibility check and raw calculation together.
        return DB::transaction(function () use ($symbol, $at, $expected) {
            $universe = app(GexExpirationUniverse::class)->resolve($symbol, '14d', $this->uiTimeframes, $at);
            $ids = $universe['expiration_ids'];
            $dates = $universe['timeframe_expirations']['14d'] ?? [];
            if (! $ids || ! $dates) {
                throw new DomainException('No expirations are available for this symbol and 2W scope.');
            }
            $selected = app(EodSnapshotSelector::class)->selectedDateRows($ids, $expected);
            if ($selected->count() !== count($ids) || $selected->contains(fn ($row) => (string) $row->max_date !== $expected)) {
                throw new DomainException("A complete {$expected} snapshot is required for every included expiry. Regenerate after the EOD data is ready.");
            }
            $inputs = app(EodSnapshotSelector::class)->selectedRows($ids, ['option_chain_data.gamma', 'option_chain_data.underlying_price', 'option_chain_data.open_interest'], $expected);
            $missing = $inputs->filter(fn ($row) => ! is_numeric($row->open_interest) || (float) $row->open_interest < 0
                || ((float) $row->open_interest > 0 && (! is_numeric($row->gamma) || ! is_finite((float) $row->gamma)
                    || ! is_numeric($row->underlying_price) || (float) $row->underlying_price <= 0)))->count();
            $payload = $this->buildGexPayload($symbol, '14d', $dates, $universe['timeframe_expirations'], $ids, $expected);
            if (! $payload || substr((string) $payload['data_date'], 0, 10) !== $expected || empty($payload['strike_data'])) {
                throw new DomainException('The GEX snapshot is unavailable or has an unexpected source date.');
            }
            foreach ($payload['strike_data'] as $row) {
                foreach (['strike', 'net_gex'] as $field) {
                    if (! is_numeric($row[$field] ?? null) || ! is_finite((float) $row[$field])) {
                        throw new DomainException('The snapshot contains incomplete chart readings.');
                    }
                }
            }
            $payload['social_quality'] = ['publishable' => $missing === 0, 'missing_input_rows' => $missing, 'source_rows' => $inputs->count()];

            return $payload;
        });
    }
}
