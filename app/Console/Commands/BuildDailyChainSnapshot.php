<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\ComputePositioningJob;
use App\Jobs\ComputeExpiryPressureJob;
use App\Jobs\ComputeUAJob;

class BuildDailyChainSnapshot extends Command
{
    protected $signature = 'chain:snapshot {date?}';
    protected $description = 'Aggregate option_chain_data into daily_chain_snapshot';

    public function handle(): int
    {
        $date = $this->argument('date') ?? now()->toDateString();

        DB::table('daily_chain_snapshot')->where('data_date', $date)->delete();

        $rows = DB::table('option_chain_data as o')
            ->join('option_expirations as e','e.id','=','o.expiration_id')
            ->selectRaw("
                e.symbol,
                o.data_date,
                o.expiration_id,
                SUM(CASE WHEN o.option_type='call' THEN o.open_interest ELSE 0 END) as call_oi,
                SUM(CASE WHEN o.option_type='put'  THEN o.open_interest ELSE 0 END) as put_oi,
                SUM(CASE WHEN o.option_type='call' THEN o.volume       ELSE 0 END) as call_vol,
                SUM(CASE WHEN o.option_type='put'  THEN o.volume       ELSE 0 END) as put_vol,
                SUM(o.gamma*COALESCE(o.open_interest,0)*100) as sum_gamma,
                SUM(COALESCE(o.delta,0)*COALESCE(o.open_interest,0)*100) as sum_delta,
                SUM(COALESCE(o.vega,0) *COALESCE(o.open_interest,0)*100) as sum_vega
            ")
            ->where('o.data_date',$date)
            ->groupBy('e.symbol','o.data_date','o.expiration_id')
            ->get();

        $payload = $rows->map(fn($r)=>[
            'symbol'=>$r->symbol,
            'data_date'=>$r->data_date,
            'expiration_id'=>$r->expiration_id,
            'call_oi'=>$r->call_oi, 'put_oi'=>$r->put_oi,
            'call_vol'=>$r->call_vol, 'put_vol'=>$r->put_vol,
            'sum_gamma'=>$r->sum_gamma, 'sum_delta'=>$r->sum_delta, 'sum_vega'=>$r->sum_vega,
            'created_at'=>now(),'updated_at'=>now(),
        ])->all();

       if (!empty($payload)) {
            DB::table('daily_chain_snapshot')->insert($payload);

            $symbols = collect($payload)->pluck('symbol')->unique()->values()->all();

            // Positioning (already there)
            (new ComputePositioningJob($symbols))->handle();

            // ✅ Expiry Pressure (pin risk + max pain)
            // if you prefer async: ComputeExpiryPressureJob::dispatch($symbols, 3);
            (new ComputeExpiryPressureJob($symbols, 3))->handle();

            (new ComputeUAJob($symbols))->handle();
        }

        $this->info("Snapshot built for {$date} (rows: ".count($payload).")");
        return self::SUCCESS;
    }
}
