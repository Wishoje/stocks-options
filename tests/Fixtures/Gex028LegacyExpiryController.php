<?php

namespace Tests\Fixtures;

use App\Http\Controllers\Controller;
use App\Support\EodCacheVersion;
use App\Support\Symbols;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Frozen pre-GEX-028 batch oracle; deliberately retain its query-per-symbol behavior. */
class Gex028LegacyExpiryController extends Controller
{
    public const SOURCE_SHA256 = '23814e2c2d821690873ec90d4d8bf639f6bcb26f2b44b3a06f8e110ec69f69ad';

    public function pressureBatch(Request $req)
    {
        $symbols = collect($req->query('symbols', []))
            ->map(fn ($s) => Symbols::canon($s))
            ->unique()->values();
        $days = (int) $req->query('days', 3);

        if ($symbols->isEmpty()) {
            return response()->json(['items' => []], 200);
        }

        $version = app(EodCacheVersion::class)->signature(
            EodCacheVersion::DOMAIN_EXPIRY_PRESSURE,
            $symbols
        );
        $cacheKey = 'expiry_pressure_batch:v2:'.md5($symbols->join(',').":{$days}:{$version}");
        $anchor = $this->completedSessionDate();

        return \Cache::remember($cacheKey, now()->addHour(), function () use ($symbols, $days, $anchor) {
            $latest = \DB::table('expiry_pressure')
                ->select('symbol', \DB::raw('MAX(data_date) as d'))
                ->whereIn('symbol', $symbols)
                ->whereDate('data_date', '<=', $anchor)
                ->groupBy('symbol')
                ->pluck('d', 'symbol');

            if ($latest->isEmpty()) {
                $latest = \DB::table('expiry_pressure')
                    ->select('symbol', \DB::raw('MAX(data_date) as d'))
                    ->whereIn('symbol', $symbols)
                    ->groupBy('symbol')
                    ->pluck('d', 'symbol');
            }

            $items = [];
            foreach ($symbols as $sym) {
                $d = $latest[$sym] ?? null;
                if ($d && ! $this->hasUsableSpot($sym, $d)) {
                    $d = \DB::table('expiry_pressure')
                        ->where('symbol', $sym)
                        ->max('data_date')
                        ?: $d;
                }
                if (! $d) {
                    $items[$sym] = ['data_date' => null, 'headline_pin' => null];

                    continue;
                }

                $end = (function (string $start, int $n) {
                    $dt = \Carbon\Carbon::parse($start, 'America/New_York');
                    $left = $n;
                    while ($left > 0) {
                        $dt->addDay();
                        if (! $dt->isWeekend()) {
                            $left--;
                        }
                    }

                    return $dt->toDateString();
                })($d, $days);

                $rows = \DB::table('expiry_pressure')
                    ->where('symbol', $sym)
                    ->where('data_date', $d)
                    ->whereBetween('exp_date', [$d, $end])
                    ->pluck('pin_score');
                $items[$sym] = [
                    'data_date' => $d,
                    'headline_pin' => $rows->count() ? (int) $rows->max() : null,
                ];
            }

            return response()->json(['items' => $items], 200);
        });
    }

    protected function completedSessionDate(): string
    {
        $ny = now('America/New_York');
        if ($ny->isWeekend()) {
            return $ny->previousWeekday()->toDateString();
        }
        $cutoff = $ny->copy()->startOfDay()->setTime(16, 15);
        if ($ny->lt($cutoff)) {
            return $ny->previousWeekday()->toDateString();
        }

        return $ny->toDateString();
    }

    protected function hasUsableSpot(string $symbol, string $date): bool
    {
        return DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)
            ->whereDate('o.data_date', $date)
            ->where('o.underlying_price', '>', 0)
            ->exists();
    }
}
