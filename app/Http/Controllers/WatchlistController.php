<?php

namespace App\Http\Controllers;

use App\Models\Watchlist;
use App\Support\Market;
use App\Support\QueueLanes;
use App\Support\SymbolBootstrapCoordinator;
use App\Support\SymbolBootstrapPolicy;
use App\Support\WorkRunCoordinator;
use App\Support\WorkRunDispatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WatchlistController extends Controller
{
    public function index()
    {
        return Watchlist::query()
            ->where('user_id', Auth::id())
            ->orderBy('symbol')
            ->get(['id', 'symbol']);
    }

    public function universe()
    {
        return Watchlist::query()
            ->select('symbol')
            ->distinct()
            ->orderBy('symbol')
            ->get()
            ->map(fn (Watchlist $row) => ['symbol' => $row->symbol])
            ->values();
    }

    public function store(
        Request $req,
        WorkRunCoordinator $runs,
        WorkRunDispatcher $dispatcher,
        SymbolBootstrapPolicy $bootstrapPolicy,
        SymbolBootstrapCoordinator $bootstrap
    ) {
        $validated = $req->validate([
            // Production watchlists.symbol is VARCHAR(10). Keep validation at
            // the storage boundary so a valid-but-long provider symbol cannot
            // turn into a MySQL truncation error.
            'symbol' => ['required', 'string', 'max:10'],
        ]);
        $symbol = \App\Support\Symbols::canon((string) $validated['symbol']);
        if (! \App\Support\Symbols::isValid($symbol)) {
            return response()->json(['message' => 'The selected symbol is invalid.'], 422);
        }

        $row = Watchlist::firstOrCreate(
            ['user_id' => Auth::id(), 'symbol' => $symbol],
            []
        );

        $bootstrapParameters = $bootstrapPolicy->claimParameters();
        $this->startWorkRun(
            $runs,
            $dispatcher,
            $bootstrap,
            'symbol_bootstrap',
            $symbol,
            $bootstrapParameters,
            QueueLanes::bootstrap(),
            $req
        );

        // Intraday bootstrap:
        // - during market hours: always allow
        // - outside market hours: allow only when symbol has no intraday rows yet
        $marketOpen = Market::isRthOpen(now('America/New_York'));
        $hasIntraday = DB::table('option_live_counters')
            ->where('symbol', $symbol)
            ->exists();
        $hasExpiries = DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->exists();

        if (! $bootstrapPolicy->enabled() && ($marketOpen || ! $hasIntraday) && $hasExpiries) {
            $this->startWorkRun(
                $runs,
                $dispatcher,
                $bootstrap,
                'intraday_refresh',
                $symbol,
                ['trade_date' => $this->tradingDate(now('America/New_York'))],
                $this->intradayQueueForSymbol($symbol),
                $req
            );
        }

        // Option A
        $row->refresh(); // reload from DB

        return response()->json($row->only(['id', 'symbol']), 201);
    }

    public function destroy(int $id)
    {
        Watchlist::where('id', $id)->where('user_id', Auth::id())->delete();

        return response()->noContent();
    }

    private function intradayQueueForSymbol(string $symbol): string
    {
        return QueueLanes::intraday($symbol, interactive: true);
    }

    private function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend() || (int) $ny->format('Hi') < 930) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }

    /** @param array<string, mixed> $parameters */
    private function startWorkRun(
        WorkRunCoordinator $runs,
        WorkRunDispatcher $dispatcher,
        SymbolBootstrapCoordinator $bootstrap,
        string $kind,
        string $symbol,
        array $parameters,
        string $queue,
        Request $request
    ): void {
        if ($kind === 'symbol_bootstrap' && isset($parameters['session_date'])) {
            $authoritative = $bootstrap->authoritativeWorkRun(
                $symbol,
                (string) $parameters['session_date']
            );
            if ($authoritative) {
                return;
            }
        }

        $claim = $runs->claim(
            $kind,
            $symbol,
            $parameters,
            $queue,
            $request->user(),
            deferWhenRateLimited: true
        );

        if ($kind === 'symbol_bootstrap' && isset($parameters['session_date'])) {
            $bootstrap->initialize($claim['run']);
        }

        if (! $claim['created'] || $claim['deferred']) {
            return;
        }

        try {
            $dispatcher->dispatch($claim['run']);
        } catch (Throwable $exception) {
            Log::channel('queue_monitor')->warning('watchlist.work_run_dispatch_deferred', [
                'kind' => $kind,
                'symbol' => $symbol,
                'exception' => $exception::class,
            ]);
        }
    }
}
