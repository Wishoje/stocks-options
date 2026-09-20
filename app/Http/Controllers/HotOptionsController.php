<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HotOptionsController extends Controller
{
    private const LOOKBACKS = [5, 10, 20];

    private const MAX_RESULTS = 500;

    public function index(Request $request)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_RESULTS],
            'days' => ['nullable', 'integer', Rule::in(self::LOOKBACKS)],
            'date' => ['nullable', 'date_format:Y-m-d', Rule::prohibitedIf($request->filled('days'))],
        ]);

        $limit = (int) ($validated['limit'] ?? 200);
        $days = (int) ($validated['days'] ?? 10);
        $requestedDate = $validated['date'] ?? null;

        // hot_option_symbols is the market-wide source of truth. It can come
        // from SteadyAPI or a scheduled Polygon EOD build and must not be
        // replaced by the locally primed option-chain subset.
        $stored = $this->storedSnapshot($limit, $days, $requestedDate);
        if ($stored !== null) {
            return response()->json($stored);
        }

        // Historical stored-snapshot requests stay historical. Falling back
        // to an arbitrary option-chain date would create unbounded cache keys
        // and silently change the requested universe.
        if ($requestedDate !== null) {
            return response()->json($this->emptyFallback($limit, $days, 'stored_snapshot_missing'));
        }

        return response()->json($this->fallbackRanking($limit, $days));
    }

    private function storedSnapshot(int $limit, int $requestedDays, ?string $requestedDate): ?array
    {
        $base = DB::table('hot_option_symbols');

        if ($requestedDate !== null) {
            $tradeDate = $requestedDate;
        } else {
            $tradeDate = $base->max('trade_date');
        }

        if (! $tradeDate) {
            return null;
        }

        $snapshot = DB::table('hot_option_symbols')->whereDate('trade_date', $tradeDate);
        $availableCount = (clone $snapshot)->count();
        if ($availableCount === 0) {
            return null;
        }

        $rows = $snapshot->orderBy('rank')->limit($limit)->get();
        $first = $rows->first();
        $source = $first->source ?? null;
        $payload = $this->payload($first->payload ?? null);
        $windowStart = $this->dateValue($payload['window_start'] ?? null);
        $windowEnd = $this->dateValue($payload['window_end'] ?? null);
        $effectiveDays = $windowStart && $windowEnd
            ? Carbon::parse($windowStart)->diffInDays(Carbon::parse($windowEnd)) + 1
            : null;

        $items = $rows->map(fn ($row) => [
            'symbol' => $row->symbol,
            'rank' => $row->rank,
            'total_volume' => $row->total_volume,
            'put_call' => $row->put_call_ratio,
            'last_price' => $row->last_price,
        ])->values();
        $ratios = $items->pluck('put_call')->filter(fn ($value) => $value !== null);

        return [
            'trade_date' => $tradeDate,
            'limit' => $limit,
            'source' => 'hot_option_symbols',
            'symbols' => $items->pluck('symbol')->all(),
            'items' => $items->all(),
            'meta' => [
                'count' => $items->count(),
                'available_count' => $availableCount,
                'source' => $source,
                'total_vol' => (int) $items->sum('total_volume'),
                'avg_pcr' => $ratios->isEmpty() ? null : $ratios->avg(),
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'requested_days' => $requestedDays,
                'effective_days' => $effectiveDays,
                'lookback_control_applied' => false,
                'scope' => $source === 'steadyapi' ? 'source_defined_session' : 'stored_snapshot',
            ],
        ];
    }

    private function fallbackRanking(int $limit, int $days): array
    {
        $latestDate = DB::table('option_chain_data')->max('data_date');
        if (! $latestDate) {
            return $this->emptyFallback($limit, $days, 'no_option_chain_data');
        }

        $end = Carbon::parse($latestDate);
        $start = $end->copy()->subDays($days - 1)->toDateString();
        $endDate = $end->toDateString();
        $cacheKey = "hot-options:fallback:v4:{$endDate}:{$days}:top".self::MAX_RESULTS;

        /** @var array<int, array<string, mixed>> $canonical */
        $canonical = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $endDate): array {
            return DB::table('option_chain_data as o')
                ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
                ->whereBetween('o.data_date', [$start, $endDate])
                ->selectRaw("\n                    e.symbol,\n                    SUM(COALESCE(o.open_interest, 0)) as total_open_interest,\n                    SUM(COALESCE(o.volume, 0)) as total_volume,\n                    SUM(CASE WHEN LOWER(o.option_type) = 'call' THEN COALESCE(o.volume, 0) ELSE 0 END) as call_volume,\n                    SUM(CASE WHEN LOWER(o.option_type) = 'put' THEN COALESCE(o.volume, 0) ELSE 0 END) as put_volume,\n                    MAX(CASE WHEN o.data_date = ? THEN o.underlying_price ELSE NULL END) as last_price\n                ", [$endDate])
                ->groupBy('e.symbol')
                ->orderByDesc('total_open_interest')
                ->orderByDesc('total_volume')
                ->limit(self::MAX_RESULTS)
                ->get()
                ->values()
                ->map(function ($row, $index): array {
                    $callVolume = (int) $row->call_volume;

                    return [
                        'symbol' => \App\Support\Symbols::canon($row->symbol),
                        'rank' => $index + 1,
                        'total_volume' => (int) $row->total_volume,
                        'put_call' => $callVolume > 0 ? (int) $row->put_volume / $callVolume : null,
                        'last_price' => $row->last_price !== null ? (float) $row->last_price : null,
                    ];
                })
                ->all();
        });

        $items = collect($canonical)->take($limit)->values();
        $ratios = $items->pluck('put_call')->filter(fn ($value) => $value !== null);

        return [
            'trade_date' => $endDate,
            'limit' => $limit,
            'source' => 'fallback_db',
            'symbols' => $items->pluck('symbol')->all(),
            'items' => $items->all(),
            'meta' => [
                'count' => $items->count(),
                'available_count' => count($canonical),
                'source' => 'polygon_eod_fallback',
                'total_vol' => (int) $items->sum('total_volume'),
                'avg_pcr' => $ratios->isEmpty() ? null : $ratios->avg(),
                'window_start' => $start,
                'window_end' => $endDate,
                'requested_days' => $days,
                'effective_days' => $days,
                'lookback_control_applied' => true,
                'scope' => 'local_chain_fallback',
                'ranking' => 'open_interest_then_volume',
            ],
        ];
    }

    private function emptyFallback(int $limit, int $days, string $reason): array
    {
        return [
            'trade_date' => null,
            'limit' => $limit,
            'source' => 'fallback_db',
            'symbols' => [],
            'items' => [],
            'meta' => [
                'count' => 0,
                'available_count' => 0,
                'source' => 'polygon_eod_fallback',
                'total_vol' => null,
                'avg_pcr' => null,
                'window_start' => null,
                'window_end' => null,
                'requested_days' => $days,
                'effective_days' => null,
                'lookback_control_applied' => true,
                'scope' => 'local_chain_fallback',
                'reason' => $reason,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
