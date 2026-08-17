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

class CompareOptionLiveTotals extends Command
{
    protected $signature = 'intraday:compare-live-totals
        {--from= : First trade date to compare (YYYY-MM-DD)}
        {--to= : Last trade date to compare (YYYY-MM-DD)}
        {--symbols= : Optional comma-separated symbol scope}
        {--chunk=500 : Number of symbol/date keys handled per chunk}
        {--max-differences=50 : Maximum mismatched keys printed}';

    protected $description = 'Compare legacy and canonical option-live totals and fail if any key differs';

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
            $chunk = $this->boundedIntegerOption('chunk', 1, 5000);
            $maxDifferences = $this->boundedIntegerOption('max-differences', 0, 1000);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $compared = 0;
        $matched = 0;
        $mismatched = 0;
        $missingCanonical = 0;
        $canonicalOnly = 0;
        $lastSymbol = null;
        $lastTradeDate = null;

        do {
            $keys = $this->allKeys($from, $to, $symbols)
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
                $symbol = (string) $key->symbol;
                $tradeDate = (string) $key->trade_date;
                $comparison = $totals->compare($symbol, $tradeDate);
                $compared++;

                if ($comparison['matches']) {
                    $matched++;
                } else {
                    $mismatched++;
                    if ($comparison['canonical'] === null) {
                        $missingCanonical++;
                    } elseif ($comparison['legacy'] === null) {
                        $canonicalOnly++;
                    }
                    if ($mismatched <= $maxDifferences) {
                        $this->error(sprintf(
                            '%s/%s differs: %s',
                            $symbol,
                            $tradeDate,
                            json_encode($comparison['differences'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                        ));
                    }
                }

                $lastSymbol = $symbol;
                $lastTradeDate = $tradeDate;
            }
        } while ($keys->isNotEmpty());

        if ($compared === 0) {
            $this->error('No option-live total keys were found in the requested scope.');

            return self::FAILURE;
        }

        $summary = sprintf(
            'Option-live totals comparison: compared=%d, matched=%d, mismatched=%d, missing_canonical=%d, canonical_only=%d.',
            $compared,
            $matched,
            $mismatched,
            $missingCanonical,
            $canonicalOnly
        );

        if ($mismatched > 0) {
            $this->error($summary);

            return self::FAILURE;
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    /** @param list<string> $symbols */
    private function allKeys(?string $from, ?string $to, array $symbols): Builder
    {
        $legacy = $this->scopedKeys(
            DB::table('option_live_counters')
                ->whereNull('exp_date')
                ->whereNull('strike')
                ->whereNull('option_type'),
            $from,
            $to,
            $symbols
        );
        $canonical = $this->scopedKeys(DB::table('option_live_totals'), $from, $to, $symbols);

        return DB::query()->fromSub($legacy->union($canonical), 'option_live_total_keys');
    }

    /** @param list<string> $symbols */
    private function scopedKeys(Builder $query, ?string $from, ?string $to, array $symbols): Builder
    {
        return $query
            ->when($from !== null, fn (Builder $scope) => $scope->where('trade_date', '>=', $from))
            ->when($to !== null, fn (Builder $scope) => $scope->where('trade_date', '<=', $to))
            ->when($symbols !== [], fn (Builder $scope) => $scope->whereIn('symbol', $symbols))
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

    private function boundedIntegerOption(string $name, int $minimum, int $maximum): int
    {
        $value = (int) $this->option($name);
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("--{$name} must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }
}
