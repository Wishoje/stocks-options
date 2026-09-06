<?php

namespace App\Support;

use App\Models\OptionExpiration;
use App\Support\Regression\CanonicalJson;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GexExpirationUniverse
{
    /**
     * Catalog availability deliberately does not require an option-chain row.
     * Snapshot readiness is checked separately by the GEX controller.
     *
     * @param  list<string>  $uiTimeframes
     * @return array{timeframe_expirations:array<string,list<string>>,expiration_ids:list<int>}
     */
    public function resolve(
        string $symbol,
        string $requestedTimeframe,
        array $uiTimeframes = ['0d', '1d', '7d', '14d', '30d', '90d'],
        ?CarbonInterface $at = null,
    ): array {
        $bounds = $this->timeframeBounds($requestedTimeframe, $uiTimeframes, $at);
        // These are semantic bounds from all requested/UI horizons, not an
        // arbitrary expiration count or a reduced coverage window.
        $start = min(array_column($bounds, 0));
        $end = max(array_column($bounds, 1));
        $catalog = $this->loadUniverse($symbol, $start, $end)->map(static fn ($row): array => [
            'expiration_id' => (int) $row->id,
            'expiration_date' => (string) $row->expiration_date,
        ])->all();

        return $this->project($catalog, $bounds, $requestedTimeframe);
    }

    /**
     * Resolve the validated per-symbol catalog from an EOD manifest without
     * touching the database. Input ordering does not affect response ordering.
     *
     * @param  list<array{expiration_id:int,expiration_date:string}>  $catalog
     * @param  list<string>  $uiTimeframes
     * @return array{timeframe_expirations:array<string,list<string>>,expiration_ids:list<int>}
     */
    public function resolveFromCatalog(
        array $catalog,
        string $requestedTimeframe,
        array $uiTimeframes = ['0d', '1d', '7d', '14d', '30d', '90d'],
        ?CarbonInterface $at = null,
    ): array {
        return $this->project($catalog, $this->timeframeBounds($requestedTimeframe, $uiTimeframes, $at), $requestedTimeframe);
    }

    /** @return array<string,array{string,string}> */
    private function timeframeBounds(string $requestedTimeframe, array $uiTimeframes, ?CarbonInterface $at): array
    {
        // Monthly calculations historically use the application's timezone;
        // weekday horizons use New York. Preserve both from one instant.
        $clock = Carbon::instance($at ?? Carbon::now())->setTimezone(date_default_timezone_get());
        $candidates = array_unique(array_merge($uiTimeframes, [$requestedTimeframe]));
        $bounds = [];
        foreach ($candidates as $timeframe) {
            $bounds[$timeframe] = $this->bounds($timeframe, $clock);
        }

        return $bounds;
    }

    private function project(array $catalog, array $bounds, string $requestedTimeframe): array
    {
        usort($catalog, static fn (array $left, array $right): int => strcmp($left['expiration_date'], $right['expiration_date'])
            ?: ($left['expiration_id'] <=> $right['expiration_id']));
        $availability = [];
        $expirationIds = [];
        foreach ($bounds as $timeframe => [$from, $through]) {
            $dates = [];
            foreach ($catalog as $row) {
                $date = (string) $row['expiration_date'];
                if ($date < $from || $date > $through) {
                    continue;
                }
                $dates[$date] = $date;
                if ((string) $timeframe === $requestedTimeframe) {
                    $expirationIds[] = (int) $row['expiration_id'];
                }
            }
            if ($dates !== []) {
                $availability[$timeframe] = array_values($dates);
            }
        }

        return ['timeframe_expirations' => $availability, 'expiration_ids' => $expirationIds];
    }

    /**
     * Date/list order is part of the response contract. Internal expiration
     * IDs represent a set, so their database-dependent order is normalized.
     * No market payload or raw identifiers need to be written to logs.
     *
     * @return array{matches:bool,legacy_hash:string,candidate_hash:string}
     */
    public function compareSelections(array $legacy, array $candidate): array
    {
        $hash = static function (array $selection): string {
            $dates = $selection['timeframe_expirations'];
            $ids = array_map('intval', $selection['expiration_ids']);
            sort($ids, SORT_NUMERIC);

            return hash('sha256', CanonicalJson::encode([
                'available_timeframes' => array_keys($dates),
                'timeframe_expirations' => $dates,
                'expiration_ids' => $ids,
            ]));
        };
        $legacyHash = $hash($legacy);
        $candidateHash = $hash($candidate);

        return [
            'matches' => hash_equals($legacyHash, $candidateHash),
            'legacy_hash' => $legacyHash,
            'candidate_hash' => $candidateHash,
        ];
    }

    protected function loadUniverse(string $symbol, string $start, string $end): Collection
    {
        return OptionExpiration::query()
            ->where('symbol', $symbol)
            ->whereBetween('expiration_date', [$start, $end])
            ->orderBy('expiration_date')
            ->get(['id', 'expiration_date']);
    }

    /** @return array{string,string} */
    private function bounds(string $timeframe, Carbon $clock): array
    {
        $lookaheads = [
            '0d' => 0, '1d' => 1, '7d' => 5, '14d' => 10, '21d' => 15,
            '30d' => 21, '45d' => 32, '60d' => 43, '90d' => 64,
        ];
        if ($timeframe === 'monthly') {
            $monthly = $this->thirdFriday($clock);
            if ($monthly->lt($clock->copy()->startOfDay())) {
                // Keep Carbon's existing addMonth overflow behavior.
                $monthly = $this->thirdFriday($clock->copy()->addMonth());
            }
            $date = $monthly->toDateString();

            return [$date, $date];
        }

        $anchor = $clock->copy()->setTimezone('America/New_York')->startOfDay();
        if ($anchor->isWeekend()) {
            $anchor = $anchor->previousWeekday()->startOfDay();
        }
        // Holidays are intentionally counted as weekdays, matching the old
        // endpoint. Unknown timeframe keys retain the 14-weekday fallback.
        $days = $lookaheads[$timeframe] ?? 14;

        return [
            $anchor->toDateString(),
            $days > 0 ? $anchor->copy()->addWeekdays($days)->toDateString() : $anchor->toDateString(),
        ];
    }

    private function thirdFriday(Carbon $date): Carbon
    {
        $first = $date->copy()->startOfMonth();
        $firstFriday = $first->isFriday() ? $first : $first->copy()->next(Carbon::FRIDAY);

        return $firstFriday->copy()->addWeeks(2);
    }
}
