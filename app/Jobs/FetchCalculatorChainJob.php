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

    public $timeout = 90;
    public $tries = 3;

    public function __construct(public string $symbol) {}

    private static function clip(?string $s, int $max = 600): string
    {
        if ($s === null) return '<null>';
        $s = trim($s);
        return strlen($s) > $max ? substr($s, 0, $max) . '…<clipped>' : $s;
    }

    public function handle()
    {
        $symbol = strtoupper($this->symbol);
        $apiKey = env('MASSIVE_API_KEY');
        $base   = rtrim(env('MASSIVE_BASE', 'https://api.massive.com'), '/');

        Log::debug('CalculatorChain.start', ['symbol' => $symbol, 'base' => $base, 'hasKey' => (bool)$apiKey]);

        if (!$apiKey) {
            Log::error('MassiveClient.missingKey', ['job' => 'CalculatorChain']);
            return;
        }

        // Step 1: Underlying price via Unified Snapshot
        $uResp = Http::timeout(10)
            ->withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->get("{$base}/v3/snapshot", ['ticker.any_of' => $symbol, 'limit' => 1]);

        Log::debug('CalculatorChain.underlying.response', [
            'status' => $uResp->status(),
            'ok'     => $uResp->ok(),
            'body'   => self::clip($uResp->body()),
        ]);

        $underlying = $uResp->json('results.0.last_quote.midpoint')
            ?? $uResp->json('results.0.last_trade.price')
            ?? 100;

        Log::info('CalculatorChain.underlying', ['symbol' => $symbol, 'price' => $underlying]);

        // Step 2: Option chain with pagination
        $perPage = 250; // try high once; fallback to 100 if rejected
        $url = "{$base}/v3/snapshot/options/{$symbol}";
        $contracts = [];
        $page = 0;

        while ($url && $page < 50) {
            $page++;

            $request = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}", 'Accept' => 'application/json']);

            $params = ($page === 1)
                ? ['limit' => $perPage, 'sort' => 'strike_price', 'order' => 'asc']
                : [];

            Log::debug('CalculatorChain.page.request', [
                'symbol' => $symbol,
                'page'   => $page,
                'url'    => $url,
                'params' => $params,
            ]);

            $resp = ($page === 1) ? $request->get($url, $params) : $request->get($url);

            // limit fallback on first page
            if ($page === 1 && $resp->status() === 400 && str_contains($resp->body(), "'Limit' failed")) {
                Log::warning('CalculatorChain.limitRejected', [
                    'attempted_limit' => $perPage,
                    'body' => self::clip($resp->body()),
                ]);
                $perPage = 100;
                $params  = ['limit' => $perPage, 'sort' => 'strike_price', 'order' => 'asc'];
                $resp    = $request->get($url, $params);
                Log::debug('CalculatorChain.limitRetry', ['new_limit' => $perPage, 'status' => $resp->status()]);
            }

            Log::debug('CalculatorChain.page.response', [
                'page' => $page,
                'status' => $resp->status(),
                'ok' => $resp->ok(),
                'bodySnippet' => self::clip($resp->body()),
            ]);

            if (!$resp->ok()) {
                Log::warning('CalculatorChain.pageFailed', [
                    'symbol' => $symbol, 'page' => $page,
                    'status' => $resp->status(), 'body' => self::clip($resp->body()),
                ]);
                break;
            }

            $json = $resp->json();
            $batch = $json['results'] ?? [];
            $count = is_array($batch) ? count($batch) : 0;
            Log::debug('CalculatorChain.page.results', ['page' => $page, 'count' => $count]);

            if (!$batch) break;

            $contracts = array_merge($contracts, $batch);

            $next = $json['next_url'] ?? null;
            if ($next && !str_starts_with($next, 'http')) {
                $next = $base . $next;
                Log::debug('CalculatorChain.nextUrl.normalized', ['page' => $page, 'url' => $next]);
            }
            $url = $next;
        }

        Log::info('CalculatorChain.fetchComplete', ['symbol' => $symbol, 'contracts' => count($contracts)]);

        if (empty($contracts)) {
            Log::info('CalculatorChain.noContracts', ['symbol' => $symbol]);
            return;
        }

        $inserts = [];
        $now = now();
        $seen = 0; $kept = 0; $skipped = 0;

        foreach ($contracts as $c) {
            $seen++;
            $details = $c['details'] ?? [];
            $quote   = $c['last_quote'] ?? [];

            $bid = (float)($quote['bid'] ?? 0);
            $ask = (float)($quote['ask'] ?? 0);
            $mid = $quote['midpoint'] ?? (($bid > 0 && $ask > 0) ? ($bid + $ask) / 2 : ($bid ?: $ask ?: 0));

            if (empty($details['strike_price'])) { $skipped++; continue; }

            $inserts[] = [
                'symbol'           => $symbol,
                'ticker'           => $c['ticker'] ?? '',
                'type'             => strtolower($details['contract_type'] ?? 'call'),
                'strike'           => $details['strike_price'],
                'expiry'           => $details['expiration_date'] ?? null,
                'bid'              => round($bid, 2),
                'ask'              => round($ask, 2),
                'mid'              => round((float)$mid, 2),
                'underlying_price' => round((float)$underlying, 2),
                'fetched_at'       => $now,
            ];
            $kept++;
        }

        Log::debug('CalculatorChain.reduceStats', ['seen' => $seen, 'kept' => $kept, 'skipped' => $skipped, 'toUpsert' => count($inserts)]);

        if ($inserts) {
            DB::table('option_snapshots')->upsert(
                $inserts,
                ['ticker', 'fetched_at'],
                ['bid', 'ask', 'mid', 'underlying_price', 'fetched_at']
            );
        }

        Log::info('CalculatorChain.SUCCESS', [
            'symbol'     => $symbol,
            'contracts'  => count($inserts),
            'underlying' => $underlying,
        ]);
    }
}
