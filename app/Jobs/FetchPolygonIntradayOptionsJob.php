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

class FetchPolygonIntradayOptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(public array $symbols) {}

    public function handle(): void
    {
        $tradeDate = $this->tradingDate(now());

        Log::debug('FetchPolygonIntradayOptionsJob.start', [
            'symbols'    => $this->symbols,
            'trade_date' => $tradeDate,
        ]);

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            Log::debug('FetchPolygonIntradayOptionsJob.symbolLoop', [
                'raw'    => $raw,
                'symbol' => $symbol,
            ]);

            $snap = app(\App\Support\PolygonClient::class)->intradayOptionVolumes($symbol);

            Log::debug('FetchPolygonIntradayOptionsJob.afterIntraday', [
                'symbol'         => $symbol,
                'snap_is_null'   => $snap === null,
                'totals'         => $snap['totals']    ?? null,
                'bucket_count'   => isset($snap['by_strike']) ? count($snap['by_strike']) : null,
                'first_bucket'   => $snap['by_strike'][0] ?? null,
            ]);

            if (!$snap) {
                Log::warning('FetchPolygonIntradayOptionsJob.noSnap', [
                    'symbol' => $symbol,
                ]);
                continue;
            }

            DB::transaction(function () use ($symbol, $tradeDate, $snap) {
                $now = now();
                $capturedAt = \Carbon\Carbon::parse($snap['asof'] ?? now('America/New_York'))
                    ->setTimezone('UTC');

                $ingestor = app(\App\Services\IntradayOptionVolumeIngestor::class);
                $requestId = (string)($snap['request_id'] ?? '');

                foreach ($snap['contracts'] as $contract) {
                    try {
                        $ingestor->ingest($contract, $requestId, $capturedAt);
                    } catch (\Throwable $e) {
                        Log::warning('FetchPolygonIntradayOptionsJob.ingestError', [
                            'symbol' => $symbol,
                            'err'    => $e->getMessage(),
                        ]);
                    }
                }

                $totCall = (int)($snap['totals']['call_vol'] ?? 0);
                $totPut  = (int)($snap['totals']['put_vol'] ?? 0);
                $totPrem = $snap['totals']['premium'] ?? null;

                Log::debug('FetchPolygonIntradayOptionsJob.totalsRow', [
                    'symbol'      => $symbol,
                    'trade_date'  => $tradeDate,
                    'totCall'     => $totCall,
                    'totPut'      => $totPut,
                    'totPrem'     => $totPrem,
                    'asof'        => $snap['asof'] ?? null,
                ]);

                DB::table('option_live_counters')->upsert(
                    [[
                        'symbol'      => $symbol,
                        'trade_date'  => $tradeDate,
                        'exp_date'    => null,
                        'strike'      => null,
                        'option_type' => null,
                        'volume'      => $totCall + $totPut,
                        'premium_usd' => $totPrem,
                        'asof'        => $snap['asof'] ?? null,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]],
                    ['symbol','trade_date','exp_date','strike','option_type'],
                    ['volume','premium_usd','asof','updated_at']
                );

                $rows = [];

                foreach ($snap['by_strike'] as $idx => $row) {
                    $K       = isset($row['strike']) ? (float)$row['strike'] : null;
                    $expDate = $row['exp_date'] ?? null;

                    $callVol   = (int)($row['call_vol']   ?? 0);
                    $putVol    = (int)($row['put_vol']    ?? 0);
                    $callPrem  = $row['call_prem'] ?? null;
                    $putPrem   = $row['put_prem']  ?? null;

                    Log::debug('FetchPolygonIntradayOptionsJob.bucketRow', [
                        'symbol'     => $symbol,
                        'idx'        => $idx,
                        'strike'     => $K,
                        'exp_date'   => $expDate,
                        'callVol'    => $callVol,
                        'putVol'     => $putVol,
                        'callPrem'   => $callPrem,
                        'putPrem'    => $putPrem,
                        'asof'       => $snap['asof'] ?? null,
                    ]);

                    if ($K && $expDate) {
                        if ($callVol > 0) {
                            $rows[] = [
                                'symbol'      => $symbol,
                                'trade_date'  => $tradeDate,
                                'exp_date'    => $expDate,
                                'strike'      => $K,
                                'option_type' => 'call',
                                'volume'      => $callVol,
                                'premium_usd' => $callPrem,
                                'asof'        => $snap['asof'] ?? null,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ];
                        }

                        if ($putVol > 0) {
                            $rows[] = [
                                'symbol'      => $symbol,
                                'trade_date'  => $tradeDate,
                                'exp_date'    => $expDate,
                                'strike'      => $K,
                                'option_type' => 'put',
                                'volume'      => $putVol,
                                'premium_usd' => $putPrem,
                                'asof'        => $snap['asof'] ?? null,
                                'created_at'  => $now,
                                'updated_at'  => $now,
                            ];
                        }
                    }
                }

                Log::debug('FetchPolygonIntradayOptionsJob.beforeUpsertRows', [
                    'symbol'        => $symbol,
                    'rows_count'    => count($rows),
                    'first_row'     => $rows[0] ?? null,
                    'last_row'      => $rows[count($rows)-1] ?? null,
                ]);

                if (!empty($rows)) {
                    DB::table('option_live_counters')->upsert(
                        $rows,
                        ['symbol','trade_date','exp_date','strike','option_type'],
                        ['volume','premium_usd','asof','updated_at']
                    );
                }
            });
        }

        Log::debug('FetchPolygonIntradayOptionsJob.done');
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
