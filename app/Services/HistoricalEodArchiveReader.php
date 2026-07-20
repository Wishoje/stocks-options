<?php

namespace App\Services;

use App\Support\Symbols;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Streams the frozen intraday JSONL archive and selects one coherent, latest
 * post-close request group for the expiration that disappeared after OPEX.
 */
class HistoricalEodArchiveReader
{
    /**
     * Select the latest coherent request group for every archived expiration.
     * Only one group is retained in memory per symbol/expiration while the
     * 420k-row source is streamed.
     *
     * @param  array<int,string>  $symbols
     * @return array<string,array<string,array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}>>
     */
    public function latestExpirationGroups(
        string $path,
        string $expectedSha256,
        string $targetDate,
        string $endDate,
        array $symbols,
    ): array {
        return $this->readGroups($path, $expectedSha256, $targetDate, $endDate, $symbols);
    }

    /**
     * @param  array<int,string>  $symbols
     * @return array<string,array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}>
     */
    public function targetExpirationGroups(
        string $path,
        string $expectedSha256,
        string $targetDate,
        array $symbols,
    ): array {
        $allGroups = $this->readGroups($path, $expectedSha256, $targetDate, $targetDate, $symbols);
        $selected = [];
        foreach ($allGroups as $symbol => $expirationGroups) {
            if (! isset($expirationGroups[$targetDate])) {
                throw new RuntimeException("Recovery archive has no {$targetDate} expiration group for {$symbol}.");
            }
            $selected[$symbol] = $expirationGroups[$targetDate];
        }

        return $selected;
    }

    /**
     * @param  array<int,string>  $symbols
     * @return array<string,array<string,array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}>>
     */
    private function readGroups(
        string $path,
        string $expectedSha256,
        string $targetDate,
        string $endDate,
        array $symbols,
    ): array {
        if (! $this->validDate($targetDate) || ! $this->validDate($endDate) || $targetDate > $endDate) {
            throw new RuntimeException('Recovery archive dates must use a valid YYYY-MM-DD window.');
        }
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException("Recovery archive is not readable: {$path}");
        }

        $actualSha256 = hash_file('sha256', $realPath);
        if (! is_string($actualSha256) || ! hash_equals(strtolower($expectedSha256), strtolower($actualSha256))) {
            throw new RuntimeException('Recovery archive SHA-256 does not match the expected value.');
        }

        $wanted = [];
        foreach ($symbols as $symbol) {
            $canonical = Symbols::canon($symbol);
            if ($canonical !== null && $canonical !== '') {
                $wanted[$canonical] = true;
            }
        }
        if ($wanted === []) {
            throw new RuntimeException('At least one recovery symbol is required.');
        }

        try {
            $dayStartUtc = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                "{$targetDate} 00:00:00",
                'America/New_York'
            )->utc();
        } catch (\Throwable $exception) {
            throw new RuntimeException('Recovery target date must use YYYY-MM-DD.', previous: $exception);
        }
        $dayEndUtc = $dayStartUtc->addDay();

        /** @var array<string,array<string,array{captured_at:string,request_id:string,contracts:array<string,array<string,mixed>>}>> $groups */
        $groups = [];
        /** @var array<string,array<string,array<string,bool>>> $groupIdentities */
        $groupIdentities = [];
        $lines = 0;
        $matchedRows = 0;
        $reader = $this->openReader($realPath);

        try {
            while (($line = $this->readLine($reader)) !== false) {
                $lines++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (! is_array($row)) {
                    throw new RuntimeException("Recovery archive contains invalid JSON on line {$lines}.");
                }

                $symbol = Symbols::canon((string) ($row['symbol'] ?? ''));
                if ($symbol === null || ! isset($wanted[$symbol])) {
                    continue;
                }
                $expiry = trim((string) ($row['expiration_date'] ?? ''));
                if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $expiry) !== 1) {
                    throw new RuntimeException("Recovery archive has an invalid expiration for {$symbol}.");
                }
                try {
                    $expiryDate = CarbonImmutable::createFromFormat('!Y-m-d', $expiry, 'America/New_York');
                } catch (\Throwable) {
                    throw new RuntimeException("Recovery archive has an invalid expiration for {$symbol}.");
                }
                if ($expiryDate === null || $expiryDate->format('Y-m-d') !== $expiry) {
                    throw new RuntimeException("Recovery archive has an invalid expiration for {$symbol}.");
                }
                if ($expiry < $targetDate || $expiry > $endDate) {
                    continue;
                }

                $capturedRaw = trim((string) ($row['captured_at'] ?? ''));
                if ($capturedRaw === '') {
                    throw new RuntimeException("Recovery archive has an empty captured_at for {$symbol}.");
                }
                try {
                    $capturedAt = CarbonImmutable::parse($capturedRaw, 'UTC')->utc();
                } catch (\Throwable) {
                    throw new RuntimeException("Recovery archive has an invalid captured_at for {$symbol}.");
                }
                if ($capturedAt->lt($dayStartUtc) || ! $capturedAt->lt($dayEndUtc)) {
                    throw new RuntimeException("Recovery archive row for {$symbol} falls outside the target New York session.");
                }

                $ticker = strtoupper(trim((string) ($row['contract_symbol'] ?? '')));
                $type = strtolower(trim((string) ($row['contract_type'] ?? '')));
                $strike = (float) ($row['strike_price'] ?? 0);
                $requestId = trim((string) ($row['request_id'] ?? ''));
                if (
                    $ticker === ''
                    || ! in_array($type, ['call', 'put'], true)
                    || $strike <= 0
                    || $requestId === ''
                ) {
                    throw new RuntimeException("Recovery archive has an invalid target-expiration row for {$symbol}.");
                }

                $captured = $capturedAt->format('Y-m-d H:i:s');
                $groupKey = hash('sha256', $requestId.'|'.$captured);
                $groupIdentities[$symbol][$expiry][$groupKey] = true;
                $selectedGroup = $groups[$symbol][$expiry] ?? null;
                if ($selectedGroup === null || $captured > $selectedGroup['captured_at']) {
                    $groups[$symbol][$expiry] = [
                        'captured_at' => $captured,
                        'request_id' => $requestId,
                        'contracts' => [],
                    ];
                    $selectedGroup = $groups[$symbol][$expiry];
                }
                if ($captured < $selectedGroup['captured_at']) {
                    continue;
                }
                if ($requestId !== $selectedGroup['request_id']) {
                    throw new RuntimeException(
                        "Recovery archive contains multiple request IDs at the latest capture for {$symbol} {$expiry}."
                    );
                }

                if (isset($groups[$symbol][$expiry]['contracts'][$ticker])) {
                    throw new RuntimeException("Recovery archive contains duplicate contract {$ticker} in one request group.");
                }

                $groups[$symbol][$expiry]['contracts'][$ticker] = $row;
                $matchedRows++;
            }
        } finally {
            $this->closeReader($reader);
        }

        $postReadSha256 = hash_file('sha256', $realPath);
        if (! is_string($postReadSha256) || ! hash_equals(strtolower($actualSha256), strtolower($postReadSha256))) {
            throw new RuntimeException('Recovery archive changed while it was being read.');
        }

        $selected = [];
        foreach (array_keys($wanted) as $symbol) {
            if (($groups[$symbol] ?? []) === []) {
                throw new RuntimeException("Recovery archive has no requested rows for {$symbol}.");
            }
            foreach (($groups[$symbol] ?? []) as $expiry => $group) {
                $contracts = array_values($group['contracts']);
                $sides = array_values(array_unique(array_map(
                    static fn (array $row): string => strtolower((string) $row['contract_type']),
                    $contracts
                )));
                sort($sides);
                if ($sides !== ['call', 'put']) {
                    throw new RuntimeException("Recovery archive expiration {$expiry} is one-sided for {$symbol}.");
                }

                $selected[$symbol][$expiry] = [
                    'contracts' => $contracts,
                    'meta' => [
                        'archive_sha256' => strtolower($actualSha256),
                        'archive_lines_scanned' => $lines,
                        'archive_rows_matched' => $matchedRows,
                        'available_request_groups' => count($groupIdentities[$symbol][$expiry] ?? []),
                        'selected_request_id' => $group['request_id'],
                        'selected_captured_at' => $group['captured_at'],
                        'contracts_unique' => count($contracts),
                        'sides' => $sides,
                    ],
                ];
            }
        }

        return $selected;
    }

    /** @return array{gzip:bool,handle:resource} */
    private function openReader(string $path): array
    {
        $gzip = str_ends_with(strtolower($path), '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open recovery archive: {$path}");
        }

        return ['gzip' => $gzip, 'handle' => $handle];
    }

    /** @param array{gzip:bool,handle:resource} $reader */
    private function readLine(array $reader): string|false
    {
        return $reader['gzip']
            ? gzgets($reader['handle'])
            : fgets($reader['handle']);
    }

    /** @param array{gzip:bool,handle:resource} $reader */
    private function closeReader(array $reader): void
    {
        if ($reader['gzip']) {
            gzclose($reader['handle']);

            return;
        }

        fclose($reader['handle']);
    }

    private function validDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
