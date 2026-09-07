<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Set-based summaries with the original batch's date and missing-data rules. */
class ExpiryPressureBatch
{
    // Internal transport/query bound, not a request or watchlist size limit.
    private const CHUNK_SIZE = 500;

    /** @param list<string> $symbols Already canonicalized/deduplicated by the endpoint. */
    public function read(array $symbols, int $days, string $anchor): array
    {
        $latest = [];
        $hasAnchoredDates = false;
        foreach (array_chunk($symbols, self::CHUNK_SIZE) as $chunk) {
            $rows = DB::table('expiry_pressure')
                ->select('symbol')
                ->selectRaw('MAX(CASE WHEN data_date <= ? THEN data_date END) AS anchored_date, MAX(data_date) AS any_date', [$anchor])
                ->whereIn('symbol', $chunk)->groupBy('symbol')->get();
            foreach ($rows as $row) {
                $latest[$row->symbol] = $row;
                $hasAnchoredDates = $hasAnchoredDates || $row->anchored_date !== null;
            }
        }

        $selected = [];
        foreach ($symbols as $symbol) {
            $row = $latest[$symbol] ?? null;
            // Legacy falls back globally only when NO requested symbol has an
            // anchored row. Do not turn mixed-batch future-only items into data.
            $date = $hasAnchoredDates ? ($row->anchored_date ?? null) : ($row->any_date ?? null);
            if ($date !== null) {
                $selected[] = ['symbol' => $symbol, 'data_date' => $date];
            }
        }

        $usable = [];
        $spotIndex = $selected === [] ? null : $this->compatibleSpotIndex();
        foreach (array_chunk($selected, self::CHUNK_SIZE) as $chunk) {
            if ($spotIndex === null) {
                $usable += $this->legacySpotChecks($chunk);

                continue;
            }
            try {
                $spots = $this->spotProbes($chunk, $spotIndex)->first();
                foreach ($chunk as $position => $row) {
                    if ($spots->{'spot_'.$position}) {
                        $usable[$row['symbol']] = true;
                    }
                }
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1176) {
                    throw $exception;
                }
                // An operator can remove/hide an index after the capability
                // check. Only that missing-index error takes the legacy path.
                $spotIndex = null;
                $usable += $this->legacySpotChecks($chunk);
            }
        }

        $bounds = [];
        $endDates = [];
        $items = [];
        foreach ($symbols as $symbol) {
            $row = $latest[$symbol] ?? null;
            $date = $hasAnchoredDates ? ($row->anchored_date ?? null) : ($row->any_date ?? null);
            if ($date !== null && ! isset($usable[$symbol])) {
                $date = $row->any_date ?: $date;
            }
            $items[$symbol] = ['data_date' => $date, 'headline_pin' => null];
            if ($date !== null) {
                $endDates[$date] ??= $this->endDate($date, $days);
                $bounds[] = ['symbol' => $symbol, 'data_date' => $date, 'end_date' => $endDates[$date]];
            }
        }
        foreach (array_chunk($bounds, self::CHUNK_SIZE) as $chunk) {
            $groups = [];
            $requested = array_fill_keys(array_column($chunk, 'symbol'), true);
            foreach ($chunk as $row) {
                $groups[$row['data_date']]['end_date'] = $row['end_date'];
                $groups[$row['data_date']]['symbols'][] = $row['symbol'];
            }
            $headlines = DB::table('expiry_pressure')->where(function (Builder $query) use ($groups): void {
                foreach ($groups as $date => $group) {
                    $query->orWhere(function (Builder $slice) use ($date, $group): void {
                        $slice->whereIn('symbol', $group['symbols'])->where('data_date', $date)
                            ->whereBetween('exp_date', [$date, $group['end_date']]);
                    });
                }
            })->select(['symbol', 'pin_score'])->cursor();
            foreach ($headlines as $headline) {
                $symbol = Symbols::canon($headline->symbol);
                if (! isset($requested[$symbol])) {
                    continue;
                }
                $current = $items[$symbol]['headline_pin'];
                $items[$symbol]['headline_pin'] = $current === null
                    ? (int) $headline->pin_score : max($current, (int) $headline->pin_score);
            }
        }

        return $items;
    }

    /** @param non-empty-list<array{symbol:string,data_date:string}> $rows */
    private function spotProbes(array $rows, string $index): Builder
    {
        // One bounded result row maps scalar answers to request positions.
        $query = DB::query();
        foreach ($rows as $position => $row) {
            $probe = DB::table('option_chain_data as o')
                ->forceIndex(DB::connection()->getQueryGrammar()->wrap($index))
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->where('e.symbol', $row['symbol'])
                // The capability check verifies this is a DATE column and
                // the chosen index leads with expiration_id, data_date.
                ->where('o.data_date', $row['data_date'])
                ->where('o.underlying_price', '>', 0);
            $query->selectRaw('EXISTS('.$probe->toSql().') AS spot_'.$position, $probe->getBindings());
        }

        return $query;
    }

    /** One read-only capability query per cold batch, never one per symbol. */
    protected function compatibleSpotIndex(): ?string
    {
        try {
            $connection = DB::connection();
            $rows = $connection->table('information_schema.statistics as s')
                ->join('information_schema.columns as c', function ($join): void {
                    $join->on('c.table_schema', '=', 's.table_schema')
                        ->on('c.table_name', '=', 's.table_name')
                        ->where('c.column_name', 'data_date')->where('c.data_type', 'date');
                })
                ->where('s.table_schema', $connection->getDatabaseName())
                ->where('s.table_name', $connection->getTablePrefix().'option_chain_data')
                ->where('s.is_visible', 'YES')->where('s.index_type', 'BTREE')
                ->select('s.index_name as index_name')
                ->selectRaw("GROUP_CONCAT(COALESCE(s.column_name, '#expression') ORDER BY s.seq_in_index) AS indexed_columns")
                ->groupBy('s.index_name')->orderBy('s.index_name')->get();
            foreach ($rows as $row) {
                if (array_slice(explode(',', strtolower($row->indexed_columns)), 0, 2) === ['expiration_id', 'data_date']
                    && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $row->index_name) === 1) {
                    return $row->index_name;
                }
            }
        } catch (\Throwable) {
            // Restricted metadata access or an older schema remains readable
            // through the original probes; that fallback is not optimized.
        }

        return null;
    }

    /** @param list<array{symbol:string,data_date:string}> $rows */
    private function legacySpotChecks(array $rows): array
    {
        $usable = [];
        foreach ($rows as $row) {
            if (DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->where('e.symbol', $row['symbol'])->whereDate('o.data_date', $row['data_date'])
                ->where('o.underlying_price', '>', 0)->exists()) {
                $usable[$row['symbol']] = true;
            }
        }

        return $usable;
    }

    private function endDate(string $start, int $days): string
    {
        $date = Carbon::parse($start, 'America/New_York');
        while ($days > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date->toDateString();
    }
}
