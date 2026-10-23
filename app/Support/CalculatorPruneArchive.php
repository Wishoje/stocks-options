<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CalculatorPruneArchive
{
    /** Write and re-read a complete archive before its rows may be removed. */
    public function write(array $publications, array $runs, array $references): array
    {
        $root = config('maintenance.calculator_archive_path', storage_path('app/private/maintenance/calculator-prune'));
        if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700, true))) {
            throw new RuntimeException('Calculator archive directory is unavailable.');
        }
        $root = realpath($root);
        if (disk_free_space($root) < 5 * 1024 ** 3) {
            throw new RuntimeException('Calculator archive disk needs at least 5 GiB free.');
        }
        $path = $root.'/'.now('UTC')->format('Ymd-His').'-'.Str::uuid().'.jsonl.gz';
        $stream = fopen($path.'.tmp', 'xb');
        if ($stream === false) {
            throw new RuntimeException('Calculator archive could not be created.');
        }
        chmod($path.'.tmp', 0600);
        $filter = stream_filter_append($stream, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31, 'level' => 6]);
        if ($filter === false) {
            fclose($stream);
            throw new RuntimeException('Gzip archive filter is unavailable.');
        }
        $digest = hash_init('sha256');
        $write = function (string $kind, array $row) use ($stream, $digest): void {
            $line = json_encode(['kind' => $kind, 'row' => $row], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n";
            hash_update($digest, $line);
            $offset = 0;
            while ($offset < strlen($line)) {
                $written = fwrite($stream, substr($line, $offset));
                if (! $written) {
                    throw new RuntimeException('Calculator archive write failed.');
                }
                $offset += $written;
            }
        };
        $counts = array_fill_keys(array_column($publications, 'id'), 0);
        try {
            foreach ($runs as $run) {
                $write('run', (array) $run);
            }
            foreach ($publications as $publication) {
                $write('publication', (array) $publication);
            }
            foreach ($references as $reference) {
                $write('run_expiration', (array) $reference);
            }
            DB::table('calculator_expiry_publication_rows')->whereIn('publication_id', array_keys($counts))
                ->orderBy('id')->chunkById(1000, function ($rows) use ($write, &$counts): void {
                    foreach ($rows as $row) {
                        $write('contract', (array) $row);
                        $counts[$row->publication_id]++;
                    }
                });
            foreach ($publications as $publication) {
                if ($counts[$publication['id']] !== (int) $publication['row_count']) {
                    throw new RuntimeException('Publication row count does not match its immutable header.');
                }
            }
        } finally {
            fclose($stream);
        }
        $expected = hash_final($digest);
        $verify = gzopen($path.'.tmp', 'rb');
        if ($verify === false) {
            throw new RuntimeException('Calculator archive cannot be verified.');
        }
        $actual = hash_init('sha256');
        try {
            while (! gzeof($verify)) {
                $chunk = gzread($verify, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('Calculator archive read failed.');
                }
                hash_update($actual, $chunk);
            }
        } finally {
            gzclose($verify);
        }
        if (! hash_equals($expected, hash_final($actual)) || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Calculator archive integrity check failed.');
        }

        return ['path' => $path, 'sha256' => hash_file('sha256', $path), 'decoded_sha256' => $expected,
            'rows' => array_sum($counts), 'publications' => count($counts), 'bytes' => filesize($path)];
    }

    public function markCommitted(array $archive): void
    {
        $manifest = [...$archive, 'committed_at' => now('UTC')->toIso8601String(), 'status' => 'committed'];
        file_put_contents($archive['path'].'.manifest.json.tmp', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
        chmod($archive['path'].'.manifest.json.tmp', 0600);
        if (! rename($archive['path'].'.manifest.json.tmp', $archive['path'].'.manifest.json')) {
            throw new RuntimeException('Calculator archive commit manifest could not be written.');
        }
    }
}
