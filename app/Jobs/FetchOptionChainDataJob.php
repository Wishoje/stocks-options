<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\OptionExpiration;
use App\Support\EodHealth;
use Carbon\Carbon;

class FetchOptionChainDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var string[] */
    protected array $symbols;

    /** @var int|null Max days out to keep expirations (client-side filter) */
    protected ?int $days;
    /** @var string|null Forced data_date (YYYY-MM-DD) for backfills/repairs */
    protected ?string $targetDate;

    // ----- Tunables (adjust as needed) -----
    /** Keep strikes within ±X% of spot (precision default widened). */
    protected float $strikeBandPct = 2.00;

    /** Minimal activity to keep a row (drop dead contracts quickly). */
    protected int $minKeepOI  = 1;
    protected int $minKeepVol = 1;

    /** Compute Greeks within ±X% of spot (precision default widened). */
    protected float $greeksNearPct = 2.00;

    /** Refetch expiry slices that are missing either call or put side. */
    protected bool $repairPartialExpiries = true;
    protected float $minSideStrikeRatio = 0.35;

    /** Cache guard to prevent duplicate pulls per symbol/day */
    protected int $guardMinutes = 10;

    public function __construct(array $symbols, ?int $days = null, ?string $targetDate = null)
    {
        $this->symbols = array_values(array_unique(array_map(
            static fn($s) => \App\Support\Symbols::canon($s),
            $symbols
        )));
        $this->days = $days;
        $this->targetDate = $targetDate ? substr((string) $targetDate, 0, 10) : null;
    }

    public function handle(): void
    {
        $this->loadRuntimeTunables();

        $nowNy = now('America/New_York');
        $date = $this->targetDate ?: $this->tradingDate($nowNy->copy());
        try {
            $anchorNy = Carbon::createFromFormat('Y-m-d', $date, 'America/New_York')->startOfDay();
        } catch (\Throwable $e) {
            $anchorNy = $nowNy->copy()->startOfDay();
            $date = $this->tradingDate($nowNy->copy());
        }
        $windowStart = $anchorNy->copy()->startOfDay();
        $windowEnd = ($this->days ? $anchorNy->copy()->addDays($this->days) : $anchorNy->copy()->addDays(90))->endOfDay();

        foreach ($this->symbols as $symbol) {
            $context = [
                'symbol' => $symbol,
                'target_date' => $date,
                'days' => $this->days,
            ];

            // Duplicate-work guard per symbol/date.
            $guardKey = "optchain:pulling:{$symbol}:{$date}";
            if (!Cache::add($guardKey, 1, now()->addMinutes($this->guardMinutes))) {
                $meta = array_merge($context, [
                    'status' => 'skipped_duplicate_guard',
                    'guard_key' => $guardKey,
                ]);
                Log::channel('eod_repair')->warning('eod.fetch.symbol.skipped', $meta);
                $this->storeFetchMeta($symbol, $date, $meta);
                continue;
            }

            // Finnhub -> Massive fallback with normalized chain.
            [$spot, $sets, $providerMeta] = $this->fetchChain($symbol);
            $providerSpot = (float) $spot;
            [$spot, $spotSource] = $this->resolveSpot($symbol, $date, $providerSpot);

            if (!$sets || !is_array($sets)) {
                $meta = array_merge($context, [
                    'status' => 'no_provider_data',
                    'spot' => $spot,
                    'spot_source' => $spotSource,
                    'provider_spot' => $providerSpot,
                ], $providerMeta);
                Log::channel('eod_repair')->warning('eod.fetch.symbol.no_provider_data', $meta);
                $this->storeFetchMeta($symbol, $date, $meta);
                continue;
            }

            if ($spot <= 0) {
                Log::warning("No/invalid spot for {$symbol}, skipping greeks and most filtering.");
            }

            // Filter expirations to our configured window.
            $expWindowSets = [];
            foreach ($sets as $set) {
                $expDate = $set['expirationDate'] ?? null;
                if (!$expDate) {
                    continue;
                }

                try {
                    // Treat expiration as end-of-day in ET so same-day expiries are included.
                    $expAtNy = Carbon::createFromFormat('Y-m-d', substr((string) $expDate, 0, 10), 'America/New_York')->endOfDay();
                } catch (\Throwable $e) {
                    continue;
                }

                if ($expAtNy->lt($windowStart) || $expAtNy->gt($windowEnd)) {
                    continue;
                }

                $expWindowSets[] = [
                    'date' => $expDate,
                    'ts' => $expAtNy->timestamp,
                    'opts' => $set['options'] ?? [],
                ];
            }

            if (!$expWindowSets) {
                $meta = array_merge($context, [
                    'status' => 'no_expiries_in_window',
                    'expiries_total' => count($sets),
                    'expiries_in_window' => 0,
                    'spot' => $spot,
                    'spot_source' => $spotSource,
                    'provider_spot' => $providerSpot,
                ], $providerMeta);
                Log::channel('eod_repair')->warning('eod.fetch.symbol.no_expiries_in_window', $meta);
                $this->storeFetchMeta($symbol, $date, $meta);
                continue;
            }

            // Preload/create expiration ids in bulk.
            $expDates = collect($expWindowSets)->pluck('date')->unique()->values();
            $expMap = OptionExpiration::query()
                ->where('symbol', $symbol)
                ->whereIn('expiration_date', $expDates)
                ->pluck('id', 'expiration_date');

            $toInsert = [];
            foreach ($expDates as $d) {
                if (!isset($expMap[$d])) {
                    $toInsert[] = [
                        'symbol' => $symbol,
                        'expiration_date' => $d,
                    ];
                }
            }

            if ($toInsert) {
                DB::table('option_expirations')->insert($toInsert);
                $expMap = OptionExpiration::query()
                    ->where('symbol', $symbol)
                    ->whereIn('expiration_date', $expDates)
                    ->pluck('id', 'expiration_date');
            }

            // Strike filters.
            $useStrikeBand = $spot > 0 && $this->strikeBandPct > 0;
            $minStrike = $useStrikeBand ? $spot * (1 - $this->strikeBandPct) : 0;
            $maxStrike = $useStrikeBand ? $spot * (1 + $this->strikeBandPct) : INF;

            // Greeks near-ATM band.
            $useGreeksBand = $spot > 0 && $this->greeksNearPct > 0;
            $nearMin = $useGreeksBand ? $spot * (1 - $this->greeksNearPct) : 0;
            $nearMax = $useGreeksBand ? $spot * (1 + $this->greeksNearPct) : INF;

            $totalKept = 0;

            foreach ($expWindowSets as $set) {
                $expDate = $set['date'];
                $expTs = $set['ts'];
                $expId = (int) $expMap[$expDate];
                $rows = [];

                foreach (['CALL' => 'call', 'PUT' => 'put'] as $apiSide => $side) {
                    $list = $set['opts'][$apiSide] ?? [];
                    if (!$list) {
                        continue;
                    }

                    foreach ($list as $opt) {
                        $strike = (float) ($opt['strike'] ?? 0);
                        if ($strike <= 0 || ($useStrikeBand && ($strike < $minStrike || $strike > $maxStrike))) {
                            continue;
                        }

                        $oi = (int) ($opt['openInterest'] ?? 0);
                        $vol = (int) ($opt['volume'] ?? 0);
                        if ($oi < $this->minKeepOI && $vol < $this->minKeepVol) {
                            continue;
                        }

                        $ivRaw = $opt['impliedVolatility'] ?? null;
                        $iv = null;
                        if ($ivRaw !== null) {
                            $iv = (float) $ivRaw;
                            if ($iv > 1.0) {
                                $iv = $iv / 100.0;
                            }
                            if ($iv <= 0) {
                                $iv = null;
                            }
                        }

                        $T = $this->timeToExpirationYears($expTs);

                        // Prefer pre-computed Greeks from the API (Polygon provides them).
                        // Fall back to our IV-based computation only when not available.
                        $apiGamma = isset($opt['apiGamma']) && is_numeric($opt['apiGamma']) ? (float) $opt['apiGamma'] : null;
                        $apiDelta = isset($opt['apiDelta']) && is_numeric($opt['apiDelta']) ? (float) $opt['apiDelta'] : null;
                        $apiVega  = isset($opt['apiVega'])  && is_numeric($opt['apiVega'])  ? (float) $opt['apiVega']  : null;

                        $gamma = $delta = $vega = null;
                        if ($apiGamma !== null) {
                            // Use API-provided Greeks — accurate for all strikes including far OTM.
                            $gamma = $apiGamma;
                            $delta = $apiDelta;
                            $vega  = $apiVega;
                        } elseif (
                            $iv !== null
                            && $T > 0
                            && $spot > 0
                            && (!$useGreeksBand || ($strike >= $nearMin && $strike <= $nearMax))
                        ) {
                            $gamma = $this->computeGamma($spot, $strike, $T, $iv, 0.0);
                            $delta = $this->computeDelta($side, $spot, $strike, $T, $iv, 0.0);
                            $vega  = $this->computeVega($spot, $strike, $T, $iv, 0.0);
                        }

                        $rows[] = [
                            'expiration_id' => $expId,
                            'data_date' => $date,
                            'option_type' => $side,
                            'strike' => $strike,
                            'open_interest' => $oi,
                            'volume' => $vol,
                            'gamma' => $gamma,
                            'delta' => $delta,
                            'vega' => $vega,
                            'iv' => $iv,
                            'underlying_price' => $spot ?: null,
                            'data_timestamp' => now(),
                        ];
                    }
                }

                if ($rows) {
                    DB::transaction(function () use ($rows) {
                        DB::table('option_chain_data')->upsert(
                            $rows,
                            ['expiration_id', 'data_date', 'option_type', 'strike'],
                            [
                                'open_interest',
                                'volume',
                                'gamma',
                                'delta',
                                'vega',
                                'iv',
                                'underlying_price',
                                'data_timestamp',
                            ]
                        );
                    });
                    $totalKept += count($rows);
                }
            }

            $meta = array_merge($context, [
                'status' => 'ok',
                'expiries_total' => count($sets),
                'expiries_in_window' => count($expWindowSets),
                'rows_kept' => $totalKept,
                'spot' => $spot,
                'spot_source' => $spotSource,
                'provider_spot' => $providerSpot,
                'strike_band_pct' => $this->strikeBandPct,
                'greeks_near_pct' => $this->greeksNearPct,
                'min_keep_oi' => $this->minKeepOI,
                'min_keep_vol' => $this->minKeepVol,
                'min_side_strike_ratio' => $this->minSideStrikeRatio,
                'repair_partial_expiries' => $this->repairPartialExpiries,
            ], $providerMeta);
            Log::channel('eod_repair')->info('eod.fetch.symbol.ok', $meta);
            $this->storeFetchMeta($symbol, $date, $meta);
        }
    }

    protected function loadRuntimeTunables(): void
    {
        $band = (float) config('services.massive.eod_strike_band_pct', $this->strikeBandPct);
        $greeksBand = (float) config('services.massive.eod_greeks_near_pct', $this->greeksNearPct);
        $minOi = (int) config('services.massive.eod_min_keep_oi', $this->minKeepOI);
        $minVol = (int) config('services.massive.eod_min_keep_vol', $this->minKeepVol);
        $repairPartial = (bool) config(
            'services.massive.eod_chain_repair_partial_expiries',
            $this->repairPartialExpiries
        );
        $minSideStrikeRatio = (float) config('services.massive.eod_min_side_strike_ratio', $this->minSideStrikeRatio);

        $this->strikeBandPct = max(0.0, $band);
        $this->greeksNearPct = max(0.0, $greeksBand);
        $this->minKeepOI = max(0, $minOi);
        $this->minKeepVol = max(0, $minVol);
        $this->repairPartialExpiries = $repairPartial;
        $this->minSideStrikeRatio = max(0.01, min(1.0, $minSideStrikeRatio));
    }

    // ---------- Provider selection + adapters ----------

    /**
     * Try Finnhub first, then Massive.
     *
     * @return array{0:float,1:array,2:array<string,mixed>}
     */
    protected function fetchChain(string $symbol): array
    {
        [$finnhubChain, $finnhubMeta] = $this->fetchFinnhubChain($symbol);
        if ($finnhubChain !== null) {
            return [$finnhubChain[0], $finnhubChain[1], [
                'provider' => 'finnhub',
                'provider_status' => 'ok',
            ]];
        }

        [$massiveChain, $massiveMeta] = $this->fetchMassiveChain($symbol);
        if ($massiveChain !== null) {
            return [$massiveChain[0], $massiveChain[1], [
                'provider' => 'massive',
                'provider_status' => 'ok',
                'finnhub_status' => $finnhubMeta['status'] ?? 'not_attempted',
                'massive_status' => $massiveMeta['status'] ?? 'ok',
                'massive_pages' => $massiveMeta['pages'] ?? null,
                'massive_page_limit' => $massiveMeta['page_limit'] ?? null,
                'pagination_capped' => (bool) ($massiveMeta['pagination_capped'] ?? false),
                'expiry_backfill_requested' => $massiveMeta['expiry_backfill_requested'] ?? 0,
                'expiry_backfill_fetched' => $massiveMeta['expiry_backfill_fetched'] ?? 0,
                'expiry_backfill_incomplete_requested' => $massiveMeta['expiry_backfill_incomplete_requested'] ?? 0,
                'expiry_backfill_incomplete_fetched' => $massiveMeta['expiry_backfill_incomplete_fetched'] ?? 0,
                'expiry_backfill_side_requested' => $massiveMeta['expiry_backfill_side_requested'] ?? 0,
                'expiry_backfill_side_fetched' => $massiveMeta['expiry_backfill_side_fetched'] ?? 0,
            ]];
        }

        return [0.0, [], [
            'provider' => 'none',
            'provider_status' => 'failed',
            'finnhub_status' => $finnhubMeta['status'] ?? 'not_attempted',
            'finnhub_http_status' => $finnhubMeta['http_status'] ?? null,
            'massive_status' => $massiveMeta['status'] ?? 'not_attempted',
            'massive_http_status' => $massiveMeta['http_status'] ?? null,
            'massive_pages' => $massiveMeta['pages'] ?? null,
            'massive_page_limit' => $massiveMeta['page_limit'] ?? null,
            'pagination_capped' => (bool) ($massiveMeta['pagination_capped'] ?? false),
        ]];
    }

    /**
     * Finnhub adapter.
     *
     * @return array{0:?array{0:float,1:array},1:array<string,mixed>}
     */
    protected function fetchFinnhubChain(string $symbol): array
    {
        $apiKey = env('FINNHUB_API_KEY') ?: config('services.finnhub.api_key');
        if (!$apiKey) {
            return [null, ['status' => 'missing_api_key']];
        }

        $url = 'https://finnhub.io/api/v1/stock/option-chain';
        $resp = Http::retry(3, 250, throw: false)
            ->timeout(15)
            ->acceptJson()
            ->get($url, [
                'symbol' => $symbol,
                'token'  => $apiKey,
            ]);

        if ($resp->failed()) {
            return [null, [
                'status' => 'http_error',
                'http_status' => $resp->status(),
            ]];
        }

        $json = $resp->json() ?? [];
        $sets = $json['data'] ?? [];
        if (!is_array($sets) || !$sets) {
            return [null, ['status' => 'empty_payload']];
        }

        $spot = (float) ($json['lastTradePrice'] ?? 0);

        return [[$spot, $sets], [
            'status' => 'ok',
            'set_count' => count($sets),
        ]];
    }

    /**
     * Massive adapter (snapshot/options) normalized to Finnhub-style sets.
     *
     * @return array{0:?array{0:float,1:array},1:array<string,mixed>}
     */
    protected function fetchMassiveChain(string $symbol): array
    {
        $base   = rtrim(config('services.massive.base', 'https://api.massive.com'), '/');
        $key    = config('services.massive.key');
        $mode   = config('services.massive.mode', 'header'); // header|bearer|query
        $header = config('services.massive.header', 'X-API-Key');
        $qparam = config('services.massive.qparam', 'apiKey');

        if (empty($key)) {
            return [null, ['status' => 'missing_api_key']];
        }

        $client = Http::acceptJson()
            ->timeout(20)
            ->retry(2, 300, throw: false);
        if ($mode === 'bearer') {
            $client = $client->withToken($key);
        } elseif ($mode === 'header') {
            $client = $client->withHeaders([$header => $key]);
        }

        $maxPages = max(50, (int) config('services.massive.eod_chain_max_pages', 120));
        $pageLimit = max(1, min(250, (int) config('services.massive.eod_chain_page_limit', 250)));

        $bulk = $this->fetchMassiveContracts(
            $client,
            $base,
            $symbol,
            $mode,
            $qparam,
            (string) $key,
            $pageLimit,
            $maxPages
        );
        $contracts = $bulk['contracts'];
        $spot = (float) ($bulk['spot'] ?? 0.0);
        $pages = (int) ($bulk['meta']['pages'] ?? 0);
        $lastStatus = $bulk['meta']['http_status'] ?? null;
        $paginationCapped = (bool) ($bulk['meta']['pagination_capped'] ?? false);

        if (empty($contracts)) {
            return [null, [
                'status' => $lastStatus ? 'http_error' : 'empty_payload',
                'http_status' => $lastStatus,
                'pages' => $pages,
                'page_limit' => $pageLimit,
                'pagination_capped' => $paginationCapped,
            ]];
        }

        $byExp = $this->normalizeMassiveContracts($contracts);
        $incompleteExpiries = $this->massiveIncompleteExpiries($byExp);
        $knownExpiries = $this->massiveExpiryHints($symbol, array_keys($byExp));
        $forceExpiryRebuild = $paginationCapped;

        // If broad pagination is capped, backfill missing known expiries one by one.
        $expiryBackfillRequested = 0;
        $expiryBackfillFetched = 0;
        $expiryBackfillIncompleteRequested = 0;
        $expiryBackfillIncompleteFetched = 0;
        $expiryBackfillSideRequested = 0;
        $expiryBackfillSideFetched = 0;
        if ($forceExpiryRebuild || ($this->repairPartialExpiries && $incompleteExpiries)) {
            $missingExpiries = array_values(array_diff($knownExpiries, array_keys($byExp)));
            $repairExpiries = $forceExpiryRebuild ? $knownExpiries : $missingExpiries;
            if ($this->repairPartialExpiries && $incompleteExpiries) {
                $repairExpiries = array_merge($repairExpiries, $incompleteExpiries);
                $expiryBackfillIncompleteRequested = count($incompleteExpiries);
            }
            $repairExpiries = array_values(array_unique($repairExpiries));
            $expiryBackfillRequested = count($repairExpiries);
            $maxPagesPerExpiry = max(5, (int) config('services.massive.eod_chain_max_pages_per_expiry', 80));

            foreach ($repairExpiries as $expiry) {
                $wasIncomplete = in_array($expiry, $incompleteExpiries, true);
                $res = $this->fetchMassiveContracts(
                    $client,
                    $base,
                    $symbol,
                    $mode,
                    $qparam,
                    (string) $key,
                    $pageLimit,
                    $maxPagesPerExpiry,
                    $expiry
                );

                $pages += (int) ($res['meta']['pages'] ?? 0);
                $lastStatus = $res['meta']['http_status'] ?? $lastStatus;
                $paginationCapped = $paginationCapped || (bool) ($res['meta']['pagination_capped'] ?? false);

                $contractsForExpiry = $res['contracts'];
                if (!$contractsForExpiry) {
                    continue;
                }

                if ($spot <= 0 && (float) ($res['spot'] ?? 0) > 0) {
                    $spot = (float) $res['spot'];
                }

                $expSet = $this->normalizeMassiveContracts($contractsForExpiry);
                if (isset($expSet[$expiry])) {
                    $byExp[$expiry] = $expSet[$expiry];
                    $expiryBackfillFetched++;
                    if ($this->repairPartialExpiries && $this->massiveExpiryNeedsRepair($byExp[$expiry])) {
                        $repairSides = $forceExpiryRebuild
                            ? ['call', 'put']
                            : $this->massiveRepairSides($byExp[$expiry]);
                        $repairSides = array_values(array_unique($repairSides));
                        $expiryBackfillSideRequested += count($repairSides);

                        foreach ($repairSides as $repairSide) {
                            $sideRes = $this->fetchMassiveContracts(
                                $client,
                                $base,
                                $symbol,
                                $mode,
                                $qparam,
                                (string) $key,
                                $pageLimit,
                                $maxPagesPerExpiry,
                                $expiry,
                                $repairSide
                            );

                            $pages += (int) ($sideRes['meta']['pages'] ?? 0);
                            $lastStatus = $sideRes['meta']['http_status'] ?? $lastStatus;
                            $paginationCapped = $paginationCapped || (bool) ($sideRes['meta']['pagination_capped'] ?? false);

                            $sideContracts = $sideRes['contracts'] ?? [];
                            if (!$sideContracts) {
                                continue;
                            }

                            if ($spot <= 0 && (float) ($sideRes['spot'] ?? 0) > 0) {
                                $spot = (float) $sideRes['spot'];
                            }

                            $sideSet = $this->normalizeMassiveContracts($sideContracts);
                            if (!isset($sideSet[$expiry])) {
                                continue;
                            }

                            $byExp[$expiry] = $this->mergeMassiveExpirySet(
                                $byExp[$expiry] ?? [
                                    'expirationDate' => $expiry,
                                    'options' => ['CALL' => [], 'PUT' => []],
                                ],
                                $sideSet[$expiry]
                            );
                            $expiryBackfillSideFetched++;
                        }
                    }

                    if (($wasIncomplete || $forceExpiryRebuild) && !$this->massiveExpiryNeedsRepair($byExp[$expiry])) {
                        $expiryBackfillIncompleteFetched++;
                    }
                }
            }
        }

        $sets = array_values($byExp);

        if (!$sets) {
            return [null, [
                'status' => 'normalized_empty',
                'pages' => $pages,
                'page_limit' => $pageLimit,
                'pagination_capped' => $paginationCapped,
            ]];
        }

        return [[$spot, $sets], [
            'status' => 'ok',
            'set_count' => count($sets),
            'pages' => $pages,
            'page_limit' => $pageLimit,
            'pagination_capped' => $paginationCapped,
            'expiry_backfill_requested' => $expiryBackfillRequested,
            'expiry_backfill_fetched' => $expiryBackfillFetched,
            'expiry_backfill_incomplete_requested' => $expiryBackfillIncompleteRequested,
            'expiry_backfill_incomplete_fetched' => $expiryBackfillIncompleteFetched,
            'expiry_backfill_side_requested' => $expiryBackfillSideRequested,
            'expiry_backfill_side_fetched' => $expiryBackfillSideFetched,
        ]];
    }

    /**
     * @return array{
     *   contracts:array<int,array<string,mixed>>,
     *   spot:float,
     *   meta:array<string,mixed>
     * }
     */
    protected function fetchMassiveContracts(
        \Illuminate\Http\Client\PendingRequest $client,
        string $base,
        string $symbol,
        string $mode,
        string $qparam,
        string $key,
        int $pageLimit,
        int $maxPages,
        ?string $expiry = null,
        ?string $contractType = null
    ): array {
        $url       = "{$base}/v3/snapshot/options/{$symbol}";
        $cursor    = null;
        $contracts = [];
        $spot      = null;
        $lastStatus = null;
        $pages = 0;
        $contractType = in_array($contractType, ['call', 'put'], true) ? $contractType : null;

        for ($page = 0; $page < $maxPages; $page++) {
            $pages++;
            $reqUrl = $cursor ?: $url;
            $params = [];

            if (!$cursor) {
                $params['limit'] = $pageLimit;
                if ($expiry) {
                    $params['expiration_date'] = $expiry;
                }
                if ($contractType) {
                    $params['contract_type'] = $contractType;
                }
            }

            if (!$cursor && $mode === 'query') {
                $params[$qparam] = $key;
            }

            $resp = $client->get($reqUrl, $params);

            if ($resp->status() === 401) {
                return [
                    'contracts' => [],
                    'spot' => 0.0,
                    'meta' => [
                        'status' => 'unauthorized',
                        'http_status' => 401,
                        'pages' => $pages,
                        'page_limit' => $pageLimit,
                        'pagination_capped' => false,
                        'expiry' => $expiry,
                        'contract_type' => $contractType,
                    ],
                ];
            }

            if ($resp->failed()) {
                $lastStatus = $resp->status();
                break;
            }

            $json  = $resp->json() ?: [];
            $batch = $json['results'] ?? [];
            if (!is_array($batch) || !$batch) {
                break;
            }

            foreach ($batch as $c) {
                $contracts[] = $c;
                if ($spot === null) {
                    $spot = $c['underlying_asset']['price'] ?? null;
                }
            }

            $cursor = $json['next_url'] ?? null;
            if ($cursor && !str_starts_with($cursor, 'http')) {
                $cursor = $base . $cursor;
            }
            if ($cursor && $mode === 'query' && !str_contains($cursor, urlencode($qparam).'=')) {
                $cursor .= (str_contains($cursor, '?') ? '&' : '?')
                    . urlencode($qparam) . '=' . urlencode($key);
            }
            if (!$cursor) {
                break;
            }
        }

        return [
            'contracts' => $contracts,
            'spot' => (float) ($spot ?? 0.0),
            'meta' => [
                'status' => empty($contracts) ? ($lastStatus ? 'http_error' : 'empty_payload') : 'ok',
                'http_status' => $lastStatus,
                'pages' => $pages,
                'page_limit' => $pageLimit,
                'pagination_capped' => (bool) $cursor,
                'expiry' => $expiry,
                'contract_type' => $contractType,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $contracts
     * @return array<string,array<string,mixed>>
     */
    protected function normalizeMassiveContracts(array $contracts): array
    {
        $byExp = [];

        foreach ($contracts as $c) {
            $details = $c['details'] ?? [];
            $session = $c['session'] ?? ($c['day'] ?? []);

            $expRaw = $details['expiration_date'] ?? null;
            if (!$expRaw) {
                continue;
            }

            $expDate = substr((string) $expRaw, 0, 10);
            $strike  = (float) ($details['strike_price'] ?? 0);
            if ($strike <= 0) {
                continue;
            }

            $sideRaw = strtolower((string) ($details['contract_type'] ?? ''));
            $side    = $sideRaw === 'call'
                ? 'CALL'
                : ($sideRaw === 'put' ? 'PUT' : null);
            if (!$side) {
                continue;
            }

            $oi  = (int) ($c['open_interest'] ?? 0);
            $vol = (int) ($session['volume'] ?? 0);

            $iv = $c['implied_volatility'] ?? null;
            if ($iv !== null) {
                $iv = (float) $iv;
                if ($iv > 1.0) {
                    $iv = $iv / 100.0;
                }
                if ($iv <= 0) {
                    $iv = null;
                }
            }

            if (!isset($byExp[$expDate])) {
                $byExp[$expDate] = [
                    'expirationDate' => $expDate,
                    'options' => [
                        'CALL' => [],
                        'PUT' => [],
                    ],
                ];
            }

            // Pass pre-computed Greeks from the API (Polygon provides them directly).
            // We prefer these over our own IV-derived computation because the IV > 1.0
            // conversion in this normalizer is wrong for Polygon's decimal IV format,
            // which causes near-zero gamma for high-IV far-OTM options.
            $apiGreeks = $c['greeks'] ?? [];
            $apiGamma  = isset($apiGreeks['gamma'])  ? (float) $apiGreeks['gamma']  : null;
            $apiDelta  = isset($apiGreeks['delta'])  ? (float) $apiGreeks['delta']  : null;
            $apiVega   = isset($apiGreeks['vega'])   ? (float) $apiGreeks['vega']   : null;

            $byExp[$expDate]['options'][$side][] = [
                'strike'           => $strike,
                'openInterest'     => $oi,
                'volume'           => $vol,
                'impliedVolatility' => $iv,
                'apiGamma'         => $apiGamma,
                'apiDelta'         => $apiDelta,
                'apiVega'          => $apiVega,
            ];
        }

        return $byExp;
    }

    /**
     * @param array<string,array<string,mixed>> $byExp
     * @return array<int,string>
     */
    protected function massiveIncompleteExpiries(array $byExp): array
    {
        $incomplete = [];
        foreach ($byExp as $exp => $set) {
            if ($this->massiveExpiryNeedsRepair($set)) {
                $incomplete[] = (string) $exp;
            }
        }
        sort($incomplete);
        return $incomplete;
    }

    /**
     * @param array<string,mixed> $set
     */
    protected function massiveExpiryNeedsRepair(array $set): bool
    {
        return !$this->massiveExpirySideRatioHealthy($set);
    }

    /**
     * @param array<string,mixed> $set
     */
    protected function massiveExpiryMissingSide(array $set): bool
    {
        $calls = $this->massiveDistinctStrikeCount($set, 'CALL');
        $puts = $this->massiveDistinctStrikeCount($set, 'PUT');
        return $calls === 0 || $puts === 0;
    }

    /**
     * @param array<string,mixed> $set
     */
    protected function massiveExpirySideRatioHealthy(array $set): bool
    {
        $calls = $this->massiveDistinctStrikeCount($set, 'CALL');
        $puts = $this->massiveDistinctStrikeCount($set, 'PUT');

        return EodHealth::sideRatioMeetsThreshold($calls, $puts, $this->minSideStrikeRatio);
    }

    /**
     * @param array<string,mixed> $set
     * @return array<int,string>
     */
    protected function massiveRepairSides(array $set): array
    {
        $repair = [];
        $calls = $this->massiveDistinctStrikeCount($set, 'CALL');
        $puts = $this->massiveDistinctStrikeCount($set, 'PUT');
        if ($calls === 0) {
            $repair[] = 'call';
        }
        if ($puts === 0) {
            $repair[] = 'put';
        }
        if (!empty($repair)) {
            return $repair;
        }

        if (!$this->massiveExpirySideRatioHealthy($set)) {
            $repair[] = $calls < $puts ? 'call' : 'put';
        }

        return $repair;
    }

    /**
     * Count distinct strikes for a normalized expiry side so repair decisions match DB health checks.
     *
     * @param array<string,mixed> $set
     */
    protected function massiveDistinctStrikeCount(array $set, string $side): int
    {
        $options = $set['options'][$side] ?? [];
        if (!is_array($options) || empty($options)) {
            return 0;
        }

        $strikes = [];
        foreach ($options as $option) {
            $strike = isset($option['strike']) ? (float) $option['strike'] : 0.0;
            if ($strike <= 0) {
                continue;
            }

            $strikes[(string) $strike] = true;
        }

        return count($strikes);
    }

    /**
     * Merge one normalized expiry set into another without duplicating sides.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    protected function mergeMassiveExpirySet(array $base, array $incoming): array
    {
        $out = $base;
        $out['expirationDate'] = $incoming['expirationDate'] ?? ($base['expirationDate'] ?? null);
        $out['options'] = [
            'CALL' => !empty($incoming['options']['CALL'] ?? null)
                ? array_values($incoming['options']['CALL'])
                : array_values($base['options']['CALL'] ?? []),
            'PUT' => !empty($incoming['options']['PUT'] ?? null)
                ? array_values($incoming['options']['PUT'])
                : array_values($base['options']['PUT'] ?? []),
        ];
        return $out;
    }

    /**
     * Build hint expiries from known DB expirations so capped bulk pagination can be filled per expiry.
     *
     * @param array<int|string,string> $existingExpiries
     * @return array<int,string>
     */
    protected function massiveExpiryHints(string $symbol, array $existingExpiries): array
    {
        $nowNy = now('America/New_York');
        $windowStart = $nowNy->copy()->startOfDay()->toDateString();
        $windowEnd = ($this->days ? $nowNy->copy()->addDays($this->days) : $nowNy->copy()->addDays(90))
            ->endOfDay()
            ->toDateString();
        $maxHints = max(5, (int) config('services.massive.eod_chain_max_hint_expiries', 40));

        $knownExpiries = OptionExpiration::query()
            ->where('symbol', $symbol)
            ->whereDate('expiration_date', '>=', $windowStart)
            ->whereDate('expiration_date', '<=', $windowEnd)
            ->orderBy('expiration_date')
            ->limit($maxHints)
            ->pluck('expiration_date')
            ->map(static fn ($d) => substr((string) $d, 0, 10))
            ->all();

        $hints = array_values(array_unique(array_merge($existingExpiries, $knownExpiries)));
        sort($hints);
        if (count($hints) > $maxHints) {
            $hints = array_slice($hints, 0, $maxHints);
        }

        return $hints;
    }

    /**
     * Resolve spot with provider-first fallback for EOD ingest.
     *
     * @return array{0:float,1:string} [spot, source]
     */
    protected function resolveSpot(string $symbol, string $targetDate, float $providerSpot): array
    {
        if ($providerSpot > 0) {
            return [$providerSpot, 'provider'];
        }

        $live = (float) (DB::table('underlying_quotes')
            ->where('symbol', $symbol)
            ->where('last_price', '>', 0)
            ->orderByDesc('asof')
            ->value('last_price') ?? 0);
        if ($live > 0) {
            return [$live, 'underlying_quotes'];
        }

        $closeExact = (float) (DB::table('prices_daily')
            ->where('symbol', $symbol)
            ->whereDate('trade_date', $targetDate)
            ->value('close') ?? 0);
        if ($closeExact > 0) {
            return [$closeExact, 'prices_daily_exact'];
        }

        $closePrev = (float) (DB::table('prices_daily')
            ->where('symbol', $symbol)
            ->whereDate('trade_date', '<=', $targetDate)
            ->orderByDesc('trade_date')
            ->value('close') ?? 0);
        if ($closePrev > 0) {
            return [$closePrev, 'prices_daily_prev'];
        }

        $snap = (float) (DB::table('option_snapshots')
            ->where('symbol', $symbol)
            ->whereDate('fetched_at', $targetDate)
            ->where('underlying_price', '>', 0)
            ->orderByDesc('fetched_at')
            ->value('underlying_price') ?? 0);
        if ($snap > 0) {
            return [$snap, 'option_snapshots'];
        }

        return [0.0, 'none'];
    }

    protected function storeFetchMeta(string $symbol, string $date, array $meta): void
    {
        Cache::put(
            "eod:fetch-meta:{$symbol}:{$date}",
            array_merge($meta, [
                'recorded_at' => now()->toIso8601String(),
            ]),
            now()->addDays(7)
        );
    }

    // ---------- Helpers ----------

    protected function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }
        return $ny->toDateString();
    }

    protected function timeToExpirationYears(int $expirationTimestamp): float
    {
        $now            = time();
        $secondsToExp   = max($expirationTimestamp - $now, 0);
        $yearInSeconds  = 365 * 24 * 3600;
        return $secondsToExp / $yearInSeconds;
    }

    protected function normPdf(float $x): float
    {
        return (1.0 / sqrt(2.0 * M_PI)) * exp(-0.5 * $x * $x);
    }

    protected function normCdf(float $x): float
    {
        // Abramowitz & Stegun approximation
        $p  = 0.2316419;
        $b1 = 0.319381530;
        $b2 = -0.356563782;
        $b3 = 1.781477937;
        $b4 = -1.821255978;
        $b5 = 1.330274429;

        $t    = 1.0 / (1.0 + $p * abs($x));
        $nd   = $this->normPdf($x);
        $poly = ($b1 * $t)
            + ($b2 * $t * $t)
            + ($b3 * $t * $t * $t)
            + ($b4 * $t * $t * $t * $t)
            + ($b5 * $t * $t * $t * $t * $t);
        $cdf  = 1.0 - $nd * $poly;

        return ($x >= 0.0) ? $cdf : 1.0 - $cdf;
    }

    protected function computeGamma(float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) {
            return null;
        }

        $d1  = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);

        return $nd1 / ($S * $sigma * sqrt($T));
    }

    protected function computeDelta(string $type, float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) {
            return null;
        }

        $d1  = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $Nd1 = $this->normCdf($d1);

        return $type === 'call'
            ? $Nd1
            : ($type === 'put' ? $Nd1 - 1.0 : null);
    }

    protected function computeVega(float $S, float $K, float $T, float $sigma, float $r = 0.0): ?float
    {
        if ($T <= 0 || $sigma <= 0 || $S <= 0 || $K <= 0) {
            return null;
        }

        $d1  = (log($S / $K) + ($r + 0.5 * $sigma * $sigma) * $T) / ($sigma * sqrt($T));
        $nd1 = $this->normPdf($d1);

        // per 100% vol; multiply by 0.01 if you want per vol point
        return $S * $nd1 * sqrt($T);
    }
}
