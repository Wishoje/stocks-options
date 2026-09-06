<?php

namespace App\Console\Commands;

use App\Services\IntradayOptionVolumeIngestor;
use Carbon\Carbon;
use Generator;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class BenchmarkIntradayWrites extends Command
{
    protected $signature = 'intraday:benchmark-writes
        {--rows=1000 : Number of deterministic contracts (1-2000)}
        {--chunk=250 : Bulk upsert chunk size (1-1000)}
        {--json : Print the benchmark report as JSON}';

    protected $description = 'Compare legacy and bulk intraday writes in isolated MySQL temporary tables';

    public function handle(): int
    {
        try {
            $rows = $this->integerOption('rows', 1, 2000);
            $chunk = $this->integerOption('chunk', 1, 1000);
        } catch (InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage());
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return $this->failure('This benchmark requires MySQL and the intraday_option_volumes schema.');
        }

        $originalClock = Carbon::getTestNow();
        $originalBulk = config('intraday_ingestion.bulk_enabled');
        $originalChunk = config('intraday_ingestion.chunk_size');
        $ownedTables = [];
        $meter = (object) ['table' => null, 'queries' => 0];
        DB::listen(static function (QueryExecuted $query) use ($meter): void {
            if ($meter->table !== null && str_contains($query->sql, '`'.$meter->table.'`')) {
                $meter->queries++;
            }
        });

        try {
            $suffix = bin2hex(random_bytes(8));
            $legacyTable = 'gex_intraday_bench_legacy_'.$suffix;
            $bulkTable = 'gex_intraday_bench_bulk_'.$suffix;
            foreach ([$legacyTable, $bulkTable] as $table) {
                $identifier = $this->temporaryIdentifier($table);
                DB::statement('CREATE TEMPORARY TABLE '.$identifier.' LIKE `intraday_option_volumes`');
                $ownedTables[] = $table;
            }

            $clock = Carbon::parse('2026-03-18 17:00:00', 'UTC');
            Carbon::setTestNow($clock);
            config()->set('intraday_ingestion.chunk_size', $chunk);
            $capturedAt = $clock->copy()->subMinute();
            $report = [
                'rows_per_phase' => $rows,
                'chunk_size' => $chunk,
                'storage' => 'temporary_tables_only',
                'matches' => true,
                'phases' => [],
            ];

            foreach (['insert', 'correction'] as $phase) {
                $correction = $phase === 'correction';
                Carbon::setTestNow($clock->copy()->addSeconds($correction ? 10 : 0));
                $result = [];
                foreach (['legacy' => $legacyTable, 'bulk' => $bulkTable] as $mode => $table) {
                    config()->set('intraday_ingestion.bulk_enabled', $mode === 'bulk');
                    $writer = new IntradayOptionVolumeIngestor($table);
                    $result[$mode] = $this->measure(
                        $table,
                        $meter,
                        fn (): int => $writer->ingestMany(
                            $this->contracts($rows, $correction),
                            'benchmark-'.$phase,
                            $capturedAt
                        )
                    );
                }
                $result['matches'] = $result['legacy']['sha256'] === $result['bulk']['sha256']
                    && $result['legacy']['row_count'] === $rows
                    && $result['bulk']['row_count'] === $rows;
                $result['query_reduction_pct'] = $result['legacy']['queries'] > 0
                    ? round(100 * (1 - $result['bulk']['queries'] / $result['legacy']['queries']), 2)
                    : 0.0;
                $report['matches'] = $report['matches'] && $result['matches'];
                $report['phases'][$phase] = $result;
            }

            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->info(sprintf(
                    'Intraday benchmark: %d contracts, chunk=%d, temporary tables only, parity=%s.',
                    $rows,
                    $chunk,
                    $report['matches'] ? 'matched' : 'FAILED'
                ));
                foreach ($report['phases'] as $phase => $result) {
                    foreach (['legacy', 'bulk'] as $mode) {
                        $measurement = $result[$mode];
                        $this->line(sprintf(
                            '%s %s: rows=%d, queries=%d, duration_ms=%.3f, peak_bytes=%d, incremental_peak_bytes=%d',
                            $phase,
                            $mode,
                            $measurement['row_count'],
                            $measurement['queries'],
                            $measurement['duration_ms'],
                            $measurement['peak_bytes'],
                            $measurement['incremental_peak_bytes']
                        ));
                    }
                }
            }

            return $report['matches'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            return $this->failure('Temporary-table benchmark failed ('.$exception::class.').');
        } finally {
            $meter->table = null;
            Carbon::setTestNow($originalClock);
            config()->set('intraday_ingestion.bulk_enabled', $originalBulk);
            config()->set('intraday_ingestion.chunk_size', $originalChunk);
            foreach (array_reverse($ownedTables) as $table) {
                DB::statement('DROP TEMPORARY TABLE IF EXISTS '.$this->temporaryIdentifier($table));
            }
        }
    }

    /** @return array<string, int|float|string> */
    private function measure(string $table, object $meter, callable $write): array
    {
        $meter->queries = 0;
        $meter->table = $table;
        $memoryBefore = memory_get_usage(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $started = hrtime(true);
        try {
            $processed = $write();
        } finally {
            $duration = (hrtime(true) - $started) / 1_000_000;
            $peak = memory_get_peak_usage(true);
            $meter->table = null;
        }

        $queries = $meter->queries;
        $hash = hash_init('sha256');
        $count = 0;
        foreach (DB::table($table)->orderBy('contract_symbol')->orderBy('captured_at')->cursor() as $row) {
            $attributes = (array) $row;
            unset($attributes['id']);
            ksort($attributes);
            hash_update($hash, json_encode($attributes, JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        return [
            'processed' => $processed,
            'row_count' => $count,
            'queries' => $queries,
            'duration_ms' => round($duration, 3),
            'peak_bytes' => $peak,
            'incremental_peak_bytes' => max(0, $peak - $memoryBefore),
            'sha256' => hash_final($hash),
        ];
    }

    /** @return Generator<int, array<string, mixed>> */
    private function contracts(int $rows, bool $correction): Generator
    {
        for ($index = 1; $index <= $rows; $index++) {
            $side = $index % 2 === 0 ? 'put' : 'call';
            $contract = [
                'underlying_asset' => ['ticker' => 'GEXBENCH'],
                'details' => [
                    'ticker' => 'O:GEXBENCH260320'.($side === 'call' ? 'C' : 'P').str_pad((string) $index, 8, '0', STR_PAD_LEFT),
                    'contract_type' => $side,
                    'expiration_date' => '2026-03-20',
                    'strike_price' => number_format(50 + $index / 100, 4, '.', ''),
                ],
                'day' => [
                    'volume' => $index * ($correction ? 101 : 100),
                    'close' => '3.1234567',
                    'change' => '-0.1234567',
                    'change_percent' => '1.7654321',
                ],
                'open_interest' => $index * ($correction ? 201 : 200),
                'implied_volatility' => '0.1234567',
                'greeks' => [
                    'delta' => '0.12345678915',
                    'gamma' => '0.00000000006',
                    'theta' => '-0.12345678915',
                    'vega' => '0.23456789125',
                ],
            ];
            if ($index % 5 === 0) {
                $value = $correction ? null : 0;
                $contract['day'] = ['volume' => $value, 'close' => $value, 'change' => $value, 'change_percent' => $value];
                $contract['open_interest'] = $value;
                $contract['implied_volatility'] = $value;
                $contract['greeks'] = ['delta' => $value, 'gamma' => $value, 'theta' => $value, 'vega' => $value];
            } elseif ($index % 7 === 0 && ! $correction) {
                unset($contract['day'], $contract['open_interest'], $contract['implied_volatility'], $contract['greeks']);
            }

            yield $contract;
        }
    }

    private function temporaryIdentifier(string $table): string
    {
        if (preg_match('/^gex_intraday_bench_(legacy|bulk)_[a-f0-9]{16}$/D', $table) !== 1) {
            throw new InvalidArgumentException('Unexpected benchmark temporary table name.');
        }

        return '`'.$table.'`';
    }

    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = (string) $this->option($name);
        if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int) $value < $minimum || (int) $value > $maximum) {
            throw new InvalidArgumentException('--'.$name.' must be an integer between '.$minimum.' and '.$maximum.'.');
        }

        return (int) $value;
    }

    private function failure(string $message): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(['matches' => false, 'error' => $message], JSON_THROW_ON_ERROR));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
