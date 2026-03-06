<?php

namespace App\Console\Commands;

use App\Http\Controllers\GexController;
use App\Models\OptionChainData;
use App\Models\OptionExpiration;
use App\Support\Symbols;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuditEodGexCommand extends Command
{
    protected $signature = 'gex:audit-eod
                            {symbol : Symbol to audit}
                            {--timeframe=14d : EOD timeframe (0d,1d,7d,14d,30d,90d)}
                            {--date= : Optional anchor date (YYYY-MM-DD) for DB-side audit cap}
                            {--refresh : Force refresh live /api/gex-levels payload}
                            {--json : Output JSON only}';

    protected $description = 'Audit EOD GEX ingest completeness, strike calculations, and payload consistency.';

    public function handle(GexController $controller): int
    {
        $symbol = Symbols::canon((string) $this->argument('symbol'));
        $timeframe = strtolower(trim((string) $this->option('timeframe')));
        $asJson = (bool) $this->option('json');
        $forceRefresh = (bool) $this->option('refresh');
        $anchorDateOpt = $this->parseDate((string) $this->option('date'));

        if (!$symbol) {
            $this->error('Invalid symbol.');
            return self::FAILURE;
        }

        $allowed = ['0d', '1d', '7d', '14d', '30d', '90d'];
        if (!in_array($timeframe, $allowed, true)) {
            $this->error('Unsupported timeframe. Use one of: '.implode(', ', $allowed));
            return self::FAILURE;
        }

        $apiResponse = $this->fetchApiPayload($controller, $symbol, $timeframe, $forceRefresh);
        $apiBody = $apiResponse['body'];

        if (($apiResponse['status'] ?? 500) !== 200 || !is_array($apiBody)) {
            $report = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'api_status' => $apiResponse['status'] ?? 500,
                'api_error' => $apiBody['error'] ?? 'Unknown API error',
            ];

            $this->emitReport($report, $asJson);
            return self::FAILURE;
        }

        $expirationDates = array_values(array_filter($apiBody['expiration_dates'] ?? []));
        if (empty($expirationDates)) {
            $report = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'api_status' => 200,
                'api_error' => 'API returned no expiration_dates.',
            ];

            $this->emitReport($report, $asJson);
            return self::FAILURE;
        }

        $expirationIds = OptionExpiration::query()
            ->where('symbol', $symbol)
            ->whereIn('expiration_date', $expirationDates)
            ->pluck('id', 'expiration_date');

        $auditCapDate = $anchorDateOpt
            ?? $this->parseDate((string) ($apiBody['data_date'] ?? ''))
            ?? DB::table('option_chain_data')->max('data_date');

        if (!$auditCapDate) {
            $report = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'api_status' => 200,
                'api_error' => 'No audit cap date could be resolved from DB or payload.',
            ];

            $this->emitReport($report, $asJson);
            return self::FAILURE;
        }

        $latestDates = $this->latestSnapshotDates($expirationIds->values()->all(), $auditCapDate);
        $todayData = OptionChainData::query()
            ->joinSub($latestDates, 'ld', function ($join) {
                $join->on('option_chain_data.expiration_id', '=', 'ld.expiration_id')
                    ->on('option_chain_data.data_date', '=', 'ld.max_date');
            })
            ->whereIn('option_chain_data.expiration_id', $expirationIds->values()->all())
            ->get();

        if ($todayData->isEmpty()) {
            $report = [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'api_status' => 200,
                'api_error' => 'No option_chain_data rows matched the latest audit snapshot dates.',
            ];

            $this->emitReport($report, $asJson);
            return self::FAILURE;
        }

        $recomputed = $this->recomputeStrikePayload(
            $todayData,
            $expirationIds->values()->all()
        );

        $payloadRows = collect($apiBody['strike_data'] ?? []);
        $comparison = $this->compareStrikeData($payloadRows, collect($recomputed['strike_data']));
        $expirySummary = $this->expirationSummary(
            $symbol,
            $expirationIds,
            $latestDates,
            $auditCapDate
        );

        $fetchMeta = Cache::get("eod:fetch-meta:{$symbol}:{$auditCapDate}");

        $report = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'audit_cap_date' => $auditCapDate,
            'api' => [
                'status' => $apiResponse['status'],
                'data_date' => $apiBody['data_date'] ?? null,
                'date_prev' => $apiBody['date_prev'] ?? null,
                'date_prev_gap_trading_days' => $apiBody['date_prev_gap_trading_days'] ?? null,
                'date_prev_is_stale' => $apiBody['date_prev_is_stale'] ?? null,
                'date_prev_week' => $apiBody['date_prev_week'] ?? null,
                'expiration_dates' => $expirationDates,
                'strike_rows' => count($apiBody['strike_data'] ?? []),
                'call_oi_total' => $apiBody['call_open_interest_total'] ?? null,
                'put_oi_total' => $apiBody['put_open_interest_total'] ?? null,
                'call_vol_total' => $apiBody['call_volume_total'] ?? null,
                'put_vol_total' => $apiBody['put_volume_total'] ?? null,
            ],
            'db' => [
                'latest_global_data_date' => DB::table('option_chain_data')->max('data_date'),
                'selected_snapshot_dates' => $expirySummary['selected_snapshot_dates'],
                'per_expiration' => $expirySummary['per_expiration'],
                'fetch_meta' => is_array($fetchMeta) ? $fetchMeta : null,
            ],
            'recomputed' => [
                'data_date' => $recomputed['data_date'],
                'date_prev' => $recomputed['date_prev'],
                'date_prev_week' => $recomputed['date_prev_week'],
                'strike_rows' => count($recomputed['strike_data']),
                'call_oi_total' => $recomputed['call_open_interest_total'],
                'put_oi_total' => $recomputed['put_open_interest_total'],
                'call_vol_total' => $recomputed['call_volume_total'],
                'put_vol_total' => $recomputed['put_volume_total'],
            ],
            'comparison' => $comparison,
            'top_levels' => [
                'api_call_resistance' => $apiBody['call_resistance'] ?? null,
                'api_put_support' => $apiBody['put_support'] ?? null,
                'api_hvl' => $apiBody['hvl'] ?? null,
                'recomputed_call_resistance' => $recomputed['call_resistance'],
                'recomputed_put_support' => $recomputed['put_support'],
                'recomputed_hvl' => $recomputed['hvl'],
            ],
        ];

        $this->emitReport($report, $asJson);

        return (($comparison['summary']['mismatch_rows'] ?? 0) > 0) ? self::FAILURE : self::SUCCESS;
    }

    private function fetchApiPayload(
        GexController $controller,
        string $symbol,
        string $timeframe,
        bool $refresh
    ): array {
        $request = Request::create('/api/gex-levels', 'GET', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'refresh' => $refresh ? 1 : 0,
        ]);

        $response = $controller->getGexLevels($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true),
        ];
    }

    private function latestSnapshotDates(array $expirationIds, string $anchorDate)
    {
        $dateCandidates = OptionChainData::query()
            ->select(
                'expiration_id',
                'data_date',
                DB::raw("SUM(CASE WHEN option_type = 'call' THEN 1 ELSE 0 END) as call_rows_n"),
                DB::raw("SUM(CASE WHEN option_type = 'put' THEN 1 ELSE 0 END) as put_rows_n")
            )
            ->whereIn('expiration_id', $expirationIds)
            ->whereDate('data_date', '<=', $anchorDate)
            ->groupBy('expiration_id', 'data_date');

        $balancedDates = DB::query()
            ->fromSub($dateCandidates, 'dc')
            ->select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->where('call_rows_n', '>', 0)
            ->where('put_rows_n', '>', 0)
            ->groupBy('expiration_id');

        $fallbackDates = OptionChainData::query()
            ->select('expiration_id', DB::raw('MAX(data_date) as max_date'))
            ->whereIn('expiration_id', $expirationIds)
            ->whereDate('data_date', '<=', $anchorDate)
            ->groupBy('expiration_id');

        return DB::query()
            ->fromSub($fallbackDates, 'fb')
            ->leftJoinSub($balancedDates, 'bd', function ($join) {
                $join->on('fb.expiration_id', '=', 'bd.expiration_id');
            })
            ->select('fb.expiration_id', DB::raw('COALESCE(bd.max_date, fb.max_date) as max_date'));
    }

    private function recomputeStrikePayload(Collection $todayData, array $expirationIds): array
    {
        $callOI = (int) $todayData->where('option_type', 'call')->sum('open_interest');
        $putOI = (int) $todayData->where('option_type', 'put')->sum('open_interest');
        $callVol = (int) $todayData->where('option_type', 'call')->sum('volume');
        $putVol = (int) $todayData->where('option_type', 'put')->sum('volume');

        $strikesRaw = [];
        foreach ($todayData as $opt) {
            $strike = (float) $opt->strike;
            $strikeKey = $this->strikeKey($strike);
            $spot = (float) ($opt->underlying_price ?? 0);
            $spotSq = $spot > 0 ? $spot * $spot : 1.0;
            $gex = (float) ($opt->gamma ?? 0) * (float) ($opt->open_interest ?? 0) * 100.0 * $spotSq;

            if (($opt->option_type ?? null) === 'call') {
                $strikesRaw[$strikeKey]['strike'] = $strike;
                $strikesRaw[$strikeKey]['call_gex'] = ($strikesRaw[$strikeKey]['call_gex'] ?? 0.0) + $gex;
            } else {
                $strikesRaw[$strikeKey]['strike'] = $strike;
                $strikesRaw[$strikeKey]['put_gex'] = ($strikesRaw[$strikeKey]['put_gex'] ?? 0.0) + $gex;
            }
        }

        $strikeList = [];
        foreach ($strikesRaw as $g) {
            $strikeList[] = [
                'strike' => (float) ($g['strike'] ?? 0.0),
                'net_gex' => (float) (($g['call_gex'] ?? 0.0) - ($g['put_gex'] ?? 0.0)),
                'call_gex' => (float) ($g['call_gex'] ?? 0.0),
                'put_gex' => (float) ($g['put_gex'] ?? 0.0),
            ];
        }

        usort($strikeList, fn ($a, $b) => $a['strike'] <=> $b['strike']);

        $latestDate = (string) $todayData->max('data_date');
        $prevDate = OptionChainData::query()
            ->whereIn('expiration_id', $expirationIds)
            ->where('data_date', '<', $latestDate)
            ->max('data_date');

        $weekCutoff = Carbon::parse($latestDate)->subWeek()->toDateString();
        $prevWeekDate = OptionChainData::query()
            ->whereIn('expiration_id', $expirationIds)
            ->where('data_date', '<=', $weekCutoff)
            ->max('data_date');

        $dayAgo = $this->fetchPriorGroupedByStrike($expirationIds, $prevDate);
        $weekAgo = $this->fetchPriorGroupedByStrike($expirationIds, $prevWeekDate);

        $fullStrike = [];
        foreach ($strikeList as $row) {
            $strike = $row['strike'];

            $curCallOi = (int) $todayData->where('strike', $strike)->where('option_type', 'call')->sum('open_interest');
            $curPutOi = (int) $todayData->where('strike', $strike)->where('option_type', 'put')->sum('open_interest');
            $curCallVol = (int) $todayData->where('strike', $strike)->where('option_type', 'call')->sum('volume');
            $curPutVol = (int) $todayData->where('strike', $strike)->where('option_type', 'put')->sum('volume');

            $pd = $dayAgo->get($this->strikeKey($strike), collect());
            $pCall = $pd->firstWhere('option_type', 'call');
            $pPut = $pd->firstWhere('option_type', 'put');

            $pw = $weekAgo->get($this->strikeKey($strike), collect());
            $wCall = $pw->firstWhere('option_type', 'call');
            $wPut = $pw->firstWhere('option_type', 'put');

            $fullStrike[] = [
                'strike' => $strike,
                'net_gex' => $row['net_gex'],
                'call_gex' => $row['call_gex'],
                'put_gex' => $row['put_gex'],
                'call_oi_delta' => $curCallOi - (int) ($pCall->oi ?? 0),
                'put_oi_delta' => $curPutOi - (int) ($pPut->oi ?? 0),
                'call_vol_delta' => $curCallVol - (int) ($pCall->vol ?? 0),
                'put_vol_delta' => $curPutVol - (int) ($pPut->vol ?? 0),
                'call_oi_wow' => $curCallOi - (int) ($wCall->oi ?? 0),
                'put_oi_wow' => $curPutOi - (int) ($wPut->oi ?? 0),
                'call_vol_wow' => $curCallVol - (int) ($wCall->vol ?? 0),
                'put_vol_wow' => $curPutVol - (int) ($wPut->vol ?? 0),
            ];
        }

        return [
            'data_date' => $latestDate,
            'date_prev' => $prevDate,
            'date_prev_week' => $prevWeekDate,
            'call_open_interest_total' => $callOI,
            'put_open_interest_total' => $putOI,
            'call_volume_total' => $callVol,
            'put_volume_total' => $putVol,
            'strike_data' => $fullStrike,
            'hvl' => $this->findHvl($strikeList),
            'call_resistance' => $this->topLevel($strikeList, 'call'),
            'put_support' => $this->topLevel($strikeList, 'put'),
        ];
    }

    private function fetchPriorGroupedByStrike(array $expirationIds, ?string $date): Collection
    {
        if (!$date) {
            return collect();
        }

        return OptionChainData::query()
            ->whereIn('expiration_id', $expirationIds)
            ->where('data_date', $date)
            ->select(
                'strike',
                'option_type',
                DB::raw('SUM(open_interest) as oi'),
                DB::raw('SUM(volume) as vol')
            )
            ->groupBy('strike', 'option_type')
            ->get()
            ->groupBy(fn ($row) => $this->strikeKey((float) $row->strike));
    }

    private function expirationSummary(
        string $symbol,
        Collection $expirationIds,
        $latestDates,
        string $auditCapDate
    ): array {
        $selected = DB::query()
            ->fromSub($latestDates, 'ld')
            ->get()
            ->keyBy('expiration_id');

        $perExpiration = [];
        foreach ($expirationIds as $expirationDate => $expirationId) {
            $rows = OptionChainData::query()
                ->where('expiration_id', $expirationId)
                ->whereDate('data_date', '<=', $auditCapDate)
                ->select(
                    'data_date',
                    DB::raw("SUM(CASE WHEN option_type = 'call' THEN 1 ELSE 0 END) as call_rows_n"),
                    DB::raw("SUM(CASE WHEN option_type = 'put' THEN 1 ELSE 0 END) as put_rows_n"),
                    DB::raw('COUNT(DISTINCT strike) as strike_count')
                )
                ->groupBy('data_date')
                ->orderByDesc('data_date')
                ->get();

            $latestAny = $rows->first();
            $latestBalanced = $rows->first(fn ($row) => (int) $row->call_rows_n > 0 && (int) $row->put_rows_n > 0);
            $selectedDate = optional($selected->get($expirationId))->max_date;

            $selRows = OptionChainData::query()
                ->where('expiration_id', $expirationId)
                ->where('data_date', $selectedDate)
                ->select(
                    DB::raw("SUM(CASE WHEN option_type = 'call' THEN open_interest ELSE 0 END) as call_oi"),
                    DB::raw("SUM(CASE WHEN option_type = 'put' THEN open_interest ELSE 0 END) as put_oi"),
                    DB::raw("SUM(CASE WHEN option_type = 'call' THEN volume ELSE 0 END) as call_vol"),
                    DB::raw("SUM(CASE WHEN option_type = 'put' THEN volume ELSE 0 END) as put_vol")
                )
                ->first();

            $perExpiration[] = [
                'expiration_date' => $expirationDate,
                'expiration_id' => $expirationId,
                'latest_any_date' => $latestAny->data_date ?? null,
                'latest_balanced_date' => $latestBalanced->data_date ?? null,
                'selected_date' => $selectedDate,
                'latest_any_call_rows' => (int) ($latestAny->call_rows_n ?? 0),
                'latest_any_put_rows' => (int) ($latestAny->put_rows_n ?? 0),
                'selected_call_oi' => (int) ($selRows->call_oi ?? 0),
                'selected_put_oi' => (int) ($selRows->put_oi ?? 0),
                'selected_call_vol' => (int) ($selRows->call_vol ?? 0),
                'selected_put_vol' => (int) ($selRows->put_vol ?? 0),
                'selected_has_both_sides' => $selectedDate !== null
                    && (($latestBalanced->data_date ?? null) === $selectedDate),
            ];
        }

        return [
            'selected_snapshot_dates' => $selected->mapWithKeys(fn ($row) => [(string) $row->expiration_id => $row->max_date])->all(),
            'per_expiration' => $perExpiration,
        ];
    }

    private function compareStrikeData(Collection $payloadRows, Collection $recomputedRows): array
    {
        $payloadMap = $payloadRows->keyBy(fn ($row) => $this->strikeKey((float) ($row['strike'] ?? 0)));
        $recomputedMap = $recomputedRows->keyBy(fn ($row) => $this->strikeKey((float) ($row['strike'] ?? 0)));
        $keys = $payloadMap->keys()->merge($recomputedMap->keys())->unique()->values();

        $fields = [
            'net_gex',
            'call_gex',
            'put_gex',
            'call_oi_delta',
            'put_oi_delta',
            'call_vol_delta',
            'put_vol_delta',
        ];

        $mismatches = [];
        foreach ($keys as $key) {
            $payload = $payloadMap->get($key);
            $recomputed = $recomputedMap->get($key);

            if (!$payload || !$recomputed) {
                $mismatches[] = [
                    'strike' => $key,
                    'reason' => !$payload ? 'missing_in_api_payload' : 'missing_in_recomputed_series',
                ];
                continue;
            }

            $diffs = [];
            foreach ($fields as $field) {
                $a = (float) ($payload[$field] ?? 0);
                $b = (float) ($recomputed[$field] ?? 0);
                $diff = $a - $b;

                if (!$this->numbersMatch($a, $b)) {
                    $diffs[$field] = [
                        'api' => $a,
                        'recomputed' => $b,
                        'diff' => $diff,
                    ];
                }
            }

            if (!empty($diffs)) {
                $mismatches[] = [
                    'strike' => $key,
                    'reason' => 'value_mismatch',
                    'fields' => $diffs,
                ];
            }
        }

        return [
            'summary' => [
                'api_rows' => $payloadMap->count(),
                'recomputed_rows' => $recomputedMap->count(),
                'mismatch_rows' => count($mismatches),
            ],
            'mismatches' => array_slice($mismatches, 0, 25),
        ];
    }

    private function emitReport(array $report, bool $asJson): void
    {
        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $this->info(sprintf(
            'Audit %s %s (cap=%s)',
            $report['symbol'] ?? 'N/A',
            $report['timeframe'] ?? 'N/A',
            $report['audit_cap_date'] ?? 'n/a'
        ));

        if (isset($report['api_error'])) {
            $this->error((string) $report['api_error']);
            return;
        }

        $api = $report['api'];
        $db = $report['db'];
        $cmp = $report['comparison'];
        $levels = $report['top_levels'];

        $this->line(sprintf(
            'API data_date=%s prev=%s gap=%s stale=%s prev_week=%s strike_rows=%d',
            $api['data_date'] ?? 'n/a',
            $api['date_prev'] ?? 'n/a',
            $api['date_prev_gap_trading_days'] ?? 'n/a',
            !empty($api['date_prev_is_stale']) ? 'yes' : 'no',
            $api['date_prev_week'] ?? 'n/a',
            (int) ($api['strike_rows'] ?? 0)
        ));

        $this->line(sprintf(
            'Recomputed strike_rows=%d mismatches=%d latest_global_data_date=%s',
            (int) ($report['recomputed']['strike_rows'] ?? 0),
            (int) ($cmp['summary']['mismatch_rows'] ?? 0),
            $db['latest_global_data_date'] ?? 'n/a'
        ));

        $this->line(sprintf(
            'Levels API(call=%s put=%s hvl=%s) recomputed(call=%s put=%s hvl=%s)',
            $levels['api_call_resistance'] ?? 'null',
            $levels['api_put_support'] ?? 'null',
            $levels['api_hvl'] ?? 'null',
            $levels['recomputed_call_resistance'] ?? 'null',
            $levels['recomputed_put_support'] ?? 'null',
            $levels['recomputed_hvl'] ?? 'null'
        ));

        $this->line('Per-expiration snapshot selection:');
        foreach ($db['per_expiration'] as $row) {
            $this->line(sprintf(
                '  %s selected=%s latest_any=%s balanced=%s call_rows=%d put_rows=%d both_sides=%s',
                $row['expiration_date'],
                $row['selected_date'] ?? 'null',
                $row['latest_any_date'] ?? 'null',
                $row['latest_balanced_date'] ?? 'null',
                (int) $row['latest_any_call_rows'],
                (int) $row['latest_any_put_rows'],
                $row['selected_has_both_sides'] ? 'yes' : 'no'
            ));
        }

        if (!empty($cmp['mismatches'])) {
            $this->warn('Top mismatches:');
            foreach ($cmp['mismatches'] as $row) {
                $this->line('  strike='.$row['strike'].' reason='.$row['reason']);
            }
        } else {
            $this->info('No strike-level mismatches found between recomputed DB series and API payload.');
        }
    }

    private function topLevel(array $strikeList, string $type): ?float
    {
        if ($type === 'call') {
            $filtered = array_filter($strikeList, fn ($row) => ($row['net_gex'] ?? 0) > 0);
            usort($filtered, fn ($a, $b) => $b['net_gex'] <=> $a['net_gex']);
        } else {
            $filtered = array_filter($strikeList, fn ($row) => ($row['net_gex'] ?? 0) < 0);
            usort($filtered, fn ($a, $b) => abs($b['net_gex']) <=> abs($a['net_gex']));
        }

        return $filtered[0]['strike'] ?? null;
    }

    private function findHvl(array $strikeList): ?float
    {
        for ($i = 0; $i < count($strikeList) - 1; $i++) {
            if (($strikeList[$i]['net_gex'] ?? 0) < 0 && ($strikeList[$i + 1]['net_gex'] ?? 0) >= 0) {
                return $strikeList[$i + 1]['strike'];
            }
        }

        return $strikeList[0]['strike'] ?? null;
    }

    private function numbersMatch(float $a, float $b): bool
    {
        $diff = abs($a - $b);
        if ($diff <= 0.01) {
            return true;
        }

        $scale = max(abs($a), abs($b), 1.0);
        return ($diff / $scale) <= 1.0e-9;
    }

    private function strikeKey(float $strike): string
    {
        return number_format($strike, 2, '.', '');
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, 'America/New_York')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
