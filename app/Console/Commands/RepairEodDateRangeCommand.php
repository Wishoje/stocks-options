<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RepairEodDateRangeCommand extends Command
{
    protected $signature = 'gex:repair-eod-range
                            {--from= : First target data_date (YYYY-MM-DD)}
                            {--to= : Last target data_date (YYYY-MM-DD)}
                            {--symbols= : Optional comma-separated symbols}
                            {--chunk=10 : Symbols per queued chunk}
                            {--days=90 : Expiration lookahead for option chain fetch}
                            {--profile=core : Incomplete-data profile passed to watchlist:repair-missing}
                            {--check-incomplete=1 : Whether to treat incomplete snapshots as repair candidates (1/0)}
                            {--with-backfill=1 : Whether to include PricesBackfillJob before the chain fetch (1/0)}
                            {--min-expirations= : Override minimum distinct expirations}
                            {--min-strikes= : Override minimum distinct strikes}
                            {--min-strike-ratio= : Override minimum target/previous strike-count ratio}
                            {--min-side-ratio= : Override min call/put strike ratio per symbol or expiry}
                            {--allow-nonhistorical-chain-repair : Allow queueing past-date option-chain repairs even though the fetcher is not historical}
                            {--dry-run : Report dates and wrapped command output only}';

    protected $description = 'Queue EOD repairs across a trading-date range to restore stale strike-delta baselines.';

    public function handle(): int
    {
        $from = $this->parseDate((string) $this->option('from'));
        $to = $this->parseDate((string) $this->option('to'));

        if ($from === null || $to === null) {
            $this->error('Invalid --from/--to. Use YYYY-MM-DD.');
            return self::FAILURE;
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $symbols = trim((string) $this->option('symbols'));
        $chunk = max(1, (int) $this->option('chunk'));
        $days = max(1, (int) $this->option('days'));
        $profile = trim((string) $this->option('profile')) ?: 'core';
        $checkIncomplete = $this->truthy($this->option('check-incomplete'));
        $withBackfill = $this->truthy($this->option('with-backfill'));
        $allowNonHistoricalChainRepair = (bool) $this->option('allow-nonhistorical-chain-repair');
        $dryRun = (bool) $this->option('dry-run');

        $dates = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            if ($cursor->isWeekend()) {
                continue;
            }
            $dates[] = $cursor->toDateString();
        }

        if (empty($dates)) {
            $this->warn('No trading dates in the selected range.');
            return self::SUCCESS;
        }

        $currentTradingDate = $this->tradingDate(now('America/New_York'));
        $historicalDates = array_values(array_filter(
            $dates,
            fn (string $date) => $date !== $currentTradingDate
        ));
        if (!$allowNonHistoricalChainRepair && !empty($historicalDates)) {
            $this->error(sprintf(
                'Refusing historical option-chain repair for %s. FetchOptionChainDataJob stores target_date but fetches the current chain, so this would not restore historically accurate option_chain_data. Current trading date is %s. Re-run only for %s, or pass --allow-nonhistorical-chain-repair if you explicitly want that behavior.',
                implode(', ', $historicalDates),
                $currentTradingDate,
                $currentTradingDate
            ));
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Repairing %d trading dates from %s to %s%s',
            count($dates),
            $dates[0],
            $dates[count($dates) - 1],
            $symbols !== '' ? " for {$symbols}" : ''
        ));

        $failures = 0;
        foreach ($dates as $date) {
            $options = [
                '--date' => $date,
                '--chunk' => $chunk,
                '--days' => $days,
                '--profile' => $profile,
            ];

            if ($symbols !== '') {
                $options['--symbols'] = $symbols;
            }
            if ($checkIncomplete) {
                $options['--check-incomplete'] = true;
            }
            if ($withBackfill) {
                $options['--with-backfill'] = true;
            }
            if ($allowNonHistoricalChainRepair) {
                $options['--allow-nonhistorical-chain-repair'] = true;
            }
            if ($dryRun) {
                $options['--dry-run'] = true;
            }

            foreach (['min-expirations', 'min-strikes', 'min-strike-ratio', 'min-side-ratio'] as $opt) {
                $value = $this->option($opt);
                if ($value !== null && $value !== '') {
                    $options["--{$opt}"] = $value;
                }
            }

            $this->line("[".$date.'] watchlist:repair-missing');
            $code = Artisan::call('watchlist:repair-missing', $options);
            $output = trim(Artisan::output());
            if ($output !== '') {
                foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
                    $this->line('  '.$line);
                }
            }

            if ($code !== self::SUCCESS) {
                $failures++;
                $this->error("  command failed with exit code {$code}");
            }
        }

        if ($failures > 0) {
            $this->error("Completed with {$failures} failed date runs.");
            return self::FAILURE;
        }

        $this->info('Range repair dispatch completed.');
        return self::SUCCESS;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, 'America/New_York')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function truthy(mixed $value): bool
    {
        $value = is_string($value) ? strtolower(trim($value)) : $value;

        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function tradingDate(Carbon $now): string
    {
        $ny = $now->copy()->setTimezone('America/New_York');
        if ($ny->isWeekend()) {
            $ny->previousWeekday();
        }

        return $ny->toDateString();
    }
}
