<?php

namespace App\Console\Commands;

use App\Http\Controllers\GexController;
use App\Support\Symbols;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class WarmGexCacheCommand extends Command
{
    protected $signature = 'gex:warm-cache
                            {--symbols=SPY,QQQ : Comma-separated symbols}
                            {--timeframes=14d,30d,90d : Comma-separated EOD timeframes}
                            {--refresh : Force recompute and overwrite cache}';

    protected $description = 'Prewarm /api/gex-levels server cache for selected symbols and timeframes';

    public function handle(GexController $ctrl): int
    {
        $symbols = collect(explode(',', (string) $this->option('symbols')))
            ->map(fn ($s) => Symbols::canon((string) $s))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $allowed = ['0d', '1d', '7d', '14d', '21d', '30d', '45d', '60d', '90d', 'monthly'];
        $timeframes = collect(explode(',', (string) $this->option('timeframes')))
            ->map(fn ($tf) => strtolower(trim((string) $tf)))
            ->filter()
            ->unique()
            ->filter(fn ($tf) => in_array($tf, $allowed, true))
            ->values()
            ->all();

        $forceRefresh = (bool) $this->option('refresh');

        if (empty($symbols) || empty($timeframes)) {
            $this->warn('No valid symbols/timeframes to warm.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Warming GEX cache for symbols [%s] and timeframes [%s] (refresh=%s)',
            implode(', ', $symbols),
            implode(', ', $timeframes),
            $forceRefresh ? 'yes' : 'no'
        ));

        $ok = 0;
        $failed = 0;

        foreach ($symbols as $symbol) {
            foreach ($timeframes as $tf) {
                $req = Request::create('/api/gex-levels', 'GET', [
                    'symbol' => $symbol,
                    'timeframe' => $tf,
                    'refresh' => $forceRefresh ? 1 : 0,
                ]);

                $resp = $ctrl->getGexLevels($req);
                $status = $resp->getStatusCode();

                if ($status === 200) {
                    $ok++;
                    continue;
                }

                $failed++;
                $body = json_decode($resp->getContent(), true) ?: [];
                $msg = (string) ($body['error'] ?? ('HTTP '.$status));
                $this->warn("Warm failed for {$symbol}/{$tf}: {$msg}");
            }
        }

        $this->info("Warm complete. OK={$ok}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
