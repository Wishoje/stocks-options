<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\Market;

class FetchPolygonIntradayOptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    // ❌ REMOVE this line:
    // public string $queue = 'intraday';

    public function __construct(public array $symbols)
    {
        // Set default queue for this job
        $this->onQueue('intraday');
    }

    public function handle(): void
    {
        $tradeDate = $this->tradingDate(now());

        // Log::debug('FetchPolygonIntradayOptionsJob.start', [
        //     'symbols'    => $this->symbols,
        //     'trade_date' => $tradeDate,
        // ]);

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            // Log::debug('FetchPolygonIntradayOptionsJob.symbolLoop', [
            //     'raw'    => $raw,
            //     'symbol' => $symbol,
            // ]);

            // Pull by expiry chunks to keep pagination shallow
            $expiries = DB::table('option_expirations')
                ->where('symbol', $symbol)
                ->whereDate('expiration_date', '>=', $tradeDate)
                ->orderBy('expiration_date')
                ->limit(8)
                ->pluck('expiration_date')
                ->map(fn ($d) => substr($d, 0, 10))
                ->all();

            if (empty($expiries)) {
                $expiries = [null]; // fallback: all expiries
            }

            $totCallAll = 0; $totPutAll = 0; $totPremAll = 0.0;
            $rowsAll = [];
            $lastCapturedAt = null;
            $requestId = null;

            foreach ($expiries as $expiry) {
                $snap = app(\App\Support\PolygonClient::class)->intradayOptionVolumes($symbol, $expiry);

                // Log::debug('FetchPolygonIntradayOptionsJob.afterIntraday', [...]);
                if (!$snap) {
                    Log::warning('FetchPolygonIntradayOptionsJob.noSnap', [
                        'symbol' => $symbol,
                        'expiry' => $expiry,
                    ]);
                    continue;
                }

                DB::transaction(function () use ($symbol, $tradeDate, $snap, $expiry, &$totCallAll, &$totPutAll, &$totPremAll, &$rowsAll, &$lastCapturedAt, &$requestId) {
                    $now = now();
                    $capturedAt = \Carbon\Carbon::parse($snap['asof'] ?? now('America/New_York'))->setTimezone('UTC');
                    $lastCapturedAt = $capturedAt;
                    $requestId = $snap['request_id'] ?? $requestId;

                    $ingestor  = app(\App\Services\IntradayOptionVolumeIngestor::class);

                    foreach ($snap['contracts'] as $contract) {
                        try {
                            $ingestor->ingest($contract, $requestId ?? '', $capturedAt);
                        } catch (\Throwable $e) {
                            Log::warning('FetchPolygonIntradayOptionsJob.ingestError', [
                                'symbol' => $symbol,
                                'expiry' => $expiry,
                                'err'    => $e->getMessage(),
                            ]);
                        }
                    }

                    $totCall = (int)($snap['totals']['call_vol'] ?? 0);
                    $totPut  = (int)($snap['totals']['put_vol'] ?? 0);
                    $totPrem = $snap['totals']['premium'] ?? 0;

                    $totCallAll += $totCall;
                    $totPutAll  += $totPut;
                    $totPremAll += (float)$totPrem;

                    foreach ($snap['by_strike'] as $row) {
                        $K       = isset($row['strike']) ? (float)$row['strike'] : null;
                        $expDate = $row['exp_date'] ?? $expiry;

                        $callVol  = (int)($row['call_vol']   ?? 0);
                        $putVol   = (int)($row['put_vol']    ?? 0);
                        $callPrem = $row['call_prem'] ?? null;
                        $putPrem  = $row['put_prem']  ?? null;

                        if ($K && $expDate) {
                            if ($callVol > 0) {
                                $rowsAll[] = [
                                    'symbol'      => $symbol,
                                    'trade_date'  => $tradeDate,
                                    'exp_date'    => $expDate,
                                    'strike'      => $K,
                                    'option_type' => 'call',
                                    'volume'      => $callVol,
                                    'premium_usd' => $callPrem,
                                    'asof'        => $capturedAt,
                                    'created_at'  => $now,
                                    'updated_at'  => $now,
                                ];
                            }

                            if ($putVol > 0) {
                                $rowsAll[] = [
                                    'symbol'      => $symbol,
                                    'trade_date'  => $tradeDate,
                                    'exp_date'    => $expDate,
                                    'strike'      => $K,
                                    'option_type' => 'put',
                                    'volume'      => $putVol,
                                    'premium_usd' => $putPrem,
                                    'asof'        => $capturedAt,
                                    'created_at'  => $now,
                                    'updated_at'  => $now,
                                ];
                            }
                        }
                    }
                });
            }

            // Write aggregated totals and strikes after all chunks
            if ($lastCapturedAt) {
                $now = now();
                DB::table('option_live_counters')->upsert(
                    [[
                        'symbol'      => $symbol,
                        'trade_date'  => $tradeDate,
                        'exp_date'    => null,
                        'strike'      => null,
                        'option_type' => null,
                        'volume'      => $totCallAll + $totPutAll,
                        'premium_usd' => $totPremAll,
                        'asof'        => $lastCapturedAt,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]],
                    ['symbol','trade_date','exp_date','strike','option_type'],
                    ['volume','premium_usd','asof','updated_at']
                );
            }

            if (!empty($rowsAll)) {
                DB::table('option_live_counters')->upsert(
                    $rowsAll,
                    ['symbol','trade_date','exp_date','strike','option_type'],
                    ['volume','premium_usd','asof','updated_at']
                );
            }

            continue;
        }

        // Log::debug('FetchPolygonIntradayOptionsJob.done');
    }

    protected function tradingDate(\Carbon\Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }
        return $ny->toDateString();
    }
}
