<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\ComputeUAJob;

class ComputeUACommand extends Command
{
    protected $signature = 'ua:compute {symbols?*} {--async}';
    protected $description = 'Compute unusual activity flags for given symbols (or all with data today)';

    public function handle(): int
    {
        $syms = $this->argument('symbols');

        if (empty($syms)) {
            // Default: run for every symbol that has any chain data (use latest date per symbol)
            $syms = DB::table('option_chain_data as o')
                ->join('option_expirations as e','e.id','=','o.expiration_id')
                ->select('e.symbol')
                ->groupBy('e.symbol')
                ->pluck('e.symbol')
                ->map(fn($s)=>strtoupper($s))
                ->values()
                ->all();
        }

        $syms = array_values(array_unique(array_map('strtoupper', $syms)));

        if ($this->option('async')) {
            ComputeUAJob::dispatch($syms);
            $this->info('UA job dispatched for: '.implode(', ', $syms));
        } else {
            (new ComputeUAJob($syms))->handle();
            $this->info('UA computed for: '.implode(', ', $syms));
        }

        return self::SUCCESS;
    }
}
