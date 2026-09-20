<?php

namespace App\Jobs;

use App\Support\CoordinationCache;
use App\Support\EodSnapshotSelector;
use App\Support\PositioningRegimeRepository;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComputePositioningJob extends QueueJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const DEX_HISTORY_DAYS = 30;

    protected const DEX_FORWARD_DAYS = 90;

    public function __construct(public array $symbols, public ?string $anchorDate = null)
    {
        $this->anchorDate = app(EodSnapshotSelector::class)->resolvedAnchorDate($anchorDate);
    }

    public function handle(): void
    {
        $selector = app(EodSnapshotSelector::class);
        $date = (string) $this->anchorDate;

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            $dexExpMap = $this->dexExpiryMap($symbol, $date);
            [$dexSelectedDates, $dexRows] = $this->selectedChainContext($dexExpMap, $date, $selector);

            // The fixed regime window is a subset of the DEX window. Reuse
            // the selected snapshot rows instead of materializing the chain a
            // second time for every symbol.
            $gammaExpMap = $this->regimeExpiryMap($dexExpMap, $date);
            $gammaExpirationIds = array_values($gammaExpMap->all());
            $gammaSelectedDates = $dexSelectedDates->only($gammaExpirationIds);
            $gammaRows = $dexRows->whereIn('expiration_id', $gammaExpirationIds)->values();

            if ($dexRows->isEmpty() && $gammaRows->isEmpty()) {
                continue;
            }

            $dexRowsToInsert = [];

            foreach ($dexExpMap as $expDate => $expId) {
                $slice = $dexRows->where('expiration_id', $expId);
                if ($slice->isEmpty()) {
                    continue;
                }

                $dex = 0.0;
                foreach ($slice as $row) {
                    $oi = (float) ($row->open_interest ?? 0);
                    $delta = (float) ($row->delta ?? 0);
                    if ($oi === 0.0 || $delta === 0.0) {
                        continue;
                    }

                    $dex += $delta * $oi * 100.0;
                }

                if (! is_finite($dex)) {
                    continue;
                }

                $dexRowsToInsert[] = [
                    'symbol' => $symbol,
                    'data_date' => $date,
                    'exp_date' => $expDate,
                    'dex_total' => $dex,
                    'source_chain_date' => $dexSelectedDates[$expId]->max_date ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $spot = (float) round($gammaRows->avg('underlying_price') ?? 0, 6);
            $netGamma = 0.0;
            $absGamma = 0.0;

            if ($spot > 0) {
                foreach ($gammaRows as $row) {
                    $oi = (float) ($row->open_interest ?? 0);
                    $gamma = (float) ($row->gamma ?? 0);
                    if ($oi === 0.0 || $gamma === 0.0) {
                        continue;
                    }

                    $gammaNotional = $gamma * $spot * $spot * $oi * 100.0;
                    $netGamma += $row->option_type === 'call'
                        ? $gammaNotional
                        : -$gammaNotional;
                    $absGamma += abs($gammaNotional);
                }
            }

            $strength = $absGamma > 0 ? min(1.0, max(0.0, abs($netGamma) / $absGamma)) : null;
            $sign = $absGamma <= 0 ? null : ($netGamma <=> 0.0);
            $sourceMeta = [
                'anchor_date' => $date,
                'scope_days' => PositioningRegimeRepository::DEFAULT_SCOPE_DAYS,
                'scope_start_date' => $date,
                'scope_end_date' => Carbon::parse($date, 'America/New_York')
                    ->addDays(PositioningRegimeRepository::DEFAULT_SCOPE_DAYS)
                    ->toDateString(),
                'sign_convention' => 'call_minus_put',
                'expiration_dates' => array_values($gammaExpMap->keys()->all()),
                'selected_snapshot_dates' => $gammaSelectedDates->mapWithKeys(
                    fn ($row, $expirationId) => [$expirationId => $row->max_date]
                )->all(),
            ];
            $regimeFacts = [
                'date' => $date,
                'scope_days' => PositioningRegimeRepository::DEFAULT_SCOPE_DAYS,
                'strength' => $strength,
                'sign' => $sign,
                'net_gamma' => $netGamma,
                'absolute_gamma' => $absGamma,
                'source_meta' => $sourceMeta,
            ];

            DB::transaction(function () use (
                $symbol,
                $date,
                $dexRowsToInsert,
                $strength,
                $sign,
                $netGamma,
                $absGamma,
                $sourceMeta
            ): void {
                DB::table('dex_by_expiry')
                    ->where('symbol', $symbol)
                    ->where('data_date', $date)
                    ->delete();

                if ($dexRowsToInsert !== []) {
                    DB::table('dex_by_expiry')->insert($dexRowsToInsert);
                }

                app(PositioningRegimeRepository::class)->write(
                    $symbol,
                    $date,
                    PositioningRegimeRepository::DEFAULT_SCOPE_DAYS,
                    [
                        'strength' => $strength,
                        'sign' => $sign,
                        'net_gamma' => $netGamma,
                        'absolute_gamma' => $absGamma,
                        'source_meta' => $sourceMeta,
                    ]
                );
            }, 3);

            // Keep the established coordination-cache key during the durable
            // storage rollout. Controllers prefer the database row.
            CoordinationCache::store()->put("gamma_strength:{$symbol}:{$date}", $regimeFacts, now()->addDay());
        }
    }

    protected function dexExpiryMap(string $symbol, string $date): Collection
    {
        $anchor = Carbon::parse($date, 'America/New_York');

        return DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->whereBetween('expiration_date', [
                $anchor->copy()->subDays(self::DEX_HISTORY_DAYS)->toDateString(),
                $anchor->copy()->addDays(self::DEX_FORWARD_DAYS)->toDateString(),
            ])
            ->orderBy('expiration_date')
            ->pluck('id', 'expiration_date');
    }

    protected function regimeExpiryMap(Collection $expirationMap, string $date): Collection
    {
        $anchor = Carbon::parse($date, 'America/New_York');
        $end = $anchor->copy()->addDays(PositioningRegimeRepository::DEFAULT_SCOPE_DAYS)->toDateString();

        return $expirationMap->filter(
            fn ($expirationId, $expirationDate): bool => $expirationDate >= $anchor->toDateString()
                && $expirationDate <= $end
        );
    }

    /**
     * @return array{0: Collection, 1: Collection}
     */
    protected function selectedChainContext(Collection $expMap, string $date, EodSnapshotSelector $selector): array
    {
        if ($expMap->isEmpty()) {
            return [collect(), collect()];
        }

        $expIds = array_values($expMap->toArray());
        $selectedDates = $selector->selectedDateRows($expIds, $date)->keyBy('expiration_id');

        $rows = DB::table('option_chain_data as o')
            ->joinSub($selector->selectedDatesSubquery($expIds, $date), 'ld', fn ($join) => $join
                ->on('o.expiration_id', '=', 'ld.expiration_id')
                ->on('o.data_date', '=', 'ld.max_date'))
            ->whereIn('o.expiration_id', $expIds)
            ->get([
                'o.expiration_id',
                'o.option_type',
                'o.delta',
                'o.gamma',
                'o.open_interest',
                'o.underlying_price',
                'o.strike',
            ]);

        return [$selectedDates, $rows];
    }
}
