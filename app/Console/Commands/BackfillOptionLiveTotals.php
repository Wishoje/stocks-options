<?php

namespace App\Console\Commands;

use App\Support\OptionLiveTotalsRepository;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class BackfillOptionLiveTotals extends Command
{
    protected $signature = 'intraday:backfill-live-totals
        {--from= : First trade date to backfill (YYYY-MM-DD)}
        {--to= : Last trade date to backfill (YYYY-MM-DD)}
        {--symbols= : Optional comma-separated symbol scope}
        {--chunk=500 : Number of symbol/date keys handled per chunk}';

    protected $description = 'Idempotently backfill canonical option-live totals from the freshest legacy rows';

    public function handle(OptionLiveTotalsRepository $totals): int
    {
        if (! Schema::hasTable('option_live_counters') || ! Schema::hasTable('option_live_totals')) {
            $this->error('Both option_live_counters and option_live_totals must exist. Run migrations first.');

            return self::FAILURE;
        }

        try {
            $from = $this->dateOption('from');
            $to = $this->dateOption('to');
            if ($from !== null && $to !== null && $from > $to) {
                throw new InvalidArgumentException('--from must not be later than --to.');
            }
            $symbols = $this->symbols();
            $chunk = $this->chunkSize();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $scanned = 0;
        $backfilled = 0;
        $lastSymbol = null;
        $lastTradeDate = null;

        do {
            $keys = $this->legacyKeys($from, $to, $symbols)
                ->when($lastSymbol !== null, function (Builder $query) use ($lastSymbol, $lastTradeDate): void {
                    $query->where(function (Builder $after) use ($lastSymbol, $lastTradeDate): void {
                        $after->where('symbol', '>', $lastSymbol)
                            ->orWhere(function (Builder $sameSymbol) use ($lastSymbol, $lastTradeDate): void {
                                $sameSymbol->where('symbol', $lastSymbol)
                                    ->where('trade_date', '>', $lastTradeDate);
                            });
                    });
                })
                ->orderBy('symbol')
                ->orderBy('trade_date')
                ->limit($chunk)
                ->get();

            foreach ($keys as $key) {
                $scanned++;
                if ($totals->backfillOne((string) $key->symbol, (string) $key->trade_date) !== null) {
                    $backfilled++;
                }
                $lastSymbol = (string) $key->symbol;
                $lastTradeDate = (string) $key->trade_date;
            }
        } while ($keys->isNotEmpty());

        $this->info(sprintf(
            'Option-live totals backfill complete: keys_scanned=%d, keys_backfilled=%d.',
            $scanned,
            $backfilled
        ));

        return self::SUCCESS;
    }

    /** @param list<string> $symbols */
    private function legacyKeys(?string $from, ?string $to, array $symbols): Builder
    {
        return DB::table('option_live_counters')
            ->whereNull('exp_date')
            ->whereNull('strike')
            ->whereNull('option_type')
            ->when($from !== null, fn (Builder $query) => $query->where('trade_date', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('trade_date', '<=', $to))
            ->when($symbols !== [], fn (Builder $query) => $query->whereIn('symbol', $symbols))
            ->select(['symbol', 'trade_date'])
            ->distinct();
    }

    private function dateOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));
        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("--{$name} must use YYYY-MM-DD.");
        }

        return $value;
    }

    /** @return list<string> */
    private function symbols(): array
    {
        $raw = trim((string) $this->option('symbols'));
        if ($raw === '') {
            return [];
        }

        $symbols = [];
        foreach (explode(',', $raw) as $value) {
            $symbol = Symbols::canon($value);
            if (! Symbols::isValid($symbol) || strlen($symbol) > 12) {
                throw new InvalidArgumentException("--symbols contains an invalid symbol [{$value}].");
            }
            $symbols[$symbol] = true;
        }

        return array_keys($symbols);
    }

    private function chunkSize(): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1 || $chunk > 5000) {
            throw new InvalidArgumentException('--chunk must be between 1 and 5000.');
        }

        return $chunk;
    }
}
