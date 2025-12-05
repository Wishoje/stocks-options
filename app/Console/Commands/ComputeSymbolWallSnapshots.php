<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\SymbolWallSnapshot;
use App\Services\WallService;

class ComputeSymbolWallSnapshots extends Command
{
    protected $signature = 'walls:compute
                            {--timeframe=30d : EOD GEX timeframe (1d,7d,14d,30d or all)}
                            {--limit=400 : Max symbols from hot_option_symbols}
                            {--source=hot : hot|all}';

    protected $description = 'Precompute EOD + intraday walls and distances for a symbol universe';

    /**
     * All EOD timeframes you want snapshots for.
     * Add/remove as needed.
     *
     * @var string[]
     */
    protected array $eodTimeframes = ['1d', '7d', '14d', '30d'];

    public function handle(WallService $walls): int
    {
        $timeframeOpt = (string) $this->option('timeframe');
        $limit        = (int) $this->option('limit');
        $source       = (string) $this->option('source');

        $tradeDate = now('America/New_York')->toDateString();

        // Resolve which timeframes to compute
        if ($timeframeOpt === 'all') {
            $timeframes = $this->eodTimeframes;
        } else {
            // allow comma-separated list: 14d,30d
            $timeframes = array_filter(array_map('trim', explode(',', $timeframeOpt)));
            if (empty($timeframes)) {
                $timeframes = ['30d'];
            }
        }

        // 1) Choose universe
        if ($source === 'hot') {
            $latestTradeDate = DB::table('hot_option_symbols')->max('trade_date');

            if (!$latestTradeDate) {
                $this->warn('No hot_option_symbols rows found.');
                return self::SUCCESS;
            }

            $symbols = DB::table('hot_option_symbols')
                ->whereDate('trade_date', $latestTradeDate)
                ->orderBy('rank')
                ->limit($limit)
                ->pluck('symbol')
                ->map(fn ($s) => \App\Support\Symbols::canon($s))
                ->unique()
                ->values()
                ->all();
        } else {
            $symbols = DB::table('option_expirations')
                ->select('symbol')->distinct()
                ->pluck('symbol')
                ->map(fn ($s) => \App\Support\Symbols::canon($s))
                ->unique()
                ->values()
                ->all();
        }

        if (!$symbols) {
            $this->warn('No symbols to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Computing wall snapshots for %d symbols across [%s]…',
            count($symbols),
            implode(', ', $timeframes),
        ));

        foreach ($symbols as $sym) {
            try {
                // Require price not older than 30 minutes
                $spot = $walls->currentPrice($sym, 30);

                if ($spot === null) {
                    // No fresh price → skip this symbol entirely
                    continue;
                }

                // Intraday call wall should also be fresh
                $intr      = $walls->intradayCallWall($sym, 30);
                $intrCall  = $intr['call_wall'] ?? null;
                $intrDist  = $intrCall ? $walls->distancePct($spot, $intrCall) : null;

                foreach ($timeframes as $tf) {
                    $eod      = $walls->eodWalls($sym, $tf);
                    $eodPut   = $eod['put_wall']  ?? null;
                    $eodCall  = $eod['call_wall'] ?? null;
                    $eodPutDp = $eodPut  ? $walls->distancePct($spot, $eodPut)  : null;
                    $eodCallDp= $eodCall ? $walls->distancePct($spot, $eodCall) : null;

                    $row = [
                        'spot'                   => $spot,
                        'eod_put_wall'           => $eodPut,
                        'eod_call_wall'          => $eodCall,
                        'eod_put_dist_pct'       => $eodPutDp,
                        'eod_call_dist_pct'      => $eodCallDp,
                        'intraday_call_wall'     => $intrCall,
                        'intraday_call_dist_pct' => $intrDist,
                    ];

                    SymbolWallSnapshot::updateOrCreate(
                        [
                            'symbol'     => $sym,
                            'trade_date' => $tradeDate,
                            'timeframe'  => $tf,
                        ],
                        $row
                    );
                }
            } catch (\Throwable $e) {
                $this->error("Error for {$sym}: ".$e->getMessage());
            }
        }



        $this->info('Done.');

        return self::SUCCESS;
    }
}
