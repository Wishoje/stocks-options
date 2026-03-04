<?php

namespace App\Http\Controllers;

use App\Support\EodHealth;
use App\Support\Symbols;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EodHealthController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('EodHealth');
    }

    public function index(Request $request): JsonResponse
    {
        $targetDate = $this->resolveTargetDate((string) $request->query('date', ''));
        if ($targetDate === null) {
            return response()->json(['error' => 'Invalid date. Use YYYY-MM-DD.'], 422);
        }

        $thresholds = EodHealth::resolveThresholds(
            (string) $request->query('profile', 'broad'),
            $request->query('min_expirations'),
            $request->query('min_strikes'),
            $request->query('min_strike_ratio'),
        );

        $symbols = $this->resolveSymbolUniverse((string) $request->query('symbols', ''));
        if (empty($symbols)) {
            return response()->json([
                'date' => $targetDate,
                'latest_available_date' => DB::table('option_chain_data')->max('data_date'),
                'thresholds' => $thresholds,
                'summary' => [
                    'total' => 0,
                    'covered' => 0,
                    'missing' => 0,
                    'alert' => 0,
                    'warn' => 0,
                    'ok' => 0,
                ],
                'items' => [],
            ]);
        }

        $targetStats = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereDate('o.data_date', $targetDate)
            ->whereIn('e.symbol', $symbols)
            ->select(
                'e.symbol',
                DB::raw('COUNT(*) as rows_n'),
                DB::raw('COUNT(DISTINCT o.option_type) as option_types_n'),
                DB::raw('COUNT(DISTINCT o.expiration_id) as expirations_n'),
                DB::raw('COUNT(DISTINCT o.strike) as strikes_n'),
                DB::raw("COUNT(DISTINCT CASE WHEN o.option_type='call' THEN o.strike END) as call_strikes_n"),
                DB::raw("COUNT(DISTINCT CASE WHEN o.option_type='put' THEN o.strike END) as put_strikes_n"),
            )
            ->groupBy('e.symbol')
            ->get()
            ->mapWithKeys(function ($row) {
                $sym = Symbols::canon((string) $row->symbol);
                if ($sym === null || $sym === '') {
                    return [];
                }

                return [$sym => [
                    'rows_n' => (int) $row->rows_n,
                    'option_types_n' => (int) $row->option_types_n,
                    'expirations_n' => (int) $row->expirations_n,
                    'strikes_n' => (int) $row->strikes_n,
                    'call_strikes_n' => (int) $row->call_strikes_n,
                    'put_strikes_n' => (int) $row->put_strikes_n,
                ]];
            });

        $prevDates = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->whereIn('e.symbol', $symbols)
            ->whereDate('o.data_date', '<', $targetDate)
            ->select('e.symbol', DB::raw('MAX(o.data_date) as prev_date'))
            ->groupBy('e.symbol');

        $prevStats = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->joinSub($prevDates, 'p', function ($join) {
                $join->on('e.symbol', '=', 'p.symbol')
                    ->on('o.data_date', '=', 'p.prev_date');
            })
            ->whereIn('e.symbol', $symbols)
            ->select(
                'e.symbol',
                DB::raw('COUNT(DISTINCT o.strike) as prev_strikes_n'),
                DB::raw('MAX(o.data_date) as prev_date'),
            )
            ->groupBy('e.symbol')
            ->get()
            ->mapWithKeys(function ($row) {
                $sym = Symbols::canon((string) $row->symbol);
                if ($sym === null || $sym === '') {
                    return [];
                }
                return [$sym => [
                    'prev_strikes_n' => (int) $row->prev_strikes_n,
                    'prev_date' => (string) $row->prev_date,
                ]];
            });

        $items = [];
        $summary = [
            'total' => count($symbols),
            'covered' => 0,
            'missing' => 0,
            'alert' => 0,
            'warn' => 0,
            'ok' => 0,
        ];

        foreach ($symbols as $sym) {
            $stats = $targetStats[$sym] ?? null;
            $missing = $stats === null;
            $prev = $prevStats[$sym] ?? ['prev_strikes_n' => 0, 'prev_date' => null];
            $prevStrikes = (int) ($prev['prev_strikes_n'] ?? 0);
            $fetchMeta = Cache::get("eod:fetch-meta:{$sym}:{$targetDate}");
            $strikeRatio = (!$missing && $prevStrikes > 0)
                ? round(((int) ($stats['strikes_n'] ?? 0)) / $prevStrikes, 4)
                : null;

            $reasons = $missing
                ? ['no_rows_for_target_date']
                : EodHealth::incompleteReasons($stats, $prevStrikes, $thresholds);

            $status = EodHealth::statusFromReasons($missing, $reasons);
            $severity = match ($status) {
                'missing' => 0,
                'alert' => 1,
                'warn' => 2,
                default => 3,
            };

            if (!$missing) {
                $summary['covered']++;
            }
            $summary[$status]++;

            $items[] = [
                'symbol' => $sym,
                'status' => $status,
                'severity' => $severity,
                'reasons' => $reasons,
                'rows_n' => $stats['rows_n'] ?? 0,
                'expirations_n' => $stats['expirations_n'] ?? 0,
                'strikes_n' => $stats['strikes_n'] ?? 0,
                'call_strikes_n' => $stats['call_strikes_n'] ?? 0,
                'put_strikes_n' => $stats['put_strikes_n'] ?? 0,
                'prev_date' => $prev['prev_date'] ?? null,
                'prev_strikes_n' => $prevStrikes,
                'strike_ratio_vs_prev_day' => $strikeRatio,
                'last_fetch_meta' => is_array($fetchMeta) ? $fetchMeta : null,
            ];
        }

        usort($items, function (array $a, array $b): int {
            return ($a['severity'] <=> $b['severity'])
                ?: strcmp((string) $a['symbol'], (string) $b['symbol']);
        });

        return response()->json([
            'date' => $targetDate,
            'latest_available_date' => DB::table('option_chain_data')->max('data_date'),
            'thresholds' => $thresholds,
            'summary' => $summary,
            'items' => $items,
        ]);
    }

    protected function resolveSymbolUniverse(string $override): array
    {
        if (trim($override) !== '') {
            return collect(explode(',', $override))
                ->map(fn ($s) => Symbols::canon((string) $s))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return DB::table('watchlists')
            ->pluck('symbol')
            ->map(fn ($s) => Symbols::canon((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function resolveTargetDate(string $dateOpt): ?string
    {
        $raw = trim($dateOpt);
        if ($raw === '') {
            $latest = DB::table('option_chain_data')->max('data_date');
            return $latest ? (string) $latest : now('America/New_York')->toDateString();
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y-m-d', $raw, 'America/New_York')->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
