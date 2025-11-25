<?php

namespace App\Console\Commands;

use App\Support\SteadyApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchHotOptionUniverse extends Command
{
    protected $signature = 'hot-options:fetch 
                            {--limit=200 : Max symbols} 
                            {--type=STOCKS : Universe type for SteadyAPI (STOCKS|ETFS|INDICES)} 
                            {--days=5 : Lookback window (in days) for Polygon EOD ranking}';

    protected $description = 'Fetch today\'s most active option underlyings (prefer SteadyAPI, fallback to Polygon EOD option_chain_data)';

    public function handle(SteadyApiClient $client): int
    {
        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 500));

        $type  = (string) $this->option('type');
        $days  = max(1, (int) $this->option('days'));

        $this->info("Building hot options universe (limit={$limit}, days={$days}, type={$type})…");

        $items    = [];
        $viaSteady = false;

        /**
         * 1) Try SteadyAPI (if you ever upgrade that plan, this "just works")
         */
        try {
            $items = $client->mostActiveOptions($limit, $type);
            $viaSteady = !empty($items);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $this->error('Error fetching from SteadyAPI: '.$msg);
            Log::warning('hot-options:fetch SteadyAPI failed', ['error' => $msg]);
        }

        if ($viaSteady) {
            $this->info('SteadyAPI returned '.count($items).' symbols, storing snapshot…');
            $this->storeSteadySnapshot($items, $limit);
            return self::SUCCESS;
        }

        /**
         * 2) Primary path for you right now:
         *    Build "top 200" purely from Polygon EOD chains in option_chain_data.
         */
        $this->warn('Using Polygon EOD data (option_chain_data) to compute hot universe…');

        $this->storePolygonEodSnapshot($limit, $days);

        return self::SUCCESS;
    }

    /**
     * Store SteadyAPI response into hot_option_symbols for "today".
     *
     * @param array<int,array<string,mixed>> $items
     */
    protected function storeSteadySnapshot(array $items, int $limit): void
    {
        $today = now('America/New_York')->toDateString();

        DB::transaction(function () use ($items, $today, $limit) {
            DB::table('hot_option_symbols')
                ->whereDate('trade_date', $today)
                ->delete();

            $rows = [];
            $now  = now();

            foreach (array_slice($items, 0, $limit) as $idx => $row) {
                $rank = $idx + 1;

                $rows[] = [
                    'trade_date'     => $today,
                    'symbol'         => $row['symbol'],
                    'rank'           => $rank,
                    'total_volume'   => $row['total_volume'] ?? null,
                    'call_volume'    => $row['call_volume'] ?? null,
                    'put_volume'     => $row['put_volume'] ?? null,
                    'put_call_ratio' => $row['put_call_ratio'] ?? null,
                    'last_price'     => $row['last_price'] ?? null,
                    'source'         => 'steadyapi',
                    'payload'        => json_encode($row['raw'] ?? []),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('hot_option_symbols')->insert($rows);
            }
        });

        $this->info('Stored '.min(count($items), $limit)." SteadyAPI symbols into hot_option_symbols (trade_date={$today}).");
    }

    /**
     * PRIMARY path for you now:
     * Build top-N underlyings from Polygon EOD chains in option_chain_data.
     *
     * - Use the latest data_date you have as "end" of window
     * - Use (days) lookback – e.g. days=5 → last 5 trading days up to latestDate
     * - Rank by SUM(volume) then SUM(open_interest)
     */
    protected function storePolygonEodSnapshot(int $limit, int $days): void
    {
        $latestDate = DB::table('option_chain_data')->max('data_date');

        if (!$latestDate) {
            $this->warn('No option_chain_data available to build Polygon EOD universe.');
            return;
        }

        $end   = Carbon::parse($latestDate);
        $start = $end->copy()->subDays($days - 1)->toDateString();
        $endStr = $end->toDateString();

        $this->info("Using Polygon EOD window {$start} → {$endStr} for hot universe.");

        $symbols = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereBetween('o.data_date', [$start, $endStr])
            ->selectRaw('
                e.symbol,
                SUM(o.volume)        as vol_sum,
                SUM(o.open_interest) as oi_sum
            ')
            ->groupBy('e.symbol')
            ->orderByRaw('vol_sum DESC, oi_sum DESC')
            ->limit($limit)
            ->get();

        if ($symbols->isEmpty()) {
            $this->warn('No aggregated symbols found for Polygon EOD ranking.');
            return;
        }

        DB::transaction(function () use ($symbols, $endStr, $start) {
            // Treat "trade_date" as the window end, i.e. the latest EOD date you have
            DB::table('hot_option_symbols')
                ->whereDate('trade_date', $endStr)
                ->delete();

            $rows = [];
            $now  = now();

            foreach ($symbols as $idx => $row) {
                $rank = $idx + 1;

                $rows[] = [
                    'trade_date'     => $endStr,
                    'symbol'         => \App\Support\Symbols::canon($row->symbol),
                    'rank'           => $rank,
                    'total_volume'   => (int) $row->vol_sum,
                    'call_volume'    => null,
                    'put_volume'     => null,
                    'put_call_ratio' => null,
                    'last_price'     => null,
                    'source'         => 'polygon_eod',
                    'payload'        => json_encode([
                        'window_start' => $start,
                        'window_end'   => $endStr,
                        'vol_sum'      => (int) $row->vol_sum,
                        'oi_sum'       => (int) $row->oi_sum,
                    ]),
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }

            DB::table('hot_option_symbols')->insert($rows);
        });

        $this->info('Stored '.$symbols->count()." Polygon EOD symbols into hot_option_symbols (trade_date={$endStr}, source=polygon_eod).");
    }
}
