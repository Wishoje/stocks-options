<?php

namespace App\Services;

use App\Models\UnderlyingQuote;
use Illuminate\Support\Facades\DB;
use App\Support\Symbols;
use App\Http\Controllers\GexController;
use App\Http\Controllers\IntradayController;
use Carbon\Carbon;

class WallService
{
    /**
     * Simple per-request cache for intraday composites.
     *
     * @var array<string, array|null>
     */
    protected array $intradayCache = [];

    /**
     * Legacy spot from option_snapshots.
     *
     * If $maxAgeMinutes is provided, we only accept rows whose fetched_at
     * is not older than that window. Otherwise behaves as before.
     */
    public function latestSpot(string $symbol, ?int $maxAgeMinutes = null): ?float
    {
        $symbol = Symbols::canon($symbol);

        $q = UnderlyingQuote::where('symbol', $symbol);

        if ($maxAgeMinutes !== null) {
            $cutoff = now('UTC')->subMinutes($maxAgeMinutes);
            $q->where('asof', '>=', $cutoff);
        }

        $row = $q->orderByDesc('asof')->first();

        if ($row && $row->last_price > 0) {
            return (float) $row->last_price;
        }

        // Fallback: old behavior using option_snapshots,
        // in case you still have legacy data there.
        $fallback = DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->when($maxAgeMinutes !== null, function ($q) use ($maxAgeMinutes) {
                $q->where('fetched_at', '>=', now()->subMinutes($maxAgeMinutes));
            })
            ->orderByDesc('fetched_at')
            ->value('underlying_price');

        return $fallback ? (float)$fallback : null;
    }

    /**
     * Preferred current price accessor:
     *  1) intraday composite (age-limited if $maxAgeMinutes given)
     *  2) fallback to option_snapshots (also age-limited)
     */
    public function currentPrice(string $symbol, ?int $maxAgeMinutes = null): ?float
    {
        $sym = strtoupper($symbol);

        // Try intraday first
        [$spot, $asof] = $this->intradaySpotWithAsOf($sym);

        if ($spot !== null) {
            // If we care about age, enforce it
            if ($maxAgeMinutes !== null && $asof instanceof Carbon) {
                $age = $asof->diffInMinutes(now('America/New_York'));
                if ($age > $maxAgeMinutes) {
                    $spot = null; // too stale
                }
            }
        }

        // If intraday looks okay, sanity-check it against last known snapshot
        if ($spot !== null) {
            $snap = $this->latestSpot($sym, null); // no age limit, just for comparison
            if ($snap !== null) {
                $diffPct = $this->distancePct($snap, $spot);
                $diffPts = abs($snap - $spot);

                // if intraday is wildly off vs last snapshot, trust snapshot instead
                if ($diffPct > 25 && $diffPts > 10) {
                    return $snap;
                }
            }

            return $spot;
        }

        // Otherwise fall back to snapshot with age limit
        return $this->latestSpot($sym, $maxAgeMinutes);
    }


    protected function intradaySpotWithAsOf(string $symbol): array
    {
        $data = $this->intradayComposite($symbol);

        if (!$data) {
            return [null, null];
        }

        // pull price
        $candidates = ['spot', 'underlying_price', 'underlying', 'last'];
        $price = null;

        foreach ($candidates as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $price = (float) $data[$key];
                break;
            }
        }

        // pull as-of
        $asof = $this->extractIntradayAsOf($data);

        return [$price, $asof];
    }

    protected function extractIntradayAsOf(array $data): ?Carbon
    {
        foreach (['asof', 'as_of', 'last_updated', 'updated_at'] as $key) {
            if (!empty($data[$key])) {
                try {
                    return Carbon::parse($data[$key]);
                } catch (\Throwable $e) {
                    // ignore and try next key
                }
            }
        }

        return null;
    }

    /**
     * EOD (put/call) walls using your existing GexController endpoint.
     */
    public function eodWalls(string $symbol, string $timeframe = '30d'): array
    {
        /** @var GexController $ctrl */
        $ctrl = app(GexController::class);

        // console command has a global request() instance; we can safely duplicate
        $req  = request()->duplicate(['symbol' => $symbol, 'timeframe' => $timeframe]);
        $resp = $ctrl->getGexLevels($req);

        if ($resp->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($resp->getContent(), true) ?: [];

        return [
            'put_wall'  => $data['put_support']     ?? null,
            'call_wall' => $data['call_resistance'] ?? null,
        ];
    }

    public function intradayCallWall(string $symbol, ?int $maxAgeMinutes = null): array
    {
        $data  = $this->intradayComposite($symbol);
        if (!$data) {
            return [];
        }

        // age-guard the “live” wall as well
        if ($maxAgeMinutes !== null) {
            $asof = $this->extractIntradayAsOf($data);
            if (!$asof || $asof->diffInMinutes(now('America/New_York')) > $maxAgeMinutes) {
                return []; // intraday payload is stale → no live wall
            }
        }

        $items = $data['items'] ?? [];
        if (empty($items)) {
            return [];
        }

        $best = null;
        foreach ($items as $row) {
            $ng = $row['net_gex_live'] ?? null;
            if ($ng === null) continue;
            if ($best === null || $ng > $best['net_gex_live']) {
                $best = $row;
            }
        }

        if (!$best) return [];

        return [
            'call_wall' => $best['strike'],
        ];
    }

    /**
     * Distance in percent between spot and strike.
     */
    public function distancePct(float $spot, float $strike): float
    {
        if ($spot <= 0 || $strike <= 0) {
            return INF;
        }

        return abs($spot - $strike) / $spot * 100.0;
    }

    /**
     * Internal helper: fetch & cache intraday composite for a symbol.
     *
     * This keeps us from calling IntradayController multiple times for
     * the same symbol in a single request (better performance).
     */
    protected function intradayComposite(string $symbol): ?array
    {
        $sym = strtoupper($symbol);

        if (array_key_exists($sym, $this->intradayCache)) {
            return $this->intradayCache[$sym];
        }

        /** @var IntradayController $ctrl */
        $ctrl = app(IntradayController::class);

        $req  = request()->duplicate(['symbol' => $sym]);
        $resp = $ctrl->strikesComposite($req);

        if ($resp->getStatusCode() !== 200) {
            return $this->intradayCache[$sym] = null;
        }

        $data = json_decode($resp->getContent(), true) ?: null;

        return $this->intradayCache[$sym] = ($data ?: null);
    }
}
