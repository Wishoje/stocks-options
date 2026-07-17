<?php

namespace App\Jobs;

use App\Support\EodSnapshotSelector;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ComputeUAJob extends QueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public function __construct(
        public array $symbols,
        public int   $lookbackDays = 30,
        public float $Z_SCORE_MIN  = 3.0,
        public float $VOL_OI_MIN   = 0.50,
        public int   $VOL_MIN      = 50,
        public int   $MIN_SAMPLES  = 1,
        public int   $MIN_Z_SAMPLES = 3,
        public ?string $anchorDate = null,
    ) {
        $this->anchorDate = app(EodSnapshotSelector::class)->resolvedAnchorDate($anchorDate);
    }

    public function handle(): void
    {
        $selector = app(EodSnapshotSelector::class);
        $anchorDate = (string) $this->anchorDate;

        // cache schema probes once per run (avoid per-query cost)
        $hasMid   = Schema::hasColumn('option_chain_data', 'mid_price');
        $hasLast  = Schema::hasColumn('option_chain_data', 'last_price');
        $hasClose = Schema::hasColumn('option_chain_data', 'close');

        foreach ($this->symbols as $raw) {
            $symbol = \App\Support\Symbols::canon($raw);

            $latest = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol',$symbol)
                ->whereDate('o.data_date', '<=', $anchorDate)
                ->max('o.data_date');

            if (!$latest) continue;

            $expiries = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->where('e.symbol', $symbol)
                ->whereDate('o.data_date', $latest)
                ->distinct()
                ->pluck('e.expiration_date');

            if ($expiries->isEmpty()) continue;

            $payload = [];
            foreach ($expiries as $exp) {
                // build column list safely
                $cols = ['o.strike','o.option_type','o.volume','o.open_interest'];
                if ($hasMid)   $cols[] = 'o.mid_price';
                if ($hasLast)  $cols[] = 'o.last_price';
                if ($hasClose) $cols[] = 'o.close';

                $todayRows = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $exp)
                    ->whereDate('o.data_date', $latest)
                    ->get($cols);

                if ($todayRows->isEmpty()) continue;

                $aggToday = [];
                $oiToday  = [];

                foreach ($todayRows as $r) {
                    $k      = (float)$r->strike;
                    $isCall = ($r->option_type === 'call');
                    $vol    = (int)($r->volume ?? 0);

                    // volumes
                    $aggToday[$k]['call_vol'] = ($aggToday[$k]['call_vol'] ?? 0) + ($isCall ? $vol : 0);
                    $aggToday[$k]['put_vol']  = ($aggToday[$k]['put_vol']  ?? 0) + (!$isCall ? $vol : 0);

                    // OI
                    $oiToday[$k] = ($oiToday[$k] ?? 0) + (int)($r->open_interest ?? 0);

                    // unit price (optional)
                    $px = null;
                    if ($hasMid   && isset($r->mid_price)  && $r->mid_price  !== null) $px = (float)$r->mid_price;
                    elseif ($hasLast && isset($r->last_price) && $r->last_price !== null) $px = (float)$r->last_price;
                    elseif ($hasClose && isset($r->close) && $r->close !== null) $px = (float)$r->close;

                    if ($px !== null) {
                        $px = max($px, 0.01); // floor per-contract price to a penny to avoid near-zero premiums
                        $aggToday[$k]['call_prem'] = ($aggToday[$k]['call_prem'] ?? 0) + ($isCall ? $vol * $px * 100 : 0);
                        $aggToday[$k]['put_prem']  = ($aggToday[$k]['put_prem']  ?? 0) + (!$isCall ? $vol * $px * 100 : 0);
                    }
                }

                foreach ($aggToday as $k=>$v) {
                    $aggToday[$k]['total_vol']  = (int)($v['call_vol'] ?? 0) + (int)($v['put_vol'] ?? 0);
                    $aggToday[$k]['total_prem'] = (array_key_exists('call_prem',$v) || array_key_exists('put_prem',$v))
                        ? (float)($v['call_prem'] ?? 0) + (float)($v['put_prem'] ?? 0)
                        : null;
                }

                // 30d baseline
                $start = Carbon::parse($latest, 'America/New_York')->subDays($this->lookbackDays)->toDateString();
                $hist = DB::table('option_chain_data as o')
                    ->join('option_expirations as e','e.id','=','o.expiration_id')
                    ->where('e.symbol', $symbol)
                    ->whereDate('e.expiration_date', $exp)
                    ->whereDate('o.data_date', '>=', $start)
                    ->whereDate('o.data_date', '<', $latest)
                    ->selectRaw('o.data_date, o.strike, SUM(o.volume) as total_vol')
                    ->groupBy('o.data_date','o.strike')
                    ->get();

                $series = [];
                foreach ($hist as $h) {
                    $k = (float)$h->strike;
                    $series[$k] = $series[$k] ?? [];
                    $series[$k][] = (int)$h->total_vol;
                }

                foreach ($aggToday as $k => $v) {
                    $histArr = $series[$k] ?? [];
                    $historySamples = count($histArr);
                    $mu = null;
                    $sigma = null;
                    $z = null;

                    if ($historySamples >= $this->MIN_SAMPLES && $historySamples > 0) {
                        [$mu, $sigma] = $this->winsorizedStats($histArr);
                        $sigma = max($sigma, 1.0);
                    }

                    if ($historySamples >= $this->MIN_Z_SAMPLES && !is_null($mu) && !is_null($sigma)) {
                        $z = ((int) $v['total_vol'] - $mu) / $sigma;
                    }

                    $oi  = max(1, (int)($oiToday[$k] ?? 0));
                    $rat = (int)$v['total_vol'] / $oi;
                    $confidence = $historySamples >= $this->MIN_Z_SAMPLES ? 'normal' : 'low';

                    if (
                        $v['total_vol'] >= $this->VOL_MIN
                        && (($z !== null && $z >= $this->Z_SCORE_MIN) || $rat >= $this->VOL_OI_MIN)
                    ) {
                        $payload[] = [
                            'symbol'    => $symbol,
                            'data_date' => $latest,
                            'exp_date'  => $exp,
                            'strike'    => $k,
                            'z_score'   => $z !== null ? round($z, 3) : null,
                            'vol_oi'    => round($rat, 4),
                            'meta'      => json_encode([
                                'call_vol'    => (int)($v['call_vol'] ?? 0),
                                'put_vol'     => (int)($v['put_vol']  ?? 0),
                                'total_vol'   => (int)$v['total_vol'],
                                // premium only if computed
                                'premium_usd' => isset($v['total_prem']) && $v['total_prem'] !== null ? round($v['total_prem'], 2) : null,
                                'call_prem'   => isset($v['call_prem']) ? round($v['call_prem'],2) : null,
                                'put_prem'    => isset($v['put_prem'])  ? round($v['put_prem'],2)  : null,
                                'mu'          => $mu !== null ? round($mu, 2) : null,
                                'sigma'       => $sigma !== null ? round($sigma, 2) : null,
                                'history_samples' => $historySamples,
                                'confidence' => $confidence,
                                'baseline_excludes_today' => true,
                            ], JSON_THROW_ON_ERROR),
                            'created_at'=> now(),
                            'updated_at'=> now(),
                        ];
                    }
                }
            }

            usort($payload, function ($a, $b) {
                return ($a['symbol'] <=> $b['symbol'])
                    ?: ($a['data_date'] <=> $b['data_date'])
                    ?: ($a['exp_date'] <=> $b['exp_date'])
                    ?: ($a['strike'] <=> $b['strike']);
            });

            DB::transaction(function () use ($symbol, $latest, $payload): void {
                DB::table('unusual_activity')
                    ->where('symbol', $symbol)
                    ->where('data_date', $latest)
                    ->delete();

                foreach (array_chunk($payload, 200) as $chunk) {
                    $this->upsertUa($chunk);
                }
            }, 3);
        }
    }

    // helpers (unchanged)...
    protected function winsorizedStats(array $xs, float $p=0.05): array {
        sort($xs);
        $n = count($xs);
        $lo = (int)floor($p * $n);
        $hi = (int)ceil((1-$p) * $n) - 1;
        $trim = array_slice($xs, $lo, $hi-$lo+1);
        $mu = array_sum($trim)/max(1,count($trim));
        $var=0; foreach ($trim as $x) { $d=$x-$mu; $var += $d*$d; }
        $sigma = sqrt($var/max(1,count($trim)-1));
        return [$mu,$sigma];
    }

    protected function tradingDate(Carbon $now): string {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) $ny->previousWeekday();
        return $ny->toDateString();
    }

    protected function upsertUa(array $rows): void
    {
        DB::table('unusual_activity')->upsert(
            $rows,
            ['symbol', 'data_date', 'exp_date', 'strike'],
            ['z_score', 'vol_oi', 'meta', 'updated_at']
        );
    }
}
