<?php

namespace App\Support;

use App\Models\OptionChainData;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EodSnapshotSelector
{
    public function minSideRatio(?float $override = null): float
    {
        $ratio = $override ?? (float) config('services.massive.eod_min_side_strike_ratio', 0.35);
        return max(0.01, min(1.0, $ratio));
    }

    public function completedSessionDate(?Carbon $now = null): string
    {
        $ny = ($now ?? now('America/New_York'))->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            return $ny->previousWeekday()->toDateString();
        }

        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            return $ny->previousWeekday()->toDateString();
        }

        return $ny->toDateString();
    }

    public function resolvedAnchorDate(?string $forcedDate = null, ?Carbon $now = null): string
    {
        $sessionDate = $this->completedSessionDate($now);
        $forcedDate = trim((string) ($forcedDate ?? config('services.massive.eod_force_data_date', '')));
        if ($forcedDate === '') {
            return $sessionDate;
        }

        try {
            $forced = Carbon::createFromFormat('Y-m-d', $forcedDate, 'America/New_York')->toDateString();
        } catch (\Throwable) {
            return $sessionDate;
        }

        return strcmp($forced, $sessionDate) <= 0 ? $forced : $sessionDate;
    }

    /**
     * @param array<int> $expirationIds
     */
    public function selectedDatesSubquery(array $expirationIds, ?string $anchorDate = null, ?float $minSideRatio = null): QueryBuilder
    {
        $minSideRatio = $this->minSideRatio($minSideRatio);
        $dateCandidates = OptionChainData::query()
            ->select(
                'expiration_id',
                'data_date',
                DB::raw("COUNT(DISTINCT CASE WHEN option_type = 'call' THEN strike END) as call_strikes_n"),
                DB::raw("COUNT(DISTINCT CASE WHEN option_type = 'put' THEN strike END) as put_strikes_n")
            )
            ->whereIn('expiration_id', $expirationIds)
            ->when($anchorDate, fn ($q) => $q->whereDate('data_date', '<=', $anchorDate))
            ->groupBy('expiration_id', 'data_date');

        $balancedDates = DB::query()
            ->fromSub($dateCandidates, 'dc')
            ->select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->where('call_strikes_n', '>', 0)
            ->where('put_strikes_n', '>', 0)
            ->whereRaw(
                'LEAST(call_strikes_n, put_strikes_n) / NULLIF(GREATEST(call_strikes_n, put_strikes_n), 0) >= ?',
                [$minSideRatio]
            )
            ->groupBy('expiration_id');

        $fallbackDates = OptionChainData::query()
            ->select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->whereIn('expiration_id', $expirationIds)
            ->when($anchorDate, fn ($q) => $q->whereDate('data_date', '<=', $anchorDate))
            ->groupBy('expiration_id');

        return DB::query()
            ->fromSub($fallbackDates, 'fb')
            ->leftJoinSub($balancedDates, 'bd', function ($join) {
                $join->on('fb.expiration_id', '=', 'bd.expiration_id');
            })
            ->select(
                'fb.expiration_id',
                DB::raw('COALESCE(bd.max_date, fb.max_date) as max_date')
            );
    }

    /**
     * @param array<int> $expirationIds
     * @return Collection<int,object>
     */
    public function selectedDateRows(array $expirationIds, ?string $anchorDate = null, ?float $minSideRatio = null): Collection
    {
        return DB::query()
            ->fromSub($this->selectedDatesSubquery($expirationIds, $anchorDate, $minSideRatio), 'ld')
            ->get();
    }

    /**
     * @param array<int> $expirationIds
     * @param array<int,string> $columns
     * @return Collection<int,object>
     */
    public function selectedRows(array $expirationIds, array $columns = ['option_chain_data.*'], ?string $anchorDate = null, ?float $minSideRatio = null): Collection
    {
        $selectedDates = $this->selectedDatesSubquery($expirationIds, $anchorDate, $minSideRatio);

        return OptionChainData::query()
            ->joinSub($selectedDates, 'ld', function ($join) {
                $join->on('option_chain_data.expiration_id', '=', 'ld.expiration_id')
                    ->on('option_chain_data.data_date', '=', 'ld.max_date');
            })
            ->whereIn('option_chain_data.expiration_id', $expirationIds)
            ->get($columns);
    }

    /**
     * @param array<int> $expirationIds
     * @return Collection<int,array<string,mixed>>
     */
    public function summary(array $expirationIds, ?string $anchorDate = null, ?float $minSideRatio = null): Collection
    {
        $minSideRatio = $this->minSideRatio($minSideRatio);
        $selected = $this->selectedDateRows($expirationIds, $anchorDate, $minSideRatio)->keyBy('expiration_id');

        $rows = OptionChainData::query()
            ->select(
                'expiration_id',
                'data_date',
                DB::raw("COUNT(DISTINCT CASE WHEN option_type = 'call' THEN strike END) as call_strikes_n"),
                DB::raw("COUNT(DISTINCT CASE WHEN option_type = 'put' THEN strike END) as put_strikes_n"),
                DB::raw('COUNT(DISTINCT strike) as strike_count')
            )
            ->whereIn('expiration_id', $expirationIds)
            ->when($anchorDate, fn ($q) => $q->whereDate('data_date', '<=', $anchorDate))
            ->groupBy('expiration_id', 'data_date')
            ->orderBy('expiration_id')
            ->orderByDesc('data_date')
            ->get()
            ->groupBy('expiration_id');

        return collect($expirationIds)->mapWithKeys(function (int $expirationId) use ($rows, $selected, $minSideRatio) {
            $history = $rows->get($expirationId, collect());
            $latestAny = $history->first();
            $latestBalanced = $history->first(fn ($row) => EodHealth::sideRatioMeetsThreshold(
                (int) ($row->call_strikes_n ?? 0),
                (int) ($row->put_strikes_n ?? 0),
                $minSideRatio
            ));
            $selectedDate = $selected->get($expirationId)->max_date ?? null;
            $selectedRow = $selectedDate
                ? $history->first(fn ($row) => (string) $row->data_date === (string) $selectedDate)
                : null;

            return [$expirationId => [
                'expiration_id' => $expirationId,
                'latest_any_date' => $latestAny->data_date ?? null,
                'latest_balanced_date' => $latestBalanced->data_date ?? null,
                'selected_date' => $selectedDate,
                'latest_any_call_rows' => (int) ($latestAny->call_strikes_n ?? 0),
                'latest_any_put_rows' => (int) ($latestAny->put_strikes_n ?? 0),
                'latest_any_strike_count' => (int) ($latestAny->strike_count ?? 0),
                'latest_any_side_ratio' => $latestAny
                    ? EodHealth::sideStrikeRatio(
                        (int) ($latestAny->call_strikes_n ?? 0),
                        (int) ($latestAny->put_strikes_n ?? 0)
                    )
                    : null,
                'selected_call_rows' => (int) ($selectedRow->call_strikes_n ?? 0),
                'selected_put_rows' => (int) ($selectedRow->put_strikes_n ?? 0),
                'selected_strike_count' => (int) ($selectedRow->strike_count ?? 0),
                'selected_side_ratio' => $selectedRow
                    ? EodHealth::sideStrikeRatio(
                        (int) ($selectedRow->call_strikes_n ?? 0),
                        (int) ($selectedRow->put_strikes_n ?? 0)
                    )
                    : null,
            ]];
        });
    }
}
