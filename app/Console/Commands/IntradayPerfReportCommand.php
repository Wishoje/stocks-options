<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class IntradayPerfReportCommand extends Command
{
    protected $signature = 'intraday:perf-report
        {--hours=24 : Look back this many hours}
        {--symbols= : Comma-separated symbol filter (e.g. SPY,QQQ)}
        {--file= : Log file path (default: storage/logs/laravel.log)}
        {--limit=25 : Max rows to print}';

    protected $description = 'Summarize intraday endpoint performance from laravel.log';

    public function handle(): int
    {
        $path = (string) ($this->option('file') ?: storage_path('logs/laravel.log'));
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $limit = max(1, (int) $this->option('limit'));

        if (!is_file($path)) {
            $this->error("Log file not found: {$path}");
            return self::FAILURE;
        }

        $symbolFilter = $this->parseSymbols((string) $this->option('symbols'));
        $stats = [];
        $seen = 0;

        $fh = fopen($path, 'r');
        if (!$fh) {
            $this->error("Unable to open log file: {$path}");
            return self::FAILURE;
        }

        while (($line = fgets($fh)) !== false) {
            if (!str_contains($line, 'intraday.perf')) {
                continue;
            }

            $seen++;
            $parsed = $this->parsePerfLine($line);
            if (!$parsed) {
                continue;
            }

            [$ts, $event, $ctx] = $parsed;

            if ($ts->lt($cutoff)) {
                continue;
            }

            $symbol = strtoupper((string) ($ctx['symbol'] ?? ''));
            $endpoint = (string) ($ctx['endpoint'] ?? '');
            $duration = isset($ctx['duration_ms']) ? (int) $ctx['duration_ms'] : null;

            if ($symbol === '' || $endpoint === '' || $duration === null) {
                continue;
            }

            if ($symbolFilter && !in_array($symbol, $symbolFilter, true)) {
                continue;
            }

            $key = $endpoint.'|'.$symbol;
            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'endpoint' => $endpoint,
                    'symbol' => $symbol,
                    'durations' => [],
                    'slow_count' => 0,
                    'cache_hits' => 0,
                    'rows' => 0,
                ];
            }

            $stats[$key]['durations'][] = $duration;
            $stats[$key]['rows']++;

            if (($ctx['cache_hit'] ?? false) === true) {
                $stats[$key]['cache_hits']++;
            }

            if ($event === 'intraday.perf.slow') {
                $stats[$key]['slow_count']++;
            }
        }

        fclose($fh);

        if (empty($stats)) {
            $this->warn('No intraday perf entries found for selected window/filter.');
            $this->line("Scanned file: {$path}");
            $this->line("Window: last {$hours}h");
            $this->line("Candidate lines containing intraday.perf: {$seen}");
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($stats as $s) {
            $dur = $s['durations'];
            sort($dur, SORT_NUMERIC);
            $count = count($dur);
            $avg = $count > 0 ? (array_sum($dur) / $count) : 0;
            $cacheHitPct = $s['rows'] > 0 ? ($s['cache_hits'] / $s['rows']) * 100 : 0;

            $rows[] = [
                'endpoint' => $s['endpoint'],
                'symbol' => $s['symbol'],
                'n' => $count,
                'p50_ms' => $this->percentile($dur, 50),
                'p95_ms' => $this->percentile($dur, 95),
                'p99_ms' => $this->percentile($dur, 99),
                'avg_ms' => (int) round($avg),
                'max_ms' => $count ? max($dur) : 0,
                'slow' => $s['slow_count'],
                'cache_hit_%' => round($cacheHitPct, 1),
            ];
        }

        usort($rows, fn ($a, $b) => $b['p95_ms'] <=> $a['p95_ms']);
        $rows = array_slice($rows, 0, $limit);

        $this->info("Intraday perf summary (last {$hours}h)");
        $this->line("Log: {$path}");
        if ($symbolFilter) {
            $this->line('Symbols: '.implode(', ', $symbolFilter));
        }
        $this->table(
            ['endpoint', 'symbol', 'n', 'p50_ms', 'p95_ms', 'p99_ms', 'avg_ms', 'max_ms', 'slow', 'cache_hit_%'],
            $rows
        );

        return self::SUCCESS;
    }

    private function parseSymbols(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $s) => strtoupper(trim($s)),
            explode(',', $input)
        ))));
    }

    private function parsePerfLine(string $line): ?array
    {
        if (!preg_match('/^\[([^\]]+)\]/', $line, $mTs)) {
            return null;
        }

        try {
            $ts = Carbon::parse($mTs[1]);
        } catch (\Throwable) {
            return null;
        }

        if (!preg_match('/\b(intraday\.perf(?:\.slow)?)\b/', $line, $mEvent)) {
            return null;
        }
        $event = $mEvent[1];

        if (!preg_match('/(\{.*\})\s*$/', $line, $mJson)) {
            return null;
        }

        $ctx = json_decode($mJson[1], true);
        if (!is_array($ctx)) {
            return null;
        }

        return [$ts, $event, $ctx];
    }

    private function percentile(array $sortedDurations, int $p): int
    {
        $n = count($sortedDurations);
        if ($n === 0) {
            return 0;
        }

        $rank = (int) ceil(($p / 100) * $n) - 1;
        $rank = max(0, min($n - 1, $rank));

        return (int) $sortedDurations[$rank];
    }
}
