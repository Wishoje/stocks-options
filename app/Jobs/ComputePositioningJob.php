<?php

namespace App\Jobs;

use App\Support\EodSnapshotSelector;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ComputePositioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    protected const DEX_HISTORY_DAYS = 30;
    protected const DEX_FORWARD_DAYS = 90;

    public function __construct(public array $symbols, public ?string $anchorDate = null) {}

    public function handle(): void
    {
        $selector = app(EodSnapshotSelector::class);
        $date = $this->anchorDate ?: $selector->resolvedAnchorDate();

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            $dexExpMap = $this->dexExpiryMap($symbol, $date);
            [$dexSelectedDates, $dexRows] = $this->selectedChainContext($dexExpMap, $date, $selector);

            $gammaExpMap = $this->forwardExpiryMap($symbol, $date);
            [$gammaSelectedDates, $gammaRows] = $this->selectedChainContext($gammaExpMap, $date, $selector);

            if ($dexRows->isEmpty() && $gammaRows->isEmpty()) {
                continue;
            }

            DB::table('dex_by_expiry')
                ->where('symbol', $symbol)
                ->where('data_date', $date)
                ->delete();

            $dexTotalAll = 0.0;

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

                if (!is_finite($dex)) {
                    continue;
                }

                $dexTotalAll += $dex;
                DB::table('dex_by_expiry')->insert([
                    'symbol' => $symbol,
                    'data_date' => $date,
                    'exp_date' => $expDate,
                    'dex_total' => $dex,
                    'source_chain_date' => $dexSelectedDates[$expId]->max_date ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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
                    $netGamma += $gammaNotional;
                    $absGamma += abs($gammaNotional);
                }
            }

            $strength = $absGamma > 0 ? min(1.0, max(0.0, abs($netGamma) / $absGamma)) : null;

            Cache::put("gamma_strength:{$symbol}:{$date}", [
                'date' => $date,
                'strength' => $strength,
                'sign' => ($netGamma >= 0 ? +1 : -1),
                'source_meta' => [
                    'anchor_date' => $date,
                    'selected_snapshot_dates' => $gammaSelectedDates->mapWithKeys(
                        fn ($row, $expirationId) => [$expirationId => $row->max_date]
                    )->all(),
                ],
            ], now()->addDay());
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

    protected function forwardExpiryMap(string $symbol, string $date): Collection
    {
        return DB::table('option_expirations')
            ->where('symbol', $symbol)
            ->whereDate('expiration_date', '>=', $date)
            ->orderBy('expiration_date')
            ->pluck('id', 'expiration_date');
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
