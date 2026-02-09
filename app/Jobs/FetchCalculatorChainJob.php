<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchCalculatorChainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;
    public int $tries   = 3;

    public function __construct(public string $symbol, public ?string $expiry = null) {}

    private static function clip(?string $s, int $max = 600): string
    {
        if ($s === null) {
            return '<null>';
        }
        $s = trim($s);
        return strlen($s) > $max ? substr($s, 0, $max) . '…<clipped>' : $s;
    }

    public function handle(): void
    {
        $symbol = strtoupper($this->symbol);
        $targetExpiry = $this->expiry ? substr((string) $this->expiry, 0, 10) : null;
        $apiKey = config('services.massive.key') ?: env('MASSIVE_API_KEY');
        $base   = rtrim((string) config('services.massive.base', 'https://api.massive.com'), '/');
        $mode   = (string) config('services.massive.mode', 'header'); // header|bearer|query
        $header = (string) config('services.massive.header', 'X-API-Key');
        $qparam = (string) config('services.massive.qparam', 'apiKey');

        $makeRequest = function (int $timeout = 30) use ($mode, $header, $apiKey) {
            $req = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders(['Accept' => 'application/json']);

            if ($mode === 'bearer') {
                return $req->withToken($apiKey);
            }

            if ($mode === 'header') {
                return $req->withHeaders([$header => $apiKey]);
            }

            return $req; // query mode
        };

        $authParams = function (array $params = []) use ($mode, $qparam, $apiKey) {
            if ($mode === 'query') {
                $params[$qparam] = $apiKey;
            }
            return $params;
        };

        // Log::debug('CalculatorChain.start', [
        //     'symbol' => $symbol,
        //     'base'   => $base,
        //     'hasKey' => (bool) $apiKey,
        // ]);

        if (!$apiKey) {
            Log::error('MassiveClient.missingKey', ['job' => 'CalculatorChain']);
            return;
        }

        // -----------------------------
        // Step 1: Underlying price
        // -----------------------------
        $uResp = $makeRequest(10)->get(
            "{$base}/v3/snapshot",
            $authParams([
                'ticker.any_of' => $symbol,
                'limit'         => 1,
            ])
        );

        // Log::debug('CalculatorChain.underlying.response', [
        //     'status' => $uResp->status(),
        //     'ok'     => $uResp->ok(),
        //     'body'   => self::clip($uResp->body()),
        // ]);

        $uJson = $uResp->json();
        $u0    = $uJson['results'][0] ?? [];

        // snake + camel for Massive unified snapshot
        $uQuoteSnake = $u0['last_quote'] ?? [];
        $uQuoteCamel = $u0['lastQuote'] ?? [];
        $uQuote      = $uQuoteSnake ?: $uQuoteCamel;

        $uTradeSnake = $u0['last_trade'] ?? [];
        $uTradeCamel = $u0['lastTrade'] ?? [];
        $uTrade      = $uTradeSnake ?: $uTradeCamel;

        $uSession = $u0['session'] ?? [];
        $uDay     = $u0['day'] ?? [];

        $rawU = $uQuote['midpoint']
            ?? $uQuote['mid']
            ?? $uQuote['mark']
            ?? $uTrade['price']
            ?? $uTrade['p']
            ?? ($uSession['close'] ?? null)
            ?? ($uDay['close'] ?? null);

        $underlying = is_numeric($rawU) ? (float) $rawU : 100.0;

        Log::info('CalculatorChain.underlying', [
            'symbol' => $symbol,
            'price'  => $underlying,
        ]);

        // -----------------------------
        // Step 2: Option chain (paginated)
        // -----------------------------
        $perPage   = 250; // first attempt, fallback to 100 if Massive rejects
        $maxPages  = max(50, (int) env('CALC_CHAIN_MAX_PAGES', 150));
        $url       = "{$base}/v3/snapshot/options/{$symbol}";
        $contracts = [];
        $page      = 0;

        while ($url && $page < $maxPages) {
            $page++;

            $request = $makeRequest(30);

            $params = ($page === 1)
                ? [
                    'limit' => $perPage,
                    'sort'  => 'strike_price',
                    'order' => 'asc',
                ]
                : [];
            if ($targetExpiry) {
                $params['expiration_date'] = $targetExpiry;
            }

            // Log::debug('CalculatorChain.page.request', [
            //     'symbol' => $symbol,
            //     'page'   => $page,
            //     'url'    => $url,
            //     'params' => $params,
            // ]);

            $resp = $request->get($url, $authParams($params));

            // limit fallback for page 1
            if (
                $page === 1 &&
                $resp->status() === 400 &&
                str_contains($resp->body(), "'Limit' failed")
            ) {
                Log::warning('CalculatorChain.limitRejected', [
                    'attempted_limit' => $perPage,
                    'body'            => self::clip($resp->body()),
                ]);

                $perPage = 100;
                $params  = [
                    'limit' => $perPage,
                    'sort'  => 'strike_price',
                    'order' => 'asc',
                ];
                if ($targetExpiry) {
                    $params['expiration_date'] = $targetExpiry;
                }
                $resp = $request->get($url, $authParams($params));

                // Log::debug('CalculatorChain.limitRetry', [
                //     'new_limit' => $perPage,
                //     'status'    => $resp->status(),
                // ]);
            }

            // Log::debug('CalculatorChain.page.response', [
            //     'page'        => $page,
            //     'status'      => $resp->status(),
            //     'ok'          => $resp->ok(),
            //     'bodySnippet' => self::clip($resp->body()),
            // ]);

            if (!$resp->ok()) {
                Log::warning('CalculatorChain.pageFailed', [
                    'symbol' => $symbol,
                    'page'   => $page,
                    'status' => $resp->status(),
                    'body'   => self::clip($resp->body()),
                ]);
                break;
            }

            $json  = $resp->json();
            $batch = $json['results'] ?? [];
            $count = is_array($batch) ? count($batch) : 0;

            // Log::debug('CalculatorChain.page.results', [
            //     'page'  => $page,
            //     'count' => $count,
            // ]);

            if (!$batch || !is_array($batch)) {
                break;
            }

            $contracts = array_merge($contracts, $batch);

            // if ($page === 1) {
            //     Log::debug('CalculatorChain.firstContractRaw', [
            //         'symbol' => $symbol,
            //         'sample' => $contracts[0] ?? null,
            //     ]);
            // }

            $next = $json['next_url'] ?? null;
            if ($next && !str_starts_with($next, 'http')) {
                $next = $base . $next;
                // Log::debug('CalculatorChain.nextUrl.normalized', [
                //     'page' => $page,
                //     'url'  => $next,
                // ]);
            }

            $url = $next;
        }

        if ($url) {
            Log::warning('CalculatorChain.paginationCapReached', [
                'symbol' => $symbol,
                'pages' => $page,
                'max_pages' => $maxPages,
            ]);
        }

        Log::info('CalculatorChain.fetchComplete', [
            'symbol'    => $symbol,
            'contracts' => count($contracts),
        ]);

        if (empty($contracts)) {
            Log::info('CalculatorChain.noContracts', ['symbol' => $symbol]);
            return;
        }

        // -----------------------------
        // Step 3: Normalize + upsert
        // -----------------------------
        $inserts = [];
        $now     = now();
        $seen    = 0;
        $kept    = 0;
        $skipped = 0;

        foreach ($contracts as $c) {
            $seen++;

            $details = $c['details'] ?? [];
            if (empty($details['strike_price'])) {
                $skipped++;
                continue;
            }

            $contractType = strtolower((string) ($details['contract_type'] ?? ''));
            if (!in_array($contractType, ['call', 'put'], true)) {
                $skipped++;
                continue;
            }

            $expiryRaw = (string) ($details['expiration_date'] ?? '');
            $expiry = strlen($expiryRaw) >= 10 ? substr($expiryRaw, 0, 10) : null;
            if (!$expiry) {
                $skipped++;
                continue;
            }

            // snake + camel for Massive chain
            $quoteSnake = $c['last_quote'] ?? [];
            $quoteCamel = $c['lastQuote'] ?? [];
            $quote      = $quoteSnake ?: $quoteCamel;

            $tradeSnake = $c['last_trade'] ?? [];
            $tradeCamel = $c['lastTrade'] ?? [];
            $lastTrade  = $tradeSnake ?: $tradeCamel;

            $day = $c['day'] ?? [];
            $fmv = $c['fmv'] ?? null;

            // --- bids/asks from quote (multiple possible keys) ---
            $rawBid = $quote['bid']
                ?? $quote['bid_price']
                ?? $quote['b']
                ?? null;

            $rawAsk = $quote['ask']
                ?? $quote['ask_price']
                ?? $quote['a']
                ?? null;

            $rawMid = $quote['midpoint']
                ?? $quote['mid']
                ?? $quote['mark']
                ?? null;

            $bid = is_numeric($rawBid) ? (float) $rawBid : 0.0;
            $ask = is_numeric($rawAsk) ? (float) $rawAsk : 0.0;

            // primary mid
            if (is_numeric($rawMid)) {
                $mid = (float) $rawMid;
            } elseif ($bid > 0 && $ask > 0) {
                $mid = ($bid + $ask) / 2;
            } else {
                $mid = $bid ?: $ask ?: 0.0;
            }

            // Fallback 1: last trade
            if ($mid == 0.0 && $lastTrade) {
                $lastPrice = $lastTrade['price']
                    ?? $lastTrade['p']
                    ?? null;

                if (is_numeric($lastPrice)) {
                    $mid = (float) $lastPrice;
                }
            }

            // Fallback 2: Fair Market Value
            if ($mid == 0.0 && is_numeric($fmv)) {
                $mid = (float) $fmv;
            }

            // Fallback 3: daily close
            if ($mid == 0.0 && isset($day['close']) && is_numeric($day['close'])) {
                $mid = (float) $day['close'];
            }

            // still useless → skip
            $inserts[] = [
                'symbol'           => $symbol,
                'ticker'           => $c['ticker'] ?? '',
                'type'             => $contractType,
                'strike'           => $details['strike_price'],
                'expiry'           => $expiry,
                'bid'              => round($bid, 2),
                'ask'              => round($ask, 2),
                'mid'              => round($mid, 2),
                'underlying_price' => round((float) $underlying, 2),
                'fetched_at'       => $now,
            ];
            $kept++;
        }

        // Log::debug('CalculatorChain.reduceStats', [
        //     'seen'    => $seen,
        //     'kept'    => $kept,
        //     'skipped' => $skipped,
        //     'toUpsert'=> count($inserts),
        // ]);

        if ($inserts) {
            $chunkSize = 750;
            foreach (array_chunk($inserts, $chunkSize) as $chunk) {
                DB::table('option_snapshots')->upsert(
                    $chunk,
                    ['symbol', 'type', 'strike', 'expiry', 'fetched_at'],
                    ['bid', 'ask', 'mid', 'underlying_price', 'ticker']
                );
            }
        }

        Log::info('CalculatorChain.SUCCESS', [
            'symbol'     => $symbol,
            'contracts'  => count($inserts),
            'underlying' => $underlying,
        ]);
    }
}
