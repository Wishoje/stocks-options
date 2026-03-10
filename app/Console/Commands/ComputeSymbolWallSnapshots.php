<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\SymbolWallSnapshot;
use App\Services\WallService;
use App\Support\Market;

class ComputeSymbolWallSnapshots extends Command
{
    protected $signature = 'walls:compute
                            {--timeframe=30d : EOD GEX timeframe (1d,7d,14d,30d or all)}
                            {--limit=400 : Max symbols from hot_option_symbols}
                            {--source=both : hot|watchlist|both|all}';

    protected $description = 'Precompute EOD + intraday walls and distances for a symbol universe';

    /**
     * All EOD timeframes you want snapshots for.
     *
     * @var string[]
     */
    protected array $eodTimeframes = ['1d', '7d', '14d', '30d'];

    public function handle(WallService $walls): int
    {
        $timeframeOpt = (string) $this->option('timeframe');
        $limit        = (int) $this->option('limit');
        $source       = (string) $this->option('source');

        $nowEt = now('America/New_York');
        $tradeDate = $nowEt->toDateString();
        $marketOpen = Market::isRthOpen($nowEt);
        $spotMaxAgeMinutes = $nowEt->isWeekend()
            ? (72 * 60)
            : ($marketOpen ? 30 : (6 * 60));

        // Resolve which timeframes to compute.
        if ($timeframeOpt === 'all') {
            $timeframes = $this->eodTimeframes;
        } else {
            // allow comma-separated list: 14d,30d
            $timeframes = array_filter(array_map('trim', explode(',', $timeframeOpt)));
            if (empty($timeframes)) {
                $timeframes = ['30d'];
            }
        }

        $symbols = match ($source) {
            'hot' => $this->hotSymbols($limit),
            'watchlist' => $this->watchlistSymbols(),
            'both' => collect($this->hotSymbols($limit))
                ->merge($this->watchlistSymbols())
                ->unique()
                ->values()
                ->all(),
            'all' => $this->allSymbols(),
            default => collect($this->hotSymbols($limit))
                ->merge($this->watchlistSymbols())
                ->unique()
                ->values()
                ->all(),
        };

        if (!$symbols) {
            $this->warn('No symbols to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Computing wall snapshots for %d symbols across [%s]...',
            count($symbols),
            implode(', ', $timeframes),
        ));

        foreach ($symbols as $sym) {
            try {
                // During RTH stay strict. After close, tolerate the latest session close.
                $spot = $walls->latestSpot($sym, $spotMaxAgeMinutes);

                if ($spot === null && !$marketOpen) {
                    $spot = $walls->latestSpot($sym, null);
                }

                if ($spot === null) {
                    // Still no price available for this symbol.
                    continue;
                }

                $intr       = $walls->intradayWalls($sym, $spotMaxAgeMinutes);
                $intrPut    = $intr['put_wall'] ?? null;
                $intrCall   = $intr['call_wall'] ?? null;
                $intrPutDp  = $intrPut ? $walls->distancePct($spot, $intrPut) : null;
                $intrCallDp = $intrCall ? $walls->distancePct($spot, $intrCall) : null;

                foreach ($timeframes as $tf) {
                    $eod       = $walls->eodWalls($sym, $tf);
                    $eodPut    = $eod['put_wall']  ?? null;
                    $eodCall   = $eod['call_wall'] ?? null;
                    $eodPutDp  = $eodPut  ? $walls->distancePct($spot, $eodPut)  : null;
                    $eodCallDp = $eodCall ? $walls->distancePct($spot, $eodCall) : null;

                    $row = [
                        'spot'                   => $spot,
                        'eod_put_wall'           => $eodPut,
                        'eod_call_wall'          => $eodCall,
                        'eod_put_dist_pct'       => $eodPutDp,
                        'eod_call_dist_pct'      => $eodCallDp,
                        'intraday_put_wall'      => $intrPut,
                        'intraday_call_wall'     => $intrCall,
                        'intraday_put_dist_pct'  => $intrPutDp,
                        'intraday_call_dist_pct' => $intrCallDp,
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
                $this->error("Error for {$sym}: " . $e->getMessage());
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function hotSymbols(int $limit): array
    {
        $latestTradeDate = DB::table('hot_option_symbols')->max('trade_date');

        if (!$latestTradeDate) {
            return [];
        }

        return DB::table('hot_option_symbols')
            ->whereDate('trade_date', $latestTradeDate)
            ->orderBy('rank')
            ->limit($limit)
            ->pluck('symbol')
            ->map(fn ($s) => \App\Support\Symbols::canon($s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function watchlistSymbols(): array
    {
        return DB::table('watchlists')
            ->select('symbol')
            ->distinct()
            ->pluck('symbol')
            ->map(fn ($s) => \App\Support\Symbols::canon($s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function allSymbols(): array
    {
        return DB::table('option_expirations')
            ->select('symbol')
            ->distinct()
            ->pluck('symbol')
            ->map(fn ($s) => \App\Support\Symbols::canon($s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
