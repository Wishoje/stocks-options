<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
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
        $sym = strtoupper($symbol);

        $row = DB::table('option_snapshots')
            ->where('symbol', $sym)
            ->orderByDesc('fetched_at')
            ->first(['underlying_price', 'fetched_at']);

        if (!$row || $row->underlying_price === null) {
            return null;
        }

        if ($maxAgeMinutes !== null && $row->fetched_at) {
            $fetchedAt = Carbon::parse($row->fetched_at);
            $age = $fetchedAt->diffInMinutes(now('America/New_York'));
            if ($age > $maxAgeMinutes) {
                // too old → treat as unusable
                return null;
            }
        }

        return (float) $row->underlying_price;
    }

    public function currentPrice(string $symbol, ?int $maxAgeMinutes = null): ?float
    {
        $sym = strtoupper($symbol);

        // 1) intraday first
        [$spot, $asof] = $this->intradaySpotWithAsOf($sym);

        if ($spot !== null) {
            if ($maxAgeMinutes === null) {
                return $spot;
            }

            if ($asof instanceof Carbon) {
                $age = $asof->diffInMinutes(now('America/New_York'));
                if ($age <= $maxAgeMinutes) {
                    return $spot; // fresh intraday
                }
            }

            // intraday present but stale / no asof → fall through to snapshot
        }

        // 2) fallback to snapshot, also age-limited
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
