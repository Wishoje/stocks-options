<?php

namespace App\Services;

use App\Support\EodHealth;
use App\Support\Market;
use App\Support\Symbols;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds, validates, publishes, and rolls back an immutable hybrid EOD
 * recovery run. Capture never writes canonical market-data tables.
 */
class HistoricalEodRecoveryService
{
    private const ARTIFACT_VERSION = 1;

    private const MAX_SPOT_DIFFERENCE_RATIO = 0.03;

    private const METRIC_COVERAGE_TOLERANCE = 0.10;

    private const INTENT_VERSION = 1;

    private const RECOVERY_LOCK_SECONDS = 7200;

    public function __construct(
        private readonly HistoricalEodRecoveryProvider $provider,
        private readonly HistoricalEodArchiveReader $archiveReader,
    ) {}

    /**
     * Capture a complete candidate into immutable artifacts without touching
     * option_chain_data or option_expirations.
     *
     * @param  array<int,string>  $symbols
     * @return array<string,mixed>
     */
    public function capture(
        string $date,
        array $symbols,
        string $archivePath,
        string $expectedArchiveSha,
        string $runDirectory,
    ): array {
        [$target, $end] = $this->recoveryWindow($date);
        $this->assertSafeCaptureWindow($target);
        $symbols = $this->canonicalSymbols($symbols);
        $runDirectory = $this->prepareNewRunDirectory($runDirectory);

        $archiveGroups = $this->archiveReader->latestExpirationGroups(
            $archivePath,
            $expectedArchiveSha,
            $target->toDateString(),
            $end->toDateString(),
            $symbols,
        );
        $postReadArchiveSha = hash_file('sha256', $archivePath);
        if (
            ! is_string($postReadArchiveSha)
            || ! hash_equals(strtolower($expectedArchiveSha), strtolower($postReadArchiveSha))
        ) {
            throw new RuntimeException('Recovery archive changed while it was being staged.');
        }

        $manifest = [
            'artifact_version' => self::ARTIFACT_VERSION,
            'status' => 'capturing',
            'date' => $target->toDateString(),
            'end_date' => $end->toDateString(),
            'symbols' => $symbols,
            'archive_path_fingerprint' => substr(hash('sha256', (string) realpath($archivePath)), 0, 16),
            'archive_sha256' => strtolower($expectedArchiveSha),
            'capture_started_at' => now('UTC')->toIso8601String(),
            'capture_completed_at' => null,
            'candidate_sha256' => null,
            'symbol_results' => [],
            'errors' => [],
        ];
        $this->writeJson($runDirectory.'/manifest.json', $manifest);

        foreach ($symbols as $symbol) {
            try {
                $result = $this->captureSymbol(
                    $symbol,
                    $target,
                    $end,
                    $archiveGroups[$symbol] ?? [],
                    $runDirectory,
                );
                $manifest['symbol_results'][$symbol] = $result;
            } catch (\Throwable $exception) {
                $manifest['errors'][$symbol] = [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            $this->writeJson($runDirectory.'/manifest.json', $manifest);
        }

        $candidateHashes = [];
        foreach ($symbols as $symbol) {
            $hash = $manifest['symbol_results'][$symbol]['candidate_sha256'] ?? null;
            if (is_string($hash) && $hash !== '') {
                $candidateHashes[$symbol] = $hash;
            }
        }
        ksort($candidateHashes);
        $manifest['candidate_sha256'] = hash('sha256', $this->canonicalJson($candidateHashes));
        $manifest['capture_completed_at'] = now('UTC')->toIso8601String();
        $manifest['status'] = $manifest['errors'] === [] && count($candidateHashes) === count($symbols)
            ? 'captured'
            : 'capture_failed';
        $this->writeJson($runDirectory.'/manifest.json', $manifest);

        return [
            'ok' => $manifest['status'] === 'captured',
            'run_directory' => $runDirectory,
            'candidate_sha256' => $manifest['candidate_sha256'],
            'symbols_captured' => count($candidateHashes),
            'symbols_requested' => count($symbols),
            'errors' => $manifest['errors'],
        ];
    }

    /** @return array<string,mixed> */
    public function validate(string $runDirectory): array
    {
        $runDirectory = $this->existingRunDirectory($runDirectory);
        $manifest = $this->readJson($runDirectory.'/manifest.json');
        $errors = [];

        if ((int) ($manifest['artifact_version'] ?? 0) !== self::ARTIFACT_VERSION) {
            $errors[] = 'unsupported_artifact_version';
        }
        if (! in_array(($manifest['status'] ?? null), ['captured', 'validated', 'published'], true)) {
            $errors[] = 'capture_not_complete';
        }

        $symbols = $this->canonicalSymbols((array) ($manifest['symbols'] ?? []));
        $candidateHashes = [];
        $reports = [];
        $candidateRows = 0;
        foreach ($symbols as $symbol) {
            try {
                $result = (array) ($manifest['symbol_results'][$symbol] ?? []);
                $artifactFiles = [];
                foreach (['reference', 'archive', 'snapshot', 'candidate'] as $artifact) {
                    $fileKey = $artifact.'_file';
                    $shaKey = $artifact.'_file_sha256';
                    $artifactFile = $this->resolveArtifactPath(
                        $runDirectory,
                        (string) ($result[$fileKey] ?? ''),
                    );
                    $artifactSha = hash_file('sha256', $artifactFile);
                    if (
                        ! is_string($artifactSha)
                        || ! hash_equals(strtolower((string) ($result[$shaKey] ?? '')), strtolower($artifactSha))
                    ) {
                        throw new RuntimeException("{$artifact}_file_sha256_mismatch");
                    }
                    $artifactFiles[$artifact] = $artifactFile;
                }

                $candidateFile = $this->resolveArtifactPath(
                    $runDirectory,
                    (string) ($result['candidate_file'] ?? ''),
                );
                $candidate = $this->readGzipJson($candidateFile);
                foreach (['reference', 'archive', 'snapshot'] as $sourceArtifact) {
                    $committed = (array) ($candidate['source_artifacts'][$sourceArtifact] ?? []);
                    if (
                        (string) ($committed['file'] ?? '') !== (string) ($result[$sourceArtifact.'_file'] ?? '')
                        || ! hash_equals(
                            strtolower((string) ($committed['sha256'] ?? '')),
                            strtolower((string) ($result[$sourceArtifact.'_file_sha256'] ?? '')),
                        )
                    ) {
                        throw new RuntimeException("{$sourceArtifact}_evidence_commitment_mismatch");
                    }
                }
                $actualFileSha = hash_file('sha256', $candidateFile);
                if (! is_string($actualFileSha) || ! hash_equals((string) ($result['candidate_file_sha256'] ?? ''), $actualFileSha)) {
                    throw new RuntimeException('candidate_file_sha256_mismatch');
                }

                $report = $this->validateCandidate(
                    $candidate,
                    $manifest,
                    $symbol,
                    $this->readGzipJson($artifactFiles['archive']),
                    $this->readGzipJson($artifactFiles['reference']),
                );
                if (! $report['ok']) {
                    throw new RuntimeException(implode(',', $report['errors']));
                }
                if (! hash_equals((string) ($result['candidate_sha256'] ?? ''), (string) $report['candidate_sha256'])) {
                    throw new RuntimeException('candidate_content_sha256_mismatch');
                }

                $candidateHashes[$symbol] = $report['candidate_sha256'];
                $reports[$symbol] = $report;
                $candidateRows += (int) ($report['candidate_rows'] ?? 0);
            } catch (\Throwable $exception) {
                $errors[$symbol] = $exception->getMessage();
            }
        }

        ksort($candidateHashes);
        $overallHash = hash('sha256', $this->canonicalJson($candidateHashes));
        if (! hash_equals((string) ($manifest['candidate_sha256'] ?? ''), $overallHash)) {
            $errors['manifest'] = 'overall_candidate_sha256_mismatch';
        }

        $ok = $errors === [] && count($reports) === count($symbols);
        $validation = [
            'artifact_version' => self::ARTIFACT_VERSION,
            'ok' => $ok,
            'candidate_sha256' => $overallHash,
            'candidate_rows' => $candidateRows,
            'validated_at' => now('UTC')->toIso8601String(),
            'symbols' => $reports,
            'errors' => $errors,
        ];
        $this->writeJson($runDirectory.'/validation.json', $validation);

        if ($ok && ($manifest['status'] ?? null) === 'captured') {
            $manifest['status'] = 'validated';
            $manifest['validated_at'] = $validation['validated_at'];
            $this->writeJson($runDirectory.'/manifest.json', $manifest);
        }

        return $validation;
    }

    /** @return array<string,mixed> */
    public function publish(string $runDirectory, string $confirmSha): array
    {
        $runDirectory = $this->existingRunDirectory($runDirectory);
        $validation = $this->validate($runDirectory);
        if (! ($validation['ok'] ?? false)) {
            throw new RuntimeException('Recovery validation failed; canonical publication is blocked.');
        }
        if (! hash_equals((string) $validation['candidate_sha256'], strtolower(trim($confirmSha)))) {
            throw new RuntimeException('Recovery confirmation SHA does not match the validated candidate.');
        }

        $manifest = $this->readJson($runDirectory.'/manifest.json');
        $date = (string) $manifest['date'];
        $symbols = $this->canonicalSymbols((array) $manifest['symbols']);
        $candidateSha = strtolower((string) $validation['candidate_sha256']);

        return $this->withRecoverySliceLocks($symbols, $date, function () use (
            $runDirectory,
            $manifest,
            $symbols,
            $date,
            $candidateSha,
        ): array {
            $receiptPath = $runDirectory.'/publish-receipt.json';
            if (($manifest['status'] ?? null) === 'published' && is_file($receiptPath)) {
                throw new RuntimeException('Recovery run has already been published.');
            }

            [$intent, $intentExists] = $this->resolvedPublishIntent(
                $runDirectory,
                $manifest,
                $symbols,
                $date,
                $candidateSha,
            );
            $classification = $this->classifyPersistedSlices(
                $date,
                (array) $intent['expectations'],
                false,
            );

            if (! $intentExists) {
                if ($classification['state'] !== 'all_empty') {
                    throw new RuntimeException(
                        'Recovery publication refuses existing target rows without a prepared publish intent.'
                    );
                }
                $this->writeJson($runDirectory.'/publish-intent.json', $intent);
            } elseif ($classification['state'] === 'mixed') {
                throw new RuntimeException('Recovery publication found mixed or changed target slices.');
            }

            if ($classification['state'] === 'all_empty') {
                $this->publishPreparedIntent($runDirectory, $manifest, $intent);
                $classification = $this->classifyPersistedSlices(
                    $date,
                    (array) $intent['expectations'],
                    false,
                );
            }
            if ($classification['state'] !== 'all_exact') {
                throw new RuntimeException('Recovery publication did not produce every exact prepared slice.');
            }

            $receipt = $this->finalizePublishArtifacts($runDirectory, $manifest, $intent);
            $published = (array) $receipt['symbols'];

            return [
                'ok' => true,
                'candidate_sha256' => $candidateSha,
                'published' => $published,
                'inserted_rows' => array_sum($published),
                'persisted_sha256' => $receipt['persisted_sha256'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function rollback(string $runDirectory, string $confirmSha): array
    {
        $runDirectory = $this->existingRunDirectory($runDirectory);
        $manifest = $this->readJson($runDirectory.'/manifest.json');
        $receipt = $this->validatedPublishReceipt($runDirectory.'/publish-receipt.json');
        $candidateSha = strtolower((string) ($receipt['candidate_sha256'] ?? ''));
        if ($candidateSha === '' || ! hash_equals($candidateSha, strtolower(trim($confirmSha)))) {
            throw new RuntimeException('Recovery rollback confirmation SHA does not match the publication receipt.');
        }
        if (! in_array(($manifest['status'] ?? null), ['published', 'rolled_back'], true)) {
            throw new RuntimeException('Only a published recovery run can be rolled back.');
        }
        if (! hash_equals($candidateSha, strtolower((string) ($manifest['candidate_sha256'] ?? '')))) {
            throw new RuntimeException('Recovery manifest and publication receipt do not match.');
        }

        $date = (string) $receipt['date'];
        $persistedSymbols = (array) ($receipt['persisted_symbols'] ?? []);
        $symbols = array_keys($persistedSymbols);
        sort($symbols);

        return $this->withRecoverySliceLocks($symbols, $date, function () use (
            $runDirectory,
            $manifest,
            $receipt,
            $symbols,
            $date,
            $candidateSha,
        ): array {
            $rollbackReceiptPath = $runDirectory.'/rollback-receipt.json';
            if (($manifest['status'] ?? null) === 'rolled_back' && is_file($rollbackReceiptPath)) {
                throw new RuntimeException('Recovery run has already been rolled back.');
            }

            [$intent, $intentExists] = $this->resolvedRollbackIntent(
                $runDirectory,
                $receipt,
                $symbols,
                $date,
                $candidateSha,
            );
            $classification = $this->classifyPersistedSlices(
                $date,
                (array) $intent['expectations'],
                false,
            );

            if (! $intentExists) {
                if ($classification['state'] !== 'all_exact') {
                    throw new RuntimeException(
                        'Recovery rollback refuses absent or changed rows without a prepared rollback intent.'
                    );
                }
                $this->writeJson($runDirectory.'/rollback-intent.json', $intent);
            } elseif ($classification['state'] === 'mixed') {
                throw new RuntimeException('Recovery rollback found mixed or changed target slices.');
            }

            if ($classification['state'] === 'all_exact') {
                $this->rollbackPreparedIntent($intent);
                $classification = $this->classifyPersistedSlices(
                    $date,
                    (array) $intent['expectations'],
                    false,
                );
            }
            if ($classification['state'] !== 'all_empty') {
                throw new RuntimeException('Recovery rollback did not empty every prepared slice.');
            }

            $rollback = $this->finalizeRollbackArtifacts($runDirectory, $manifest, $intent);

            return [
                'ok' => true,
                'deleted' => (array) $rollback['deleted'],
                'candidate_sha256' => $candidateSha,
            ];
        });
    }

    /** @param array<int,string> $symbols */
    private function withRecoverySliceLocks(array $symbols, string $date, callable $callback): mixed
    {
        $symbols = $this->canonicalSymbols($symbols);
        $locks = [];

        try {
            foreach ($symbols as $symbol) {
                $key = "optchain:pulling:{$symbol}:{$date}";
                $lock = Cache::lock($key, self::RECOVERY_LOCK_SECONDS);
                if (! $lock->get()) {
                    throw new RuntimeException("Recovery slice lock is busy for {$symbol} {$date}.");
                }
                $locks[] = $lock;
            }

            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                    // The finite lease remains the final safety net if Redis is
                    // unavailable while the process is unwinding.
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<int,string>  $symbols
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function resolvedPublishIntent(
        string $runDirectory,
        array $manifest,
        array $symbols,
        string $date,
        string $candidateSha,
    ): array {
        $expectations = [];
        $candidateHashes = [];
        foreach ($symbols as $symbol) {
            [$candidate, $report] = $this->loadBoundCandidate($runDirectory, $manifest, $symbol);
            $persisted = $this->candidatePersistedRows(
                $symbol,
                $date,
                (array) ($candidate['rows'] ?? []),
            );
            $expectations[$symbol] = $this->sliceExpectation($persisted);
            $candidateHashes[$symbol] = (string) $report['candidate_sha256'];
            unset($candidate, $persisted, $report);
        }
        ksort($expectations);
        ksort($candidateHashes);
        $actualCandidateSha = hash('sha256', $this->canonicalJson($candidateHashes));
        if (! hash_equals($candidateSha, $actualCandidateSha)) {
            throw new RuntimeException('Prepared publication candidates do not match the confirmed candidate SHA.');
        }

        $prepared = $this->newPreparedIntent(
            'publish',
            $date,
            $candidateSha,
            $symbols,
            $expectations,
        );
        $path = $runDirectory.'/publish-intent.json';
        if (! is_file($path)) {
            return [$prepared, false];
        }

        $existing = $this->validatedIntent($path, 'publish');
        $this->assertIntentMatches($existing, $prepared);

        return [$existing, true];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<int,string>  $symbols
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function resolvedRollbackIntent(
        string $runDirectory,
        array $receipt,
        array $symbols,
        string $date,
        string $candidateSha,
    ): array {
        $expectations = [];
        $persistedSymbols = (array) ($receipt['persisted_symbols'] ?? []);
        foreach ($symbols as $symbol) {
            $expected = (array) ($persistedSymbols[$symbol] ?? []);
            $expectations[$symbol] = [
                'rows' => (int) ($expected['rows'] ?? -1),
                'natural_key_sha256' => strtolower((string) ($expected['natural_key_sha256'] ?? '')),
                'full_slice_sha256' => strtolower((string) ($expected['sha256'] ?? '')),
            ];
        }
        ksort($expectations);

        $prepared = $this->newPreparedIntent(
            'rollback',
            $date,
            $candidateSha,
            $symbols,
            $expectations,
        );
        $path = $runDirectory.'/rollback-intent.json';
        if (! is_file($path)) {
            return [$prepared, false];
        }

        $existing = $this->validatedIntent($path, 'rollback');
        $this->assertIntentMatches($existing, $prepared);

        return [$existing, true];
    }

    /**
     * @param  array<int,string>  $symbols
     * @param  array<string,array<string,mixed>>  $expectations
     * @return array<string,mixed>
     */
    private function newPreparedIntent(
        string $type,
        string $date,
        string $candidateSha,
        array $symbols,
        array $expectations,
    ): array {
        sort($symbols);
        ksort($expectations);
        $intent = [
            'version' => self::INTENT_VERSION,
            'type' => $type,
            'status' => 'prepared',
            'date' => $date,
            'candidate_sha256' => strtolower($candidateSha),
            'symbols' => array_values($symbols),
            'expectations' => $expectations,
            'prepared_at' => now('UTC')->toIso8601String(),
        ];
        $intent['intent_sha256'] = hash('sha256', $this->canonicalJson($intent));

        return $intent;
    }

    /** @return array<string,mixed> */
    private function validatedIntent(string $path, string $type): array
    {
        $intent = $this->readJson($path);
        $claimed = strtolower((string) ($intent['intent_sha256'] ?? ''));
        $unsigned = $intent;
        unset($unsigned['intent_sha256']);
        $actual = hash('sha256', $this->canonicalJson($unsigned));
        if (! $this->validSha256($claimed) || ! hash_equals($claimed, $actual)) {
            throw new RuntimeException("Recovery {$type} intent self-hash is invalid.");
        }
        if (
            (int) ($intent['version'] ?? 0) !== self::INTENT_VERSION
            || ($intent['type'] ?? null) !== $type
            || ($intent['status'] ?? null) !== 'prepared'
            || ! $this->isStrictDate((string) ($intent['date'] ?? ''))
            || ! $this->validSha256((string) ($intent['candidate_sha256'] ?? ''))
        ) {
            throw new RuntimeException("Recovery {$type} intent schema is invalid.");
        }

        $symbols = (array) ($intent['symbols'] ?? []);
        if ($symbols !== $this->canonicalSymbols($symbols)) {
            throw new RuntimeException("Recovery {$type} intent symbols are not canonical and sorted.");
        }
        $expectations = (array) ($intent['expectations'] ?? []);
        if (array_keys($expectations) !== $symbols) {
            throw new RuntimeException("Recovery {$type} intent expectations do not match its symbols.");
        }
        foreach ($expectations as $symbol => $expectation) {
            $expectation = (array) $expectation;
            if (
                (int) ($expectation['rows'] ?? 0) <= 0
                || ! $this->validSha256((string) ($expectation['natural_key_sha256'] ?? ''))
                || ! $this->validSha256((string) ($expectation['full_slice_sha256'] ?? ''))
            ) {
                throw new RuntimeException("Recovery {$type} intent expectation is invalid for {$symbol}.");
            }
        }

        return $intent;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $expected */
    private function assertIntentMatches(array $existing, array $expected): void
    {
        foreach (['version', 'type', 'status', 'date', 'candidate_sha256', 'symbols', 'expectations'] as $field) {
            if ($this->canonicalJson($existing[$field] ?? null) !== $this->canonicalJson($expected[$field] ?? null)) {
                throw new RuntimeException("Recovery prepared intent conflicts at {$field}.");
            }
        }
    }

    /**
     * Rebind the exact decoded candidate and each evidence file to the hashes
     * captured in the immutable manifest. The returned candidate is the object
     * that publication must insert without rereading it.
     *
     * @param  array<string,mixed>  $manifest
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function loadBoundCandidate(string $runDirectory, array $manifest, string $symbol): array
    {
        $result = (array) ($manifest['symbol_results'][$symbol] ?? []);
        $artifactPaths = [];
        foreach (['reference', 'archive', 'snapshot', 'candidate'] as $artifact) {
            $path = $this->resolveArtifactPath(
                $runDirectory,
                (string) ($result[$artifact.'_file'] ?? ''),
            );
            $actualSha = hash_file('sha256', $path);
            $expectedSha = strtolower((string) ($result[$artifact.'_file_sha256'] ?? ''));
            if (! is_string($actualSha) || ! hash_equals($expectedSha, strtolower($actualSha))) {
                throw new RuntimeException("{$artifact}_file_sha256_mismatch");
            }
            $artifactPaths[$artifact] = $path;
        }

        $candidate = $this->readGzipJson($artifactPaths['candidate']);
        foreach (['reference', 'archive', 'snapshot'] as $sourceArtifact) {
            $committed = (array) ($candidate['source_artifacts'][$sourceArtifact] ?? []);
            if (
                (string) ($committed['file'] ?? '') !== (string) ($result[$sourceArtifact.'_file'] ?? '')
                || ! hash_equals(
                    strtolower((string) ($committed['sha256'] ?? '')),
                    strtolower((string) ($result[$sourceArtifact.'_file_sha256'] ?? '')),
                )
            ) {
                throw new RuntimeException("{$sourceArtifact}_evidence_commitment_mismatch");
            }
        }

        // Hash again after the read. This rejects a file replacement during
        // decoding, while the content hash below binds the exact decoded object.
        $candidateFileSha = hash_file('sha256', $artifactPaths['candidate']);
        if (
            ! is_string($candidateFileSha)
            || ! hash_equals(
                strtolower((string) ($result['candidate_file_sha256'] ?? '')),
                strtolower($candidateFileSha),
            )
        ) {
            throw new RuntimeException('candidate_file_sha256_mismatch');
        }

        $report = $this->validateCandidate(
            $candidate,
            $manifest,
            $symbol,
            $this->readGzipJson($artifactPaths['archive']),
            $this->readGzipJson($artifactPaths['reference']),
        );
        if (! ($report['ok'] ?? false)) {
            throw new RuntimeException(implode(',', (array) ($report['errors'] ?? [])));
        }
        if (! hash_equals(
            strtolower((string) ($result['candidate_sha256'] ?? '')),
            strtolower((string) ($report['candidate_sha256'] ?? '')),
        )) {
            throw new RuntimeException('candidate_content_sha256_mismatch');
        }

        return [$candidate, $report];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function sliceExpectation(array $rows): array
    {
        return [
            'rows' => count($rows),
            'natural_key_sha256' => $this->naturalKeySha($rows),
            'full_slice_sha256' => hash('sha256', $this->canonicalJson($rows)),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $expectations
     * @return array{state:string,symbols:array<string,string>}
     */
    private function classifyPersistedSlices(string $date, array $expectations, bool $lock): array
    {
        $states = [];
        foreach ($expectations as $symbol => $expected) {
            $actual = $this->persistedSliceRows((string) $symbol, $date, $lock);
            if ($actual === []) {
                $states[(string) $symbol] = 'empty';

                continue;
            }
            $actualExpectation = $this->sliceExpectation($actual);
            $states[(string) $symbol] = (
                (int) ($actualExpectation['rows'] ?? -1) === (int) ($expected['rows'] ?? -2)
                && hash_equals(
                    strtolower((string) ($expected['natural_key_sha256'] ?? '')),
                    strtolower((string) $actualExpectation['natural_key_sha256']),
                )
                && hash_equals(
                    strtolower((string) ($expected['full_slice_sha256'] ?? '')),
                    strtolower((string) $actualExpectation['full_slice_sha256']),
                )
            ) ? 'exact' : 'changed';
        }

        $unique = array_values(array_unique(array_values($states)));
        $state = $unique === ['empty']
            ? 'all_empty'
            : ($unique === ['exact'] ? 'all_exact' : 'mixed');

        return ['state' => $state, 'symbols' => $states];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $intent */
    private function publishPreparedIntent(string $runDirectory, array $manifest, array $intent): void
    {
        $date = (string) $intent['date'];
        $expectations = (array) $intent['expectations'];

        DB::transaction(function () use ($runDirectory, $manifest, $date, $expectations): void {
            $preflight = $this->classifyPersistedSlices($date, $expectations, true);
            if ($preflight['state'] !== 'all_empty') {
                throw new RuntimeException('Recovery publication preflight found a non-empty prepared slice.');
            }

            foreach (array_keys($expectations) as $symbol) {
                $symbol = (string) $symbol;
                // This is deliberately inside the transaction and immediately
                // before insertion. The exact decoded object validated here is
                // the object inserted below.
                [$candidate] = $this->loadBoundCandidate($runDirectory, $manifest, $symbol);
                $rows = (array) ($candidate['rows'] ?? []);
                $persisted = $this->candidatePersistedRows($symbol, $date, $rows);
                if ($this->canonicalJson($this->sliceExpectation($persisted)) !== $this->canonicalJson($expectations[$symbol])) {
                    throw new RuntimeException("Prepared publication expectation changed for {$symbol}.");
                }
                unset($persisted);

                $expiries = collect($rows)->pluck('expiration_date')->unique()->sort()->values();
                $now = now('UTC');
                DB::table('option_expirations')->insertOrIgnore($expiries->map(fn (string $expiry): array => [
                    'symbol' => $symbol,
                    'expiration_date' => $expiry,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());

                $expirationIds = DB::table('option_expirations')
                    ->where('symbol', $symbol)
                    ->whereIn('expiration_date', $expiries->all())
                    ->pluck('id', 'expiration_date');
                if ($expirationIds->count() !== $expiries->count()) {
                    throw new RuntimeException("Recovery could not resolve every expiration ID for {$symbol}.");
                }

                foreach (array_chunk($rows, 1000) as $rowChunk) {
                    $inserts = [];
                    foreach ($rowChunk as $row) {
                        $expiry = (string) $row['expiration_date'];
                        $inserts[] = [
                            'expiration_id' => (int) $expirationIds[$expiry],
                            'data_date' => $date,
                            'data_timestamp' => $row['data_timestamp'],
                            'option_type' => $row['option_type'],
                            'strike' => $row['strike'],
                            'open_interest' => $row['open_interest'],
                            'volume' => $row['volume'],
                            'gamma' => $row['gamma'],
                            'delta' => $row['delta'],
                            'vega' => $row['vega'],
                            'iv' => $row['iv'],
                            'underlying_price' => $row['underlying_price'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    DB::table('option_chain_data')->insert($inserts);
                    unset($inserts, $rowChunk);
                }

                $actual = $this->persistedSliceRows($symbol, $date, true);
                if ($this->canonicalJson($this->sliceExpectation($actual)) !== $this->canonicalJson($expectations[$symbol])) {
                    throw new RuntimeException("Persisted recovery slice differs from candidate for {$symbol}.");
                }
                unset($actual, $candidate, $rows, $expirationIds, $expiries);
            }

            $final = $this->classifyPersistedSlices($date, $expectations, true);
            if ($final['state'] !== 'all_exact') {
                throw new RuntimeException('Recovery publication transaction did not produce every prepared slice.');
            }
        }, 1);
    }

    /** @param array<string,mixed> $intent */
    private function rollbackPreparedIntent(array $intent): void
    {
        $date = (string) $intent['date'];
        $expectations = (array) $intent['expectations'];

        DB::transaction(function () use ($date, $expectations): void {
            $preflight = $this->classifyPersistedSlices($date, $expectations, true);
            if ($preflight['state'] !== 'all_exact') {
                throw new RuntimeException('Recovery rollback preflight found a changed prepared slice.');
            }

            foreach ($expectations as $symbol => $expected) {
                $expirationIds = DB::table('option_expirations')
                    ->where('symbol', (string) $symbol)
                    ->pluck('id');
                $deleted = DB::table('option_chain_data')
                    ->whereIn('expiration_id', $expirationIds)
                    ->whereDate('data_date', $date)
                    ->delete();
                if ($deleted !== (int) ($expected['rows'] ?? -1)) {
                    throw new RuntimeException("Recovery rollback deleted an unexpected row count for {$symbol}.");
                }
                if ($this->persistedSliceRows((string) $symbol, $date, false) !== []) {
                    throw new RuntimeException("Recovery rollback did not fully remove {$symbol}.");
                }
            }

            $final = $this->classifyPersistedSlices($date, $expectations, false);
            if ($final['state'] !== 'all_empty') {
                throw new RuntimeException('Recovery rollback transaction did not empty every prepared slice.');
            }
        }, 1);
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $intent @return array<string,mixed> */
    private function finalizePublishArtifacts(string $runDirectory, array $manifest, array $intent): array
    {
        $persistedSymbols = [];
        $published = [];
        foreach ((array) $intent['expectations'] as $symbol => $expectation) {
            $expectation = (array) $expectation;
            $published[(string) $symbol] = (int) $expectation['rows'];
            $persistedSymbols[(string) $symbol] = [
                'rows' => (int) $expectation['rows'],
                'natural_key_sha256' => (string) $expectation['natural_key_sha256'],
                'sha256' => (string) $expectation['full_slice_sha256'],
            ];
        }
        ksort($published);
        ksort($persistedSymbols);
        $persistedSha = hash('sha256', $this->canonicalJson(array_map(
            static fn (array $symbol): string => (string) $symbol['sha256'],
            $persistedSymbols,
        )));

        $path = $runDirectory.'/publish-receipt.json';
        if (is_file($path)) {
            $receipt = $this->validatedPublishReceipt($path);
            if (
                (string) ($receipt['candidate_sha256'] ?? '') !== (string) $intent['candidate_sha256']
                || (string) ($receipt['date'] ?? '') !== (string) $intent['date']
                || $this->canonicalJson($receipt['symbols'] ?? []) !== $this->canonicalJson($published)
                || $this->canonicalJson($receipt['persisted_symbols'] ?? []) !== $this->canonicalJson($persistedSymbols)
            ) {
                throw new RuntimeException('Existing publication receipt conflicts with the prepared intent.');
            }
        } else {
            $receipt = [
                'artifact_version' => self::ARTIFACT_VERSION,
                'candidate_sha256' => (string) $intent['candidate_sha256'],
                'published_at' => now('UTC')->toIso8601String(),
                'date' => (string) $intent['date'],
                'symbols' => $published,
                'persisted_sha256' => $persistedSha,
                'persisted_symbols' => $persistedSymbols,
            ];
            $receipt['receipt_sha256'] = hash('sha256', $this->canonicalJson($receipt));
            $this->writeJson($path, $receipt);
        }

        $manifest['status'] = 'published';
        $manifest['published_at'] = $receipt['published_at'];
        $this->writeJson($runDirectory.'/manifest.json', $manifest);

        return $receipt;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $intent @return array<string,mixed> */
    private function finalizeRollbackArtifacts(string $runDirectory, array $manifest, array $intent): array
    {
        $deleted = [];
        foreach ((array) $intent['expectations'] as $symbol => $expectation) {
            $deleted[(string) $symbol] = (int) ($expectation['rows'] ?? 0);
        }
        ksort($deleted);
        $emptySha = hash('sha256', $this->canonicalJson([]));
        $path = $runDirectory.'/rollback-receipt.json';

        if (is_file($path)) {
            $rollback = $this->validatedRollbackReceipt($path);
            if (
                (string) ($rollback['candidate_sha256'] ?? '') !== (string) $intent['candidate_sha256']
                || (string) ($rollback['date'] ?? '') !== (string) $intent['date']
                || $this->canonicalJson($rollback['deleted'] ?? []) !== $this->canonicalJson($deleted)
            ) {
                throw new RuntimeException('Existing rollback receipt conflicts with the prepared intent.');
            }
        } else {
            $rollback = [
                'artifact_version' => self::ARTIFACT_VERSION,
                'candidate_sha256' => (string) $intent['candidate_sha256'],
                'rolled_back_at' => now('UTC')->toIso8601String(),
                'date' => (string) $intent['date'],
                'deleted' => $deleted,
                'empty_slice_sha256' => $emptySha,
            ];
            $rollback['receipt_sha256'] = hash('sha256', $this->canonicalJson($rollback));
            $this->writeJson($path, $rollback);
        }

        $manifest['status'] = 'rolled_back';
        $manifest['rolled_back_at'] = $rollback['rolled_back_at'];
        $this->writeJson($runDirectory.'/manifest.json', $manifest);

        return $rollback;
    }

    /** @return array<string,mixed> */
    private function validatedPublishReceipt(string $path): array
    {
        $receipt = $this->validatedReceiptSelfHash($path, 'publication');
        $candidateSha = strtolower((string) ($receipt['candidate_sha256'] ?? ''));
        $date = (string) ($receipt['date'] ?? '');
        $persistedSymbols = (array) ($receipt['persisted_symbols'] ?? []);
        ksort($persistedSymbols);
        if (
            (int) ($receipt['artifact_version'] ?? 0) !== self::ARTIFACT_VERSION
            || ! $this->validSha256($candidateSha)
            || ! $this->isStrictDate($date)
            || $persistedSymbols === []
        ) {
            throw new RuntimeException('Recovery publication receipt schema is invalid.');
        }

        $published = [];
        foreach ($persistedSymbols as $symbol => $expected) {
            $expected = (array) $expected;
            if (
                Symbols::canon((string) $symbol) !== (string) $symbol
                || (int) ($expected['rows'] ?? 0) <= 0
                || ! $this->validSha256((string) ($expected['natural_key_sha256'] ?? ''))
                || ! $this->validSha256((string) ($expected['sha256'] ?? ''))
            ) {
                throw new RuntimeException("Recovery publication receipt is invalid for {$symbol}.");
            }
            $published[(string) $symbol] = (int) $expected['rows'];
        }
        ksort($published);
        if ($this->canonicalJson($receipt['symbols'] ?? []) !== $this->canonicalJson($published)) {
            throw new RuntimeException('Recovery publication receipt symbol counts are invalid.');
        }
        $calculatedOverall = hash('sha256', $this->canonicalJson(array_map(
            static fn (array $symbol): string => (string) ($symbol['sha256'] ?? ''),
            $persistedSymbols,
        )));
        if (! hash_equals(strtolower((string) ($receipt['persisted_sha256'] ?? '')), $calculatedOverall)) {
            throw new RuntimeException('Recovery persisted receipt summary is invalid.');
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function validatedRollbackReceipt(string $path): array
    {
        $receipt = $this->validatedReceiptSelfHash($path, 'rollback');
        if (
            (int) ($receipt['artifact_version'] ?? 0) !== self::ARTIFACT_VERSION
            || ! $this->validSha256((string) ($receipt['candidate_sha256'] ?? ''))
            || ! $this->isStrictDate((string) ($receipt['date'] ?? ''))
            || ! hash_equals(
                (string) ($receipt['empty_slice_sha256'] ?? ''),
                hash('sha256', $this->canonicalJson([])),
            )
        ) {
            throw new RuntimeException('Recovery rollback receipt schema is invalid.');
        }

        return $receipt;
    }

    /** @return array<string,mixed> */
    private function validatedReceiptSelfHash(string $path, string $label): array
    {
        $receipt = $this->readJson($path);
        $claimed = strtolower((string) ($receipt['receipt_sha256'] ?? ''));
        $unsigned = $receipt;
        unset($unsigned['receipt_sha256']);
        $actual = hash('sha256', $this->canonicalJson($unsigned));
        if (! $this->validSha256($claimed) || ! hash_equals($claimed, $actual)) {
            throw new RuntimeException("Recovery {$label} receipt hash is invalid.");
        }

        return $receipt;
    }

    /**
     * @param  array<string,array{contracts:array<int,array<string,mixed>>,meta:array<string,mixed>}>  $archiveGroups
     * @return array<string,mixed>
     */
    private function captureSymbol(
        string $symbol,
        CarbonImmutable $target,
        CarbonImmutable $end,
        array $archiveGroups,
        string $runDirectory,
    ): array {
        $this->assertSafeCaptureWindow($target);
        $symbolDirectory = $runDirectory.'/'.$this->artifactSymbol($symbol);
        $this->makePrivateDirectory($symbolDirectory);

        $reference = $this->provider->referenceContracts(
            $symbol,
            $target->toDateString(),
            $end->toDateString(),
        );
        $this->assertSafeCaptureWindow($target);
        $catalog = $this->validatedReferenceCatalog(
            $symbol,
            $target->toDateString(),
            $end->toDateString(),
            (array) ($reference['contracts'] ?? []),
        );

        $referenceRelative = $this->artifactSymbol($symbol).'/reference.json.gz';
        $referencePath = $runDirectory.'/'.$referenceRelative;
        $this->writeGzipJson($referencePath, [
            'symbol' => $symbol,
            'date' => $target->toDateString(),
            'end_date' => $end->toDateString(),
            'contracts' => $reference['contracts'],
            'provider_meta' => $reference['meta'] ?? [],
        ]);

        $targetDate = $target->toDateString();
        $closingSpot = $this->exactClosingSpot($symbol, $targetDate);
        $rows = [];
        $expirySources = [];
        $rawCoverage = [];

        $targetExpected = $catalog['by_expiry_side'][$targetDate] ?? null;
        $targetGroup = $archiveGroups[$targetDate] ?? null;
        if (is_array($targetExpected) && ! is_array($targetGroup)) {
            throw new RuntimeException("Recovery archive has no exact {$targetDate} expiration for {$symbol}.");
        }
        if (! is_array($targetExpected) && is_array($targetGroup)) {
            throw new RuntimeException("Reference catalog has no exact target expiration for {$symbol}.");
        }

        $archiveWitnessExpiries = [];
        if (! is_array($targetExpected)) {
            foreach ($archiveGroups as $archiveExpiry => $archiveGroup) {
                if (! isset($catalog['by_expiry_side'][$archiveExpiry]) || ! is_array($archiveGroup)) {
                    continue;
                }

                $archiveRows = (array) ($archiveGroup['contracts'] ?? []);
                $this->assertArchiveGroupIsPostClose($symbol, $target, $archiveGroup);
                $this->assertArchiveDefinitions(
                    $symbol,
                    (string) $archiveExpiry,
                    $archiveRows,
                    $catalog['by_ticker'],
                );
                $this->assertExactTickerCoverage(
                    $symbol,
                    (string) $archiveExpiry,
                    $this->referenceTickersForExpiry($catalog['by_expiry_side'][$archiveExpiry]),
                    $this->archiveTickers($archiveRows),
                    'archive_witness',
                );
                $this->assertSpotAgreement(
                    $symbol,
                    $closingSpot,
                    $this->archiveGroupSpot($symbol, $archiveRows, $closingSpot),
                );
                $archiveWitnessExpiries[] = (string) $archiveExpiry;
            }

            if ($archiveWitnessExpiries === []) {
                throw new RuntimeException("Recovery archive has no post-close catalog witness for {$symbol}.");
            }
            sort($archiveWitnessExpiries);
        }

        if (is_array($targetExpected) && is_array($targetGroup)) {
            $this->assertArchiveGroupIsPostClose($symbol, $target, $targetGroup);
            $targetArchiveRows = (array) ($targetGroup['contracts'] ?? []);
            $archiveSpot = $this->archiveGroupSpot($symbol, $targetArchiveRows, $closingSpot);
            $this->assertSpotAgreement($symbol, $closingSpot, $archiveSpot);
            $this->assertExactTickerCoverage(
                $symbol,
                $targetDate,
                $this->referenceTickersForExpiry($targetExpected),
                $this->archiveTickers($targetArchiveRows),
                'archive',
            );
            $this->assertArchiveDefinitions($symbol, $targetDate, $targetArchiveRows, $catalog['by_ticker']);
            foreach ($targetArchiveRows as $row) {
                $rows[] = $this->normalizeArchiveRow(
                    $symbol,
                    $targetDate,
                    $archiveSpot,
                    $row,
                    (array) ($targetGroup['meta'] ?? []),
                );
            }
            $expirySources[$targetDate] = 'archive';
            $rawCoverage[$targetDate] = [
                'expected' => count($this->referenceTickersForExpiry($targetExpected)),
                'recovered' => count($targetArchiveRows),
            ];
        }

        $snapshotPartitions = [];
        $overlapChecks = 0;
        $overlapVolumeDifferences = 0;
        $overlapCurrentLower = 0;
        $snapshotMissingVolumes = 0;
        $snapshotSpot = null;
        foreach ($catalog['expiries'] as $expiry) {
            if ($expiry === $targetDate) {
                continue;
            }

            $futureArchiveGroup = (array) ($archiveGroups[$expiry] ?? []);
            $futureArchiveRows = (array) ($futureArchiveGroup['contracts'] ?? []);
            $futureArchiveByTicker = [];
            foreach ($futureArchiveRows as $futureArchiveRow) {
                $archiveTicker = strtoupper(trim((string) ($futureArchiveRow['contract_symbol'] ?? '')));
                if ($archiveTicker !== '') {
                    $futureArchiveByTicker[$archiveTicker] = $futureArchiveRow;
                }
            }
            $futureArchiveRequestId = trim((string) (
                $futureArchiveGroup['meta']['selected_request_id'] ?? ''
            ));
            $futureArchiveCapturedAt = trim((string) (
                $futureArchiveGroup['meta']['selected_captured_at'] ?? ''
            ));
            $futureArchiveGroupValidated = false;

            foreach (['call', 'put'] as $side) {
                $this->assertSafeCaptureWindow($target);
                $capturedAt = now('UTC')->toIso8601String();
                $partition = $this->provider->snapshotPartition($symbol, $expiry, $side);
                $this->assertSafeCaptureWindow($target);
                $contracts = (array) ($partition['contracts'] ?? []);
                if ($contracts === []) {
                    throw new RuntimeException("Current snapshot partition is empty for {$symbol} {$expiry} {$side}.");
                }

                $expectedTickers = array_keys($catalog['by_expiry_side'][$expiry][$side] ?? []);
                $actualTickers = $this->snapshotTickers($contracts);
                $this->assertExactTickerCoverage(
                    $symbol,
                    $expiry,
                    $expectedTickers,
                    $actualTickers,
                    "current_snapshot_{$side}",
                );
                $this->assertSnapshotDefinitions(
                    $symbol,
                    $expiry,
                    $side,
                    $contracts,
                    $catalog['by_ticker'],
                );

                $partitionTimestamp = $this->validatedPartitionTimestamp(
                    $symbol,
                    $expiry,
                    $side,
                    $target,
                    $contracts,
                );
                $providerSpot = $this->positiveNumberOrNull($partition['spot'] ?? null);
                if ($providerSpot !== null) {
                    $this->assertSpotAgreement($symbol, $closingSpot, $providerSpot);
                    if (
                        $snapshotSpot !== null
                        && abs($providerSpot - $snapshotSpot) / $snapshotSpot > 0.0001
                    ) {
                        throw new RuntimeException("Current snapshot partitions disagree on spot for {$symbol}.");
                    }
                    $snapshotSpot ??= $providerSpot;
                }
                $this->recordArchiveVolumeOverlap(
                    $symbol,
                    $expiry,
                    $side,
                    $contracts,
                    (array) (($archiveGroups[$expiry]['contracts'] ?? [])),
                    $overlapChecks,
                    $overlapVolumeDifferences,
                    $overlapCurrentLower,
                    $snapshotMissingVolumes,
                );

                $snapshotPartitions[$expiry][$side] = [
                    'captured_at' => $capturedAt,
                    'market_timestamp' => $partitionTimestamp,
                    'provider_spot' => $providerSpot,
                    'contracts' => $contracts,
                    'provider_meta' => $partition['meta'] ?? [],
                ];
                foreach ($contracts as $contract) {
                    $contractTicker = strtoupper(trim((string) ($contract['details']['ticker'] ?? '')));
                    $archiveFallbackRow = $futureArchiveByTicker[$contractTicker] ?? null;
                    $normalized = $this->normalizeSnapshotRow(
                        $symbol,
                        $expiry,
                        $side,
                        $closingSpot,
                        $partitionTimestamp,
                        $contract,
                        is_array($archiveFallbackRow) ? $archiveFallbackRow : null,
                        $futureArchiveRequestId,
                        $futureArchiveCapturedAt,
                    );
                    $fallbackMetrics = (array) ($normalized['metric_fallbacks'] ?? []);
                    if ($fallbackMetrics !== []) {
                        if (! is_array($archiveFallbackRow)) {
                            throw new RuntimeException(
                                "Recovery metric fallback has no exact archive ticker for {$contractTicker}."
                            );
                        }
                        if (! $futureArchiveGroupValidated) {
                            $this->assertArchiveGroupIsPostClose($symbol, $target, $futureArchiveGroup);
                            $futureArchiveGroupValidated = true;
                        }
                        $this->assertArchiveDefinitions(
                            $symbol,
                            $expiry,
                            [$archiveFallbackRow],
                            $catalog['by_ticker'],
                        );
                    }
                    $rows[] = $normalized;
                }
            }

            $expirySources[$expiry] = 'current_snapshot';
            $rawCoverage[$expiry] = [
                'expected' => count($this->referenceTickersForExpiry($catalog['by_expiry_side'][$expiry])),
                'recovered' => count($snapshotPartitions[$expiry]['call']['contracts'])
                    + count($snapshotPartitions[$expiry]['put']['contracts']),
            ];
        }

        $archiveRelative = $this->artifactSymbol($symbol).'/archive-groups.json.gz';
        $snapshotRelative = $this->artifactSymbol($symbol).'/current-snapshots.json.gz';
        $this->writeGzipJson($runDirectory.'/'.$archiveRelative, [
            'symbol' => $symbol,
            'groups' => $archiveGroups,
        ]);
        $this->writeGzipJson($runDirectory.'/'.$snapshotRelative, [
            'symbol' => $symbol,
            'partitions' => $snapshotPartitions,
        ]);
        $referenceFileSha = hash_file('sha256', $referencePath);
        $archiveFileSha = hash_file('sha256', $runDirectory.'/'.$archiveRelative);
        $snapshotFileSha = hash_file('sha256', $runDirectory.'/'.$snapshotRelative);
        if (! is_string($referenceFileSha) || ! is_string($archiveFileSha) || ! is_string($snapshotFileSha)) {
            throw new RuntimeException("Unable to hash recovery evidence artifacts for {$symbol}.");
        }

        $rows = $this->filterAndSortRows($rows, $closingSpot);
        $archiveMetricFallbacks = ['gamma' => 0, 'delta' => 0, 'vega' => 0, 'iv' => 0];
        foreach ($rows as $row) {
            foreach ((array) ($row['metric_fallbacks'] ?? []) as $fallbackMetric) {
                $archiveMetricFallbacks[(string) $fallbackMetric]++;
            }
        }
        $candidate = [
            'artifact_version' => self::ARTIFACT_VERSION,
            'symbol' => $symbol,
            'date' => $targetDate,
            'end_date' => $end->toDateString(),
            'captured_at' => now('UTC')->toIso8601String(),
            'underlying_price' => $closingSpot,
            'underlying_price_source' => is_array($targetExpected)
                ? 'archive_group_for_target_exact_prices_daily_close_for_future'
                : 'prices_daily_close_for_all_current_snapshot_expiries',
            'source_policy' => 'archive_exact_target_else_current_snapshot_v1',
            'archive_witness_expiries' => $archiveWitnessExpiries,
            'expected_expiries' => $catalog['expiries'],
            'expiry_sources' => $expirySources,
            'raw_ticker_coverage' => $rawCoverage,
            'reference_contracts' => count($catalog['by_ticker']),
            'archive_future_overlap_checks' => $overlapChecks,
            'archive_future_volume_differences' => $overlapVolumeDifferences,
            'archive_future_current_lower' => $overlapCurrentLower,
            'current_snapshot_missing_volumes' => $snapshotMissingVolumes,
            'archive_future_metric_fallbacks' => $archiveMetricFallbacks,
            'source_artifacts' => [
                'reference' => ['file' => $referenceRelative, 'sha256' => $referenceFileSha],
                'archive' => ['file' => $archiveRelative, 'sha256' => $archiveFileSha],
                'snapshot' => ['file' => $snapshotRelative, 'sha256' => $snapshotFileSha],
            ],
            'filter_config' => $this->filterConfiguration(),
            'previous_session' => $this->previousSessionStats($symbol, $targetDate),
            'rows' => $rows,
        ];
        $preflight = $this->validateCandidate(
            $candidate,
            [
                'date' => $targetDate,
                'end_date' => $end->toDateString(),
                'symbols' => [$symbol],
            ],
            $symbol,
            ['symbol' => $symbol, 'groups' => $archiveGroups],
            [
                'symbol' => $symbol,
                'date' => $targetDate,
                'end_date' => $end->toDateString(),
                'contracts' => $reference['contracts'],
                'provider_meta' => $reference['meta'] ?? [],
            ],
        );
        if (! ($preflight['ok'] ?? false)) {
            throw new RuntimeException(implode(',', (array) ($preflight['errors'] ?? [])));
        }

        $candidateRelative = $this->artifactSymbol($symbol).'/candidate.json.gz';
        $candidatePath = $runDirectory.'/'.$candidateRelative;
        $this->writeGzipJson($candidatePath, $candidate);
        $candidateFileSha = hash_file('sha256', $candidatePath);
        if (! is_string($candidateFileSha)) {
            throw new RuntimeException("Unable to hash candidate artifact for {$symbol}.");
        }

        return [
            'candidate_file' => $candidateRelative,
            'candidate_file_sha256' => $candidateFileSha,
            'candidate_sha256' => $preflight['candidate_sha256'],
            'candidate_rows' => count($rows),
            'reference_file' => $referenceRelative,
            'reference_file_sha256' => $referenceFileSha,
            'archive_file' => $archiveRelative,
            'archive_file_sha256' => $archiveFileSha,
            'snapshot_file' => $snapshotRelative,
            'snapshot_file_sha256' => $snapshotFileSha,
            'expiry_sources' => $expirySources,
        ];
    }

    /** @return array{by_ticker:array<string,array<string,mixed>>,by_expiry_side:array<string,array<string,array<string,array<string,mixed>>>>,expiries:array<int,string>} */
    private function validatedReferenceCatalog(
        string $symbol,
        string $targetDate,
        string $endDate,
        array $contracts,
    ): array {
        if ($contracts === []) {
            throw new RuntimeException("Reference catalog is empty for {$symbol}.");
        }

        $byTicker = [];
        $byExpirySide = [];
        $canonicalKeys = [];
        foreach ($contracts as $contract) {
            if (! is_array($contract)) {
                throw new RuntimeException("Reference catalog contains a non-object for {$symbol}.");
            }
            $ticker = strtoupper(trim((string) ($contract['ticker'] ?? '')));
            $underlying = Symbols::canon((string) ($contract['underlying_ticker'] ?? ''));
            $expiry = substr((string) ($contract['expiration_date'] ?? ''), 0, 10);
            $side = strtolower(trim((string) ($contract['contract_type'] ?? '')));
            $strike = (float) ($contract['strike_price'] ?? 0);
            $shares = $contract['shares_per_contract'] ?? null;
            if (
                $ticker === ''
                || $underlying !== $symbol
                || $expiry < $targetDate
                || $expiry > $endDate
                || ! in_array($side, ['call', 'put'], true)
                || $strike <= 0
            ) {
                throw new RuntimeException("Reference catalog contains an out-of-scope contract for {$symbol}.");
            }
            if (! is_numeric($shares) || abs((float) $shares - 100.0) > 0.000001) {
                throw new RuntimeException("Adjusted/non-100-share contract {$ticker} blocks recovery.");
            }
            if (! empty($contract['additional_underlyings'] ?? [])) {
                throw new RuntimeException("Adjusted contract {$ticker} with additional underlyings blocks recovery.");
            }
            if (isset($byTicker[$ticker]) && $this->canonicalJson($byTicker[$ticker]) !== $this->canonicalJson($contract)) {
                throw new RuntimeException("Reference catalog has conflicting payloads for {$ticker}.");
            }

            $canonicalKey = $expiry.'|'.$side.'|'.$this->strikeKey($strike);
            if (isset($canonicalKeys[$canonicalKey]) && $canonicalKeys[$canonicalKey] !== $ticker) {
                throw new RuntimeException("Reference contracts collide at canonical key {$canonicalKey}.");
            }
            $canonicalKeys[$canonicalKey] = $ticker;
            $byTicker[$ticker] = $contract;
            $byExpirySide[$expiry][$side][$ticker] = $contract;
        }

        ksort($byTicker);
        ksort($byExpirySide);
        foreach ($byExpirySide as $expiry => &$sides) {
            if (empty($sides['call']) || empty($sides['put'])) {
                throw new RuntimeException("Reference expiration {$expiry} is one-sided for {$symbol}.");
            }
            ksort($sides['call']);
            ksort($sides['put']);
        }
        unset($sides);

        return [
            'by_ticker' => $byTicker,
            'by_expiry_side' => $byExpirySide,
            'expiries' => array_keys($byExpirySide),
        ];
    }

    /** @param array<string,array<string,array<string,mixed>>> $expirySides @return array<int,string> */
    private function referenceTickersForExpiry(array $expirySides): array
    {
        $tickers = array_values(array_unique(array_merge(
            array_keys((array) ($expirySides['call'] ?? [])),
            array_keys((array) ($expirySides['put'] ?? [])),
        )));
        sort($tickers);

        return $tickers;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,string> */
    private function archiveTickers(array $rows): array
    {
        $tickers = [];
        foreach ($rows as $row) {
            $ticker = strtoupper(trim((string) ($row['contract_symbol'] ?? '')));
            if ($ticker === '') {
                throw new RuntimeException('Archive contract ticker is empty.');
            }
            if (isset($tickers[$ticker])) {
                throw new RuntimeException("Archive contains duplicate ticker {$ticker}.");
            }
            $tickers[$ticker] = true;
        }
        $result = array_keys($tickers);
        sort($result);

        return $result;
    }

    /** @param array<int,array<string,mixed>> $contracts @return array<int,string> */
    private function snapshotTickers(array $contracts): array
    {
        $tickers = [];
        foreach ($contracts as $contract) {
            $ticker = strtoupper(trim((string) ($contract['details']['ticker'] ?? '')));
            if ($ticker === '') {
                throw new RuntimeException('Snapshot contract ticker is empty.');
            }
            if (isset($tickers[$ticker])) {
                throw new RuntimeException("Snapshot contains duplicate ticker {$ticker}.");
            }
            $tickers[$ticker] = true;
        }
        $result = array_keys($tickers);
        sort($result);

        return $result;
    }

    /** @param array<int,string> $expected @param array<int,string> $actual */
    private function assertExactTickerCoverage(
        string $symbol,
        string $expiry,
        array $expected,
        array $actual,
        string $source,
    ): void {
        sort($expected);
        sort($actual);
        if ($expected === $actual) {
            return;
        }

        $missing = array_values(array_diff($expected, $actual));
        $unexpected = array_values(array_diff($actual, $expected));
        throw new RuntimeException(sprintf(
            '%s ticker coverage mismatch for %s %s (missing=%d unexpected=%d).',
            $source,
            $symbol,
            $expiry,
            count($missing),
            count($unexpected),
        ));
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,array<string,mixed>> $reference */
    private function assertArchiveDefinitions(
        string $symbol,
        string $expiry,
        array $rows,
        array $reference,
    ): void {
        foreach ($rows as $row) {
            $ticker = strtoupper(trim((string) ($row['contract_symbol'] ?? '')));
            $expected = $reference[$ticker] ?? null;
            if (! is_array($expected)) {
                throw new RuntimeException("Archive ticker {$ticker} is absent from the reference catalog.");
            }
            if (
                Symbols::canon((string) ($row['symbol'] ?? '')) !== $symbol
                || substr((string) ($row['expiration_date'] ?? ''), 0, 10) !== $expiry
                || strtolower((string) ($row['contract_type'] ?? '')) !== strtolower((string) $expected['contract_type'])
                || abs((float) ($row['strike_price'] ?? 0) - (float) $expected['strike_price']) > 0.000001
            ) {
                throw new RuntimeException("Archive definition conflicts with reference ticker {$ticker}.");
            }
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function archiveGroupSpot(string $symbol, array $rows, float $fallbackSpot): float
    {
        $spot = null;
        foreach ($rows as $row) {
            $candidate = $this->positiveNumberOrNull($row['underlying_price'] ?? null);
            if ($candidate === null) {
                continue;
            }
            if ($spot !== null && abs($candidate - $spot) / $spot > 0.0001) {
                throw new RuntimeException("Archive rows disagree on spot for {$symbol}.");
            }
            $spot ??= $candidate;
        }

        return $spot ?? $fallbackSpot;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,array<string,mixed>> $reference */
    private function assertSnapshotDefinitions(
        string $symbol,
        string $expiry,
        string $side,
        array $rows,
        array $reference,
    ): void {
        foreach ($rows as $row) {
            $details = (array) ($row['details'] ?? []);
            $ticker = strtoupper(trim((string) ($details['ticker'] ?? '')));
            $expected = $reference[$ticker] ?? null;
            $actualUnderlying = Symbols::canon((string) (
                $row['underlying_asset']['ticker']
                    ?? $details['underlying_ticker']
                    ?? ''
            ));
            if (! is_array($expected)) {
                throw new RuntimeException("Snapshot ticker {$ticker} is absent from the reference catalog.");
            }
            if (
                $actualUnderlying !== $symbol
                || substr((string) ($details['expiration_date'] ?? ''), 0, 10) !== $expiry
                || strtolower((string) ($details['contract_type'] ?? '')) !== $side
                || strtolower((string) ($expected['contract_type'] ?? '')) !== $side
                || abs((float) ($details['strike_price'] ?? 0) - (float) $expected['strike_price']) > 0.000001
            ) {
                throw new RuntimeException("Snapshot definition conflicts with reference ticker {$ticker}.");
            }
            $shares = $details['shares_per_contract'] ?? $expected['shares_per_contract'] ?? null;
            if (! is_numeric($shares) || abs((float) $shares - 100.0) > 0.000001) {
                throw new RuntimeException("Snapshot adjusted/non-100-share contract {$ticker} blocks recovery.");
            }
            if (! empty($details['additional_underlyings'] ?? [])) {
                throw new RuntimeException("Snapshot adjusted contract {$ticker} blocks recovery.");
            }
        }
    }

    /** @param array<string,mixed> $group */
    private function assertArchiveGroupIsPostClose(
        string $symbol,
        CarbonImmutable $target,
        array $group,
    ): void {
        $captured = trim((string) ($group['meta']['selected_captured_at'] ?? ''));
        if ($captured === '') {
            throw new RuntimeException("Archive selected capture is empty for {$symbol}.");
        }
        try {
            $capturedAt = CarbonImmutable::parse($captured, 'UTC')->utc();
        } catch (\Throwable $exception) {
            throw new RuntimeException("Archive selected capture is invalid for {$symbol}.", previous: $exception);
        }
        $ny = $capturedAt->setTimezone('America/New_York');
        $cutoff = $target->setTimezone('America/New_York')->setTime(16, 15);
        $end = $target->setTimezone('America/New_York')->endOfDay();
        if ($ny->lt($cutoff) || $ny->gt($end)) {
            throw new RuntimeException("Archive capture for {$symbol} is outside the target post-close window.");
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $meta @return array<string,mixed> */
    private function normalizeArchiveRow(
        string $symbol,
        string $targetDate,
        float $closingSpot,
        array $row,
        array $meta,
    ): array {
        $rowSymbol = Symbols::canon((string) ($row['symbol'] ?? ''));
        $expiry = substr((string) ($row['expiration_date'] ?? ''), 0, 10);
        $side = strtolower(trim((string) ($row['contract_type'] ?? '')));
        $ticker = strtoupper(trim((string) ($row['contract_symbol'] ?? '')));
        if ($rowSymbol !== $symbol || $expiry !== $targetDate || ! in_array($side, ['call', 'put'], true) || $ticker === '') {
            throw new RuntimeException("Archive row identity is invalid for {$symbol}.");
        }

        $spot = $this->positiveNumberOrNull($row['underlying_price'] ?? null) ?? $closingSpot;
        $this->assertSpotAgreement($symbol, $closingSpot, $spot);

        $gamma = $row['gamma'] ?? null;

        return $this->normalizedCandidateRow([
            'expiration_date' => $expiry,
            'option_ticker' => $ticker,
            'option_type' => $side,
            'strike' => $row['strike_price'] ?? null,
            'open_interest' => $row['open_interest'] ?? 0,
            'volume' => $row['volume'] ?? 0,
            'gamma' => $gamma,
            'delta' => $gamma === null ? null : ($row['delta'] ?? null),
            'vega' => $gamma === null ? null : ($row['vega'] ?? null),
            'iv' => $this->normalizeIv($row['implied_volatility'] ?? null),
            'underlying_price' => $spot,
            'data_timestamp' => $meta['selected_captured_at'] ?? $row['captured_at'] ?? null,
            'source' => 'archive',
            'source_request_id' => $meta['selected_request_id'] ?? $row['request_id'] ?? null,
        ]);
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function normalizeSnapshotRow(
        string $symbol,
        string $expiry,
        string $side,
        float $providerSpot,
        string $marketTimestamp,
        array $contract,
        ?array $archiveFallbackRow = null,
        ?string $archiveFallbackRequestId = null,
        ?string $archiveFallbackCapturedAt = null,
    ): array {
        $details = (array) ($contract['details'] ?? []);
        $actualSymbol = Symbols::canon((string) (
            $contract['underlying_asset']['ticker']
                ?? $details['underlying_ticker']
                ?? ''
        ));
        $actualExpiry = substr((string) ($details['expiration_date'] ?? ''), 0, 10);
        $actualSide = strtolower(trim((string) ($details['contract_type'] ?? '')));
        $ticker = strtoupper(trim((string) ($details['ticker'] ?? '')));
        if ($actualSymbol !== $symbol || $actualExpiry !== $expiry || $actualSide !== $side || $ticker === '') {
            throw new RuntimeException("Snapshot row identity is invalid for {$symbol} {$expiry} {$side}.");
        }

        $greeks = (array) ($contract['greeks'] ?? []);
        $archiveFallbackRow ??= [];
        $metricFallbacks = [];
        $gamma = $greeks['gamma'] ?? null;
        $delta = $greeks['delta'] ?? null;
        $vega = $greeks['vega'] ?? null;
        foreach (['gamma', 'delta', 'vega'] as $metric) {
            if (
                $this->metricMissing(${$metric})
                && ! $this->metricMissing($archiveFallbackRow[$metric] ?? null)
            ) {
                ${$metric} = $archiveFallbackRow[$metric];
                $metricFallbacks[] = $metric;
            }
        }
        if ($this->metricMissing($gamma)) {
            $delta = null;
            $vega = null;
            $metricFallbacks = array_values(array_diff($metricFallbacks, ['delta', 'vega']));
        }
        $iv = $this->normalizeIv($contract['implied_volatility'] ?? null);
        if ($iv === null) {
            $archiveIv = $this->normalizeIv($archiveFallbackRow['implied_volatility'] ?? null);
            if ($archiveIv !== null) {
                $iv = $archiveIv;
                $metricFallbacks[] = 'iv';
            }
        }

        return $this->normalizedCandidateRow([
            'expiration_date' => $expiry,
            'option_ticker' => $ticker,
            'option_type' => $side,
            'strike' => $details['strike_price'] ?? null,
            'open_interest' => $contract['open_interest'] ?? 0,
            'volume' => $this->snapshotVolume($contract),
            'gamma' => $gamma,
            'delta' => $delta,
            'vega' => $vega,
            'iv' => $iv,
            'underlying_price' => $providerSpot,
            'data_timestamp' => $marketTimestamp,
            'source' => 'current_snapshot',
            'source_request_id' => null,
            'metric_fallbacks' => $metricFallbacks,
            'metric_fallback_request_id' => $metricFallbacks === []
                ? null
                : $archiveFallbackRequestId,
            'metric_fallback_captured_at' => $metricFallbacks === []
                ? null
                : $archiveFallbackCapturedAt,
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizedCandidateRow(array $row): array
    {
        $strike = $this->positiveNumberOrNull($row['strike'] ?? null);
        $spot = $this->positiveNumberOrNull($row['underlying_price'] ?? null);
        if ($strike === null || $spot === null) {
            throw new RuntimeException('Recovery row has an invalid strike or underlying price.');
        }

        $side = strtolower((string) ($row['option_type'] ?? ''));
        if (! in_array($side, ['call', 'put'], true)) {
            throw new RuntimeException('Recovery row has an invalid option side.');
        }
        $openInterest = $this->nonNegativeInteger($row['open_interest'] ?? 0, 'open_interest');
        $volume = $this->nonNegativeInteger($row['volume'] ?? 0, 'volume');
        $gamma = $this->nullableFiniteNumber($row['gamma'] ?? null, 'gamma');
        $delta = $this->nullableFiniteNumber($row['delta'] ?? null, 'delta');
        $vega = $this->nullableFiniteNumber($row['vega'] ?? null, 'vega');
        $iv = $this->nullableFiniteNumber($row['iv'] ?? null, 'iv');
        if ($delta !== null && ($delta < -1.00000001 || $delta > 1.00000001)) {
            throw new RuntimeException('Recovery row delta is outside [-1,1].');
        }
        if ($iv !== null && $iv <= 0) {
            throw new RuntimeException('Recovery row contains an invalid IV.');
        }
        if (
            ($gamma !== null && abs($gamma) >= 10000)
            || ($delta !== null && abs($delta) >= 10000)
            || ($iv !== null && abs($iv) >= 10000)
            || ($vega !== null && abs($vega) >= 1.0e30)
            || $spot >= 100000000
        ) {
            throw new RuntimeException('Recovery row exceeds a destination numeric range.');
        }

        $timestamp = $this->normalizeUtcTimestamp($row['data_timestamp'] ?? null);
        $expiration = (string) ($row['expiration_date'] ?? '');
        $ticker = strtoupper(trim((string) ($row['option_ticker'] ?? '')));
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $expiration) !== 1 || $ticker === '') {
            throw new RuntimeException('Recovery row has an invalid expiration or ticker.');
        }

        return [
            'expiration_date' => $expiration,
            'option_ticker' => $ticker,
            'option_type' => $side,
            'strike' => $this->strikeKey($strike),
            'open_interest' => $openInterest,
            'volume' => $volume,
            'gamma' => $this->fixedDecimal($gamma, 8),
            'delta' => $this->fixedDecimal($delta, 8),
            'vega' => $this->fixedDecimal($vega, 8),
            'iv' => $this->fixedDecimal($iv, 8),
            'underlying_price' => $this->fixedDecimal($spot, 4),
            'data_timestamp' => $timestamp,
            'source' => (string) ($row['source'] ?? ''),
            'source_request_id' => ($row['source_request_id'] ?? null) === null
                ? null
                : (string) $row['source_request_id'],
            'metric_fallbacks' => array_values((array) ($row['metric_fallbacks'] ?? [])),
            'metric_fallback_request_id' => ($row['metric_fallback_request_id'] ?? null) === null
                ? null
                : (string) $row['metric_fallback_request_id'],
            'metric_fallback_captured_at' => ($row['metric_fallback_captured_at'] ?? null) === null
                ? null
                : $this->normalizeUtcTimestamp($row['metric_fallback_captured_at']),
        ];
    }

    private function metricMissing(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function normalizeIv(mixed $value): ?float
    {
        $iv = $this->nullableFiniteNumber($value, 'iv');
        if ($iv === null) {
            return null;
        }
        if ($iv > 1.0) {
            $iv /= 100.0;
        }

        return $iv > 0 ? $iv : null;
    }

    /** @param array<int,array<string,mixed>> $contracts */
    private function validatedPartitionTimestamp(
        string $symbol,
        string $expiry,
        string $side,
        CarbonImmutable $target,
        array $contracts,
    ): string {
        $timestamps = [];
        foreach ($contracts as $contract) {
            foreach ([
                $contract['underlying_asset']['last_updated'] ?? null,
                $contract['day']['last_updated'] ?? null,
                $contract['session']['last_updated'] ?? null,
                $contract['last_quote']['last_updated'] ?? null,
                $contract['last_quote']['sip_timestamp'] ?? null,
                $contract['last_quote']['participant_timestamp'] ?? null,
                $contract['last_trade']['sip_timestamp'] ?? null,
                $contract['last_trade']['participant_timestamp'] ?? null,
            ] as $rawTimestamp) {
                $parsed = $this->providerTimestamp($rawTimestamp);
                if ($parsed !== null) {
                    $timestamps[] = $parsed;
                }
            }
        }
        if ($timestamps === []) {
            throw new RuntimeException("Snapshot has no provider market timestamp for {$symbol} {$expiry} {$side}.");
        }
        usort(
            $timestamps,
            static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp(),
        );
        $latest = end($timestamps);
        $targetNy = $target->setTimezone('America/New_York');
        if (
            ! $latest instanceof CarbonImmutable
            || $latest->setTimezone('America/New_York')->toDateString() !== $targetNy->toDateString()
        ) {
            throw new RuntimeException("Snapshot market timestamp is outside the target session for {$symbol} {$expiry} {$side}.");
        }

        // Massive daily/session `last_updated` values are session-date markers
        // at midnight rather than closing instants. Once the target session is
        // proven and capture is still before the next open, persist the EOD
        // boundary used by the canonical chain.
        return $targetNy->setTime(16, 0)->utc()->format('Y-m-d H:i:s');
    }

    private function providerTimestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $numeric = (float) $value;
                if (! is_finite($numeric) || $numeric <= 0) {
                    return null;
                }
                $seconds = match (true) {
                    $numeric >= 1.0e17 => $numeric / 1.0e9,
                    $numeric >= 1.0e14 => $numeric / 1.0e6,
                    $numeric >= 1.0e11 => $numeric / 1.0e3,
                    default => $numeric,
                };

                return CarbonImmutable::createFromTimestampUTC((int) floor($seconds));
            }

            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertSpotAgreement(string $symbol, float $closingSpot, float $providerSpot): void
    {
        if ($closingSpot <= 0 || $providerSpot <= 0) {
            throw new RuntimeException("Recovery spot is missing for {$symbol}.");
        }
        $ratio = abs($providerSpot - $closingSpot) / $closingSpot;
        if ($ratio > self::MAX_SPOT_DIFFERENCE_RATIO) {
            throw new RuntimeException(sprintf(
                'Provider spot differs from exact close for %s by %.4f%%.',
                $symbol,
                $ratio * 100,
            ));
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $snapshotRows
     * @param  array<int,array<string,mixed>>  $archiveRows
     */
    private function recordArchiveVolumeOverlap(
        string $symbol,
        string $expiry,
        string $side,
        array $snapshotRows,
        array $archiveRows,
        int &$checks,
        int &$differences,
        int &$currentLower,
        int &$missing,
    ): void {
        $currentByTicker = [];
        foreach ($snapshotRows as $row) {
            $ticker = strtoupper(trim((string) ($row['details']['ticker'] ?? '')));
            $day = (array) ($row['day'] ?? []);
            $session = (array) ($row['session'] ?? []);
            $hasDayVolume = array_key_exists('volume', $day)
                && $day['volume'] !== null
                && $day['volume'] !== '';
            $hasSessionVolume = array_key_exists('volume', $session)
                && $session['volume'] !== null
                && $session['volume'] !== '';
            if (! $hasDayVolume && ! $hasSessionVolume) {
                $missing++;
                $currentByTicker[$ticker] = null;

                continue;
            }
            $currentByTicker[$ticker] = $hasDayVolume
                ? $this->nonNegativeInteger($day['volume'], 'volume')
                : $this->nonNegativeInteger($session['volume'], 'volume');
        }

        foreach ($archiveRows as $archiveRow) {
            if (strtolower((string) ($archiveRow['contract_type'] ?? '')) !== $side) {
                continue;
            }
            $ticker = strtoupper(trim((string) ($archiveRow['contract_symbol'] ?? '')));
            if (
                $ticker === ''
                || ! array_key_exists($ticker, $currentByTicker)
                || $currentByTicker[$ticker] === null
            ) {
                continue;
            }
            $archiveVolume = $this->nonNegativeInteger($archiveRow['volume'] ?? 0, 'volume');
            if ($currentByTicker[$ticker] < $archiveVolume) {
                $currentLower++;
            }
            if ($currentByTicker[$ticker] !== $archiveVolume) {
                $differences++;
            }
            $checks++;
        }
    }

    /** @param array<string,mixed> $contract */
    private function snapshotVolume(array $contract): int
    {
        foreach (['session', 'day'] as $period) {
            $aggregate = (array) ($contract[$period] ?? []);
            if (
                array_key_exists('volume', $aggregate)
                && $aggregate['volume'] !== null
                && $aggregate['volume'] !== ''
            ) {
                return $this->nonNegativeInteger($aggregate['volume'], 'volume');
            }
        }

        return 0;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function filterAndSortRows(array $rows, float $fallbackSpot): array
    {
        $config = $this->filterConfiguration();
        $canonicalKeys = [];
        $tickers = [];
        $filtered = [];
        foreach ($rows as $row) {
            $spot = $fallbackSpot;
            $strike = (float) ($row['strike'] ?? 0);
            $band = (float) $config['strike_band_pct'];
            if ($band > 0 && $spot > 0) {
                $minimum = $spot * (1 - $band);
                $maximum = $spot * (1 + $band);
                if ($strike < $minimum || $strike > $maximum) {
                    continue;
                }
            }
            if (
                (int) $row['open_interest'] < (int) $config['min_keep_oi']
                && (int) $row['volume'] < (int) $config['min_keep_vol']
            ) {
                continue;
            }

            $ticker = (string) $row['option_ticker'];
            $key = $row['expiration_date'].'|'.$row['option_type'].'|'.$row['strike'];
            if (isset($canonicalKeys[$key])) {
                throw new RuntimeException("Recovery candidate contracts collide at {$key}.");
            }
            if (isset($tickers[$ticker])) {
                throw new RuntimeException("Recovery ticker {$ticker} appears more than once.");
            }
            $canonicalKeys[$key] = $ticker;
            $tickers[$ticker] = $key;
            $filtered[] = $row;
        }

        usort($filtered, static function (array $left, array $right): int {
            return [$left['expiration_date'], $left['option_type'], $left['strike'], $left['option_ticker']]
                <=> [$right['expiration_date'], $right['option_type'], $right['strike'], $right['option_ticker']];
        });

        return $filtered;
    }

    /** @return array{strike_band_pct:float,min_keep_oi:int,min_keep_vol:int,iv_rule:string,greek_rule:string} */
    private function filterConfiguration(): array
    {
        return [
            'strike_band_pct' => max(0.0, (float) config('services.massive.eod_strike_band_pct', 2.0)),
            'min_keep_oi' => max(0, (int) config('services.massive.eod_min_keep_oi', 1)),
            'min_keep_vol' => max(0, (int) config('services.massive.eod_min_keep_vol', 1)),
            'iv_rule' => 'numeric_gt_1_divide_100_nonpositive_null',
            'greek_rule' => 'target_session_provider_with_exact_archive_fallback_gamma_gates_delta_and_vega',
        ];
    }

    private function exactClosingSpot(string $symbol, string $targetDate): float
    {
        $spot = (float) (DB::table('prices_daily')
            ->where('symbol', $symbol)
            ->whereDate('trade_date', $targetDate)
            ->value('close') ?? 0);
        if ($spot <= 0) {
            throw new RuntimeException("Exact prices_daily close is missing for {$symbol} {$targetDate}.");
        }

        return $spot;
    }

    /** @return array<string,mixed> */
    private function previousSessionStats(string $symbol, string $targetDate): array
    {
        $previousDate = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)
            ->whereDate('o.data_date', '<', $targetDate)
            ->max('o.data_date');
        if ($previousDate === null) {
            return [
                'date' => null,
                'rows' => 0,
                'strikes' => 0,
                'metric_coverage' => [],
            ];
        }

        $row = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)
            ->whereDate('o.data_date', (string) $previousDate)
            ->selectRaw(
                'COUNT(*) AS rows_n, COUNT(DISTINCT o.strike) AS strikes_n, '
                .'SUM(CASE WHEN o.gamma IS NOT NULL THEN 1 ELSE 0 END) AS gamma_n, '
                .'SUM(CASE WHEN o.delta IS NOT NULL THEN 1 ELSE 0 END) AS delta_n, '
                .'SUM(CASE WHEN o.vega IS NOT NULL THEN 1 ELSE 0 END) AS vega_n, '
                .'SUM(CASE WHEN o.iv IS NOT NULL THEN 1 ELSE 0 END) AS iv_n'
            )
            ->first();
        $rows = (int) ($row->rows_n ?? 0);

        return [
            'date' => substr((string) $previousDate, 0, 10),
            'rows' => $rows,
            'strikes' => (int) ($row->strikes_n ?? 0),
            'metric_coverage' => collect(['gamma', 'delta', 'vega', 'iv'])->mapWithKeys(
                static fn (string $metric): array => [
                    $metric => $rows > 0 ? ((int) ($row->{$metric.'_n'} ?? 0) / $rows) : null,
                ],
            )->all(),
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $manifest @return array<string,mixed> */
    private function validateCandidate(
        array $candidate,
        array $manifest,
        ?string $expectedSymbol = null,
        ?array $archiveEvidence = null,
        ?array $referenceEvidence = null,
    ): array {
        $errors = [];
        $symbol = Symbols::canon((string) ($candidate['symbol'] ?? ''));
        $date = (string) ($candidate['date'] ?? '');
        $endDate = (string) ($candidate['end_date'] ?? '');
        if ($symbol === null || $symbol === '') {
            $errors[] = 'invalid_symbol';
        }
        if ($expectedSymbol !== null && $symbol !== $expectedSymbol) {
            $errors[] = 'manifest_symbol_mismatch';
        }
        if ((int) ($candidate['artifact_version'] ?? 0) !== self::ARTIFACT_VERSION) {
            $errors[] = 'unsupported_candidate_artifact_version';
        }
        if (! $this->isStrictDate($date) || ! $this->isStrictDate($endDate)) {
            $errors[] = 'invalid_candidate_date_format';
        }
        if ($date !== (string) ($manifest['date'] ?? $date)) {
            $errors[] = 'manifest_date_mismatch';
        }
        if ($endDate !== (string) ($manifest['end_date'] ?? $endDate)) {
            $errors[] = 'manifest_end_date_mismatch';
        }
        try {
            [$target, $windowEnd] = $this->recoveryWindow($date);
            if ($windowEnd->toDateString() !== $endDate) {
                $errors[] = 'invalid_recovery_window';
            }
        } catch (\Throwable) {
            $errors[] = 'invalid_candidate_date';
            $target = CarbonImmutable::now('America/New_York')->startOfDay();
        }

        $referenceCatalog = null;
        if ($referenceEvidence !== null && $symbol !== null && $symbol !== '') {
            if (
                Symbols::canon((string) ($referenceEvidence['symbol'] ?? '')) !== $symbol
                || (string) ($referenceEvidence['date'] ?? '') !== $date
                || (string) ($referenceEvidence['end_date'] ?? '') !== $endDate
            ) {
                $errors[] = 'reference_evidence_scope_mismatch';
            } else {
                try {
                    $referenceCatalog = $this->validatedReferenceCatalog(
                        $symbol,
                        $date,
                        $endDate,
                        (array) ($referenceEvidence['contracts'] ?? []),
                    );
                } catch (\Throwable) {
                    $errors[] = 'invalid_reference_evidence';
                }
            }
        }

        $candidateExpectedExpiries = array_values(array_unique(array_map(
            static fn (mixed $expiry): string => (string) $expiry,
            (array) ($candidate['expected_expiries'] ?? []),
        )));
        sort($candidateExpectedExpiries);
        foreach ($candidateExpectedExpiries as $expiry) {
            if (! $this->isStrictDate($expiry)) {
                $errors[] = 'invalid_expected_expiry';
            }
        }
        $expectedExpiries = is_array($referenceCatalog)
            ? array_values((array) $referenceCatalog['expiries'])
            : $candidateExpectedExpiries;
        sort($expectedExpiries);
        if (is_array($referenceCatalog) && $candidateExpectedExpiries !== $expectedExpiries) {
            $errors[] = 'candidate_reference_expiry_mismatch';
        }
        if ($expectedExpiries === []) {
            $errors[] = 'expected_expiries_empty';
        }
        foreach ($expectedExpiries as $expiry) {
            if ($expiry < $date || $expiry > $endDate) {
                $errors[] = 'expected_expiry_outside_window';
            }
        }
        $sourcePolicy = (string) ($candidate['source_policy'] ?? '');
        if (
            $sourcePolicy !== ''
            && $sourcePolicy !== 'archive_exact_target_else_current_snapshot_v1'
        ) {
            $errors[] = 'unsupported_source_policy';
        }
        $targetExpiryPresent = in_array($date, $expectedExpiries, true);
        if (! $targetExpiryPresent && $sourcePolicy !== 'archive_exact_target_else_current_snapshot_v1') {
            $errors[] = 'missing_non_target_source_policy';
        }

        $expectedSources = [];
        foreach ($expectedExpiries as $expiry) {
            $expectedSources[$expiry] = $expiry === $date ? 'archive' : 'current_snapshot';
        }

        $expirySources = (array) ($candidate['expiry_sources'] ?? []);
        ksort($expirySources);
        if ($expirySources !== $expectedSources) {
            $errors[] = 'expiry_source_set_mismatch';
        }
        foreach ($expectedExpiries as $expiry) {
            $expectedSource = $expectedSources[$expiry];
            if (($expirySources[$expiry] ?? null) !== $expectedSource) {
                $errors[] = "wrong_source_{$expiry}";
            }
            $coverage = (array) (($candidate['raw_ticker_coverage'][$expiry] ?? []));
            if (
                (int) ($coverage['expected'] ?? 0) <= 0
                || (int) ($coverage['expected'] ?? 0) !== (int) ($coverage['recovered'] ?? -1)
            ) {
                $errors[] = "raw_ticker_coverage_{$expiry}";
            }
        }

        $fallbackMetrics = ['gamma', 'delta', 'vega', 'iv'];
        $declaredFallbacks = (array) ($candidate['archive_future_metric_fallbacks'] ?? []);
        $observedFallbacks = array_fill_keys($fallbackMetrics, 0);
        if (
            array_diff(array_keys($declaredFallbacks), $fallbackMetrics) !== []
            || array_diff($fallbackMetrics, array_keys($declaredFallbacks)) !== []
        ) {
            $errors[] = 'invalid_archive_metric_fallback_counters';
        }
        $archiveFallbackEvidence = [];
        if ($archiveEvidence !== null) {
            if (Symbols::canon((string) ($archiveEvidence['symbol'] ?? '')) !== $symbol) {
                $errors[] = 'archive_fallback_evidence_symbol_mismatch';
            }
            foreach ((array) ($archiveEvidence['groups'] ?? []) as $archiveExpiry => $archiveGroup) {
                $archiveExpiry = (string) $archiveExpiry;
                $archiveGroup = (array) $archiveGroup;
                foreach ((array) ($archiveGroup['contracts'] ?? []) as $archiveRow) {
                    $archiveRow = (array) $archiveRow;
                    $archiveTicker = strtoupper(trim((string) ($archiveRow['contract_symbol'] ?? '')));
                    if ($archiveTicker === '') {
                        continue;
                    }
                    if (isset($archiveFallbackEvidence[$archiveExpiry]['rows'][$archiveTicker])) {
                        $errors[] = 'duplicate_archive_fallback_evidence_ticker';
                    }
                    $archiveFallbackEvidence[$archiveExpiry]['group'] = $archiveGroup;
                    $archiveFallbackEvidence[$archiveExpiry]['rows'][$archiveTicker] = $archiveRow;
                }
            }
        }

        if (is_array($referenceCatalog) && $archiveEvidence !== null) {
            $archiveGroups = (array) ($archiveEvidence['groups'] ?? []);
            if ($targetExpiryPresent !== isset($archiveGroups[$date])) {
                $errors[] = 'archive_reference_target_presence_mismatch';
            }

            if (! $targetExpiryPresent) {
                $declaredWitnesses = array_values(array_unique(array_map(
                    static fn (mixed $expiry): string => (string) $expiry,
                    (array) ($candidate['archive_witness_expiries'] ?? []),
                )));
                sort($declaredWitnesses);
                $derivedWitnesses = array_values(array_intersect(
                    array_keys($archiveGroups),
                    $expectedExpiries,
                ));
                sort($derivedWitnesses);
                if ($declaredWitnesses === [] || $declaredWitnesses !== $derivedWitnesses) {
                    $errors[] = 'archive_witness_set_mismatch';
                }

                $closingSpot = $this->positiveNumberOrNull($candidate['underlying_price'] ?? null);
                foreach ($derivedWitnesses as $witnessExpiry) {
                    try {
                        $witnessGroup = (array) $archiveGroups[$witnessExpiry];
                        $witnessRows = (array) ($witnessGroup['contracts'] ?? []);
                        $this->assertArchiveGroupIsPostClose($symbol, $target, $witnessGroup);
                        $this->assertArchiveDefinitions(
                            $symbol,
                            $witnessExpiry,
                            $witnessRows,
                            $referenceCatalog['by_ticker'],
                        );
                        $this->assertExactTickerCoverage(
                            $symbol,
                            $witnessExpiry,
                            $this->referenceTickersForExpiry(
                                $referenceCatalog['by_expiry_side'][$witnessExpiry],
                            ),
                            $this->archiveTickers($witnessRows),
                            'archive_witness',
                        );
                        if ($closingSpot === null) {
                            throw new RuntimeException('Candidate closing spot is missing.');
                        }
                        $this->assertSpotAgreement(
                            $symbol,
                            $closingSpot,
                            $this->archiveGroupSpot($symbol, $witnessRows, $closingSpot),
                        );
                    } catch (\Throwable) {
                        $errors[] = "invalid_archive_witness_{$witnessExpiry}";
                    }
                }
            }
        }

        $rows = (array) ($candidate['rows'] ?? []);
        if ($rows === []) {
            $errors[] = 'candidate_rows_empty';
        }
        $canonicalKeys = [];
        $tickerKeys = [];
        $expiryStats = [];
        $metricCounts = ['gamma' => 0, 'delta' => 0, 'vega' => 0, 'iv' => 0];
        $allStrikes = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = "row_{$index}_not_object";

                continue;
            }
            $expiry = (string) ($row['expiration_date'] ?? '');
            $side = strtolower((string) ($row['option_type'] ?? ''));
            $strike = (string) ($row['strike'] ?? '');
            $ticker = strtoupper(trim((string) ($row['option_ticker'] ?? '')));
            $source = (string) ($row['source'] ?? '');
            if (! in_array($expiry, $expectedExpiries, true)) {
                $errors[] = "row_{$index}_unexpected_expiry";
            }
            if (! in_array($side, ['call', 'put'], true) || $ticker === '' || (float) $strike <= 0) {
                $errors[] = "row_{$index}_invalid_identity";

                continue;
            }
            $expectedSource = $expectedSources[$expiry] ?? null;
            if ($source !== $expectedSource) {
                $errors[] = "row_{$index}_wrong_source";
            }
            $key = $expiry.'|'.$side.'|'.$this->strikeKey((float) $strike);
            if (isset($canonicalKeys[$key])) {
                $errors[] = "duplicate_canonical_key_{$key}";
            }
            if (isset($tickerKeys[$ticker]) && $tickerKeys[$ticker] !== $key) {
                $errors[] = "ticker_identity_conflict_{$ticker}";
            }
            $canonicalKeys[$key] = $ticker;
            $tickerKeys[$ticker] = $key;
            $expiryStats[$expiry][$side][$this->strikeKey((float) $strike)] = true;
            $allStrikes[$this->strikeKey((float) $strike)] = true;

            foreach ($metricCounts as $metric => $_) {
                if (($row[$metric] ?? null) !== null) {
                    $metricCounts[$metric]++;
                }
            }
            try {
                $timestamp = CarbonImmutable::parse((string) ($row['data_timestamp'] ?? ''), 'UTC')->utc();
                $minimum = $source === 'archive'
                    ? $target->setTime(16, 15)->utc()
                    : $target->setTime(16, 0)->utc();
                if ($timestamp->lt($minimum) || $timestamp->gt($target->endOfDay()->utc())) {
                    $errors[] = "row_{$index}_outside_target_source_window";
                }
            } catch (\Throwable) {
                $errors[] = "row_{$index}_invalid_timestamp";
            }
            if ((int) ($row['open_interest'] ?? -1) < 0 || (int) ($row['volume'] ?? -1) < 0) {
                $errors[] = "row_{$index}_negative_activity";
            }
            $delta = $row['delta'] ?? null;
            $gamma = $row['gamma'] ?? null;
            $vega = $row['vega'] ?? null;
            $iv = $row['iv'] ?? null;
            if ($delta !== null && ((float) $delta < -1.00000001 || (float) $delta > 1.00000001)) {
                $errors[] = "row_{$index}_invalid_delta";
            }
            if ($iv !== null && (float) $iv <= 0) {
                $errors[] = "row_{$index}_invalid_metrics";
            }
            $metricFallbacks = array_values((array) ($row['metric_fallbacks'] ?? []));
            if ($metricFallbacks !== []) {
                if (
                    $source !== 'current_snapshot'
                    || trim((string) ($row['metric_fallback_request_id'] ?? '')) === ''
                    || trim((string) ($row['metric_fallback_captured_at'] ?? '')) === ''
                    || count($metricFallbacks) !== count(array_unique($metricFallbacks))
                ) {
                    $errors[] = "row_{$index}_invalid_metric_fallback_provenance";
                }
                foreach ($metricFallbacks as $fallbackMetric) {
                    if (
                        ! in_array($fallbackMetric, $fallbackMetrics, true)
                        || ($row[$fallbackMetric] ?? null) === null
                    ) {
                        $errors[] = "row_{$index}_invalid_metric_fallback";

                        continue;
                    }
                    $observedFallbacks[$fallbackMetric]++;
                }
                $archiveGroup = (array) (
                    $archiveFallbackEvidence[$expiry]['group'] ?? []
                );
                $archiveRow = (array) (
                    $archiveFallbackEvidence[$expiry]['rows'][$ticker] ?? []
                );
                $archiveRequestId = trim((string) (
                    $archiveGroup['meta']['selected_request_id'] ?? ''
                ));
                $archiveCapturedAt = trim((string) (
                    $archiveGroup['meta']['selected_captured_at'] ?? ''
                ));
                if ($archiveGroup === [] || $archiveRow === []) {
                    $errors[] = "row_{$index}_missing_metric_fallback_evidence";
                } else {
                    try {
                        $this->assertArchiveGroupIsPostClose($symbol, $target, $archiveGroup);
                    } catch (\Throwable) {
                        $errors[] = "row_{$index}_invalid_metric_fallback_window";
                    }
                    if (
                        $archiveRequestId === ''
                        || (string) ($row['metric_fallback_request_id'] ?? '') !== $archiveRequestId
                        || (string) ($row['metric_fallback_captured_at'] ?? '')
                            !== $this->normalizeUtcTimestamp($archiveCapturedAt)
                        || Symbols::canon((string) ($archiveRow['symbol'] ?? '')) !== $symbol
                        || substr((string) ($archiveRow['expiration_date'] ?? ''), 0, 10) !== $expiry
                        || strtoupper(trim((string) ($archiveRow['contract_symbol'] ?? ''))) !== $ticker
                        || strtolower((string) ($archiveRow['contract_type'] ?? '')) !== $side
                        || abs((float) ($archiveRow['strike_price'] ?? 0) - (float) $strike) > 0.000001
                        || trim((string) ($archiveRow['request_id'] ?? '')) !== $archiveRequestId
                    ) {
                        $errors[] = "row_{$index}_metric_fallback_identity_mismatch";
                    }
                    foreach ($metricFallbacks as $fallbackMetric) {
                        if (! in_array($fallbackMetric, $fallbackMetrics, true)) {
                            continue;
                        }
                        try {
                            $archiveMetric = $fallbackMetric === 'iv'
                                ? $this->normalizeIv($archiveRow['implied_volatility'] ?? null)
                                : $this->nullableFiniteNumber(
                                    $archiveRow[$fallbackMetric] ?? null,
                                    $fallbackMetric,
                                );
                            if (
                                $archiveMetric === null
                                || (string) ($row[$fallbackMetric] ?? '')
                                    !== (string) $this->fixedDecimal($archiveMetric, 8)
                            ) {
                                $errors[] = "row_{$index}_metric_fallback_value_mismatch";
                            }
                        } catch (\Throwable) {
                            $errors[] = "row_{$index}_invalid_metric_fallback_value";
                        }
                    }
                }
            } elseif (($row['metric_fallback_request_id'] ?? null) !== null) {
                $errors[] = "row_{$index}_unexpected_metric_fallback_request";
            } elseif (($row['metric_fallback_captured_at'] ?? null) !== null) {
                $errors[] = "row_{$index}_unexpected_metric_fallback_timestamp";
            }
        }

        foreach ($fallbackMetrics as $fallbackMetric) {
            $declared = $declaredFallbacks[$fallbackMetric] ?? null;
            if (
                ! is_int($declared)
                || $declared < 0
                || $declared !== $observedFallbacks[$fallbackMetric]
            ) {
                $errors[] = "archive_metric_fallback_count_mismatch_{$fallbackMetric}";
            }
        }

        $minimumSideRatio = max(0.01, min(1.0, (float) config(
            'services.massive.eod_min_side_strike_ratio',
            0.35,
        )));
        foreach ($expectedExpiries as $expiry) {
            $callCount = count($expiryStats[$expiry]['call'] ?? []);
            $putCount = count($expiryStats[$expiry]['put'] ?? []);
            if (! EodHealth::sideRatioMeetsThreshold($callCount, $putCount, $minimumSideRatio)) {
                $errors[] = "expiry_side_gap_{$expiry}";
            }
        }

        $callStrikes = [];
        $putStrikes = [];
        foreach ($expiryStats as $sides) {
            $callStrikes += $sides['call'] ?? [];
            $putStrikes += $sides['put'] ?? [];
        }
        $thresholds = EodHealth::resolveThresholds(
            (string) config('services.massive.recovery_health_profile', 'broad'),
            config('services.massive.recovery_min_expirations'),
            config('services.massive.recovery_min_strikes'),
            config('services.massive.recovery_min_strike_ratio'),
            config('services.massive.recovery_min_side_ratio'),
        );
        $previous = (array) ($candidate['previous_session'] ?? []);
        $healthReasons = EodHealth::incompleteReasons([
            'option_types_n' => ($callStrikes !== [] && $putStrikes !== []) ? 2 : 0,
            'expirations_n' => count($expiryStats),
            'strikes_n' => count($allStrikes),
            'call_strikes_n' => count($callStrikes),
            'put_strikes_n' => count($putStrikes),
        ], (int) ($previous['strikes'] ?? 0), $thresholds);
        foreach ($healthReasons as $reason) {
            $errors[] = 'health_'.$reason;
        }

        $rowCount = count($rows);
        $metricCoverage = [];
        foreach ($metricCounts as $metric => $count) {
            $coverage = $rowCount > 0 ? $count / $rowCount : 0.0;
            $metricCoverage[$metric] = $coverage;
            $previousCoverage = $previous['metric_coverage'][$metric] ?? null;
            if (
                is_numeric($previousCoverage)
                && (float) $previousCoverage > 0
                && $coverage + self::METRIC_COVERAGE_TOLERANCE < (float) $previousCoverage
            ) {
                $errors[] = "metric_coverage_regression_{$metric}";
            }
        }

        $errors = array_values(array_unique($errors));
        $candidateSha = hash('sha256', $this->canonicalJson($candidate));

        return [
            'ok' => $errors === [],
            'symbol' => $symbol,
            'candidate_rows' => $rowCount,
            'candidate_sha256' => $candidateSha,
            'expiry_sources' => $expirySources,
            'expected_expiries' => $expectedExpiries,
            'metric_coverage' => $metricCoverage,
            'health_thresholds' => $thresholds,
            'errors' => $errors,
        ];
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function recoveryWindow(string $date): array
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            throw new RuntimeException('Recovery date must use YYYY-MM-DD.');
        }
        try {
            $target = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/New_York');
        } catch (\Throwable $exception) {
            throw new RuntimeException('Recovery date must use YYYY-MM-DD.', previous: $exception);
        }
        if ($target === null || $target->format('Y-m-d') !== $date) {
            throw new RuntimeException('Recovery date must be a real calendar date.');
        }

        return [$target->startOfDay(), $target->addDays(90)->startOfDay()];
    }

    private function isStrictDate(string $date): bool
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return false;
        }
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/New_York');
        } catch (\Throwable) {
            return false;
        }

        return $parsed !== null && $parsed->format('Y-m-d') === $date;
    }

    private function validSha256(string $sha): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/i', $sha) === 1;
    }

    private function assertSafeCaptureWindow(CarbonImmutable $target): void
    {
        $nowNy = CarbonImmutable::now('America/New_York');
        $targetNy = $target->setTimezone('America/New_York')->startOfDay();
        $earliest = $targetNy->setTime(16, 15);
        $nextSession = $targetNy->addDay();
        while ($nextSession->isWeekend()) {
            $nextSession = $nextSession->addDay();
        }
        $latest = $nextSession->setTime(9, 30);
        if ($nowNy->lt($earliest) || ! $nowNy->lt($latest) || Market::isRthOpen($nowNy->toMutable())) {
            throw new RuntimeException(sprintf(
                'Live recovery capture is allowed only after %s 16:15 ET and before the next session opens.',
                $targetNy->toDateString(),
            ));
        }
    }

    /** @param array<int,string> $symbols @return array<int,string> */
    private function canonicalSymbols(array $symbols): array
    {
        $canonical = [];
        foreach ($symbols as $symbol) {
            $value = Symbols::canon((string) $symbol);
            if ($value === null || $value === '') {
                throw new RuntimeException('Recovery contains an invalid symbol.');
            }
            $canonical[$value] = true;
        }
        if ($canonical === []) {
            throw new RuntimeException('At least one recovery symbol is required.');
        }
        $result = array_keys($canonical);
        sort($result);

        return $result;
    }

    private function prepareNewRunDirectory(string $directory): string
    {
        $directory = rtrim(trim($directory), '\\/');
        if ($directory === '' || file_exists($directory)) {
            throw new RuntimeException('Recovery capture requires a new, non-existing run directory.');
        }
        $this->makePrivateDirectory($directory);
        $real = realpath($directory);
        if ($real === false) {
            throw new RuntimeException('Unable to resolve the recovery run directory.');
        }

        return rtrim($real, '\\/');
    }

    private function existingRunDirectory(string $directory): string
    {
        $real = realpath($directory);
        if ($real === false || ! is_dir($real) || ! is_readable($real)) {
            throw new RuntimeException('Recovery run directory is not readable.');
        }

        return rtrim($real, '\\/');
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function candidatePersistedRows(string $symbol, string $date, array $rows): array
    {
        $persisted = [];
        foreach ($rows as $row) {
            $persisted[] = $this->normalizePersistedRow([
                'symbol' => $symbol,
                'expiration_date' => $row['expiration_date'] ?? null,
                'data_date' => $date,
                'data_timestamp' => $row['data_timestamp'] ?? null,
                'option_type' => $row['option_type'] ?? null,
                'strike' => $row['strike'] ?? null,
                'open_interest' => $row['open_interest'] ?? 0,
                'volume' => $row['volume'] ?? 0,
                'gamma' => $row['gamma'] ?? null,
                'delta' => $row['delta'] ?? null,
                'vega' => $row['vega'] ?? null,
                'iv' => $row['iv'] ?? null,
                'underlying_price' => $row['underlying_price'] ?? null,
            ]);
        }
        $this->sortPersistedRows($persisted);

        return $persisted;
    }

    /** @return array<int,array<string,mixed>> */
    private function persistedSliceRows(string $symbol, string $date, bool $lock): array
    {
        $query = DB::table('option_chain_data as o')
            ->join('option_expirations as e', 'e.id', '=', 'o.expiration_id')
            ->where('e.symbol', $symbol)
            ->whereDate('o.data_date', $date)
            ->select([
                'e.symbol',
                'e.expiration_date',
                'o.data_date',
                'o.data_timestamp',
                'o.option_type',
                'o.strike',
                'o.open_interest',
                'o.volume',
                'o.gamma',
                'o.delta',
                'o.vega',
                'o.iv',
                'o.underlying_price',
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get()->map(
            fn (object $row): array => $this->normalizePersistedRow((array) $row),
        )->all();
        $this->sortPersistedRows($rows);

        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizePersistedRow(array $row): array
    {
        return [
            'symbol' => (string) ($row['symbol'] ?? ''),
            'expiration_date' => substr((string) ($row['expiration_date'] ?? ''), 0, 10),
            'data_date' => substr((string) ($row['data_date'] ?? ''), 0, 10),
            'data_timestamp' => $this->normalizeUtcTimestamp($row['data_timestamp'] ?? null),
            'option_type' => strtolower((string) ($row['option_type'] ?? '')),
            'strike' => $this->strikeKey((float) ($row['strike'] ?? 0)),
            'open_interest' => $this->nonNegativeInteger($row['open_interest'] ?? 0, 'open_interest'),
            'volume' => $this->nonNegativeInteger($row['volume'] ?? 0, 'volume'),
            'gamma' => $this->fixedDecimal($this->nullableFiniteNumber($row['gamma'] ?? null, 'gamma'), 8),
            'delta' => $this->fixedDecimal($this->nullableFiniteNumber($row['delta'] ?? null, 'delta'), 8),
            'vega' => $this->fixedDecimal(
                $this->float32($this->nullableFiniteNumber($row['vega'] ?? null, 'vega')),
                8,
            ),
            'iv' => $this->fixedDecimal($this->nullableFiniteNumber($row['iv'] ?? null, 'iv'), 8),
            'underlying_price' => $this->fixedDecimal(
                $this->positiveNumberOrNull($row['underlying_price'] ?? null),
                4,
            ),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sortPersistedRows(array &$rows): void
    {
        usort($rows, static function (array $left, array $right): int {
            $head = [$left['symbol'], $left['expiration_date'], $left['data_date'], $left['option_type']]
                <=> [$right['symbol'], $right['expiration_date'], $right['data_date'], $right['option_type']];

            return $head !== 0 ? $head : ((float) $left['strike'] <=> (float) $right['strike']);
        });
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function naturalKeySha(array $rows): string
    {
        $keys = array_map(static fn (array $row): array => [
            'symbol' => $row['symbol'],
            'expiration_date' => $row['expiration_date'],
            'data_date' => $row['data_date'],
            'option_type' => $row['option_type'],
            'strike' => $row['strike'],
        ], $rows);

        return hash('sha256', $this->canonicalJson($keys));
    }

    private function makePrivateDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create recovery directory {$directory}.");
        }
        @chmod($directory, 0700);
    }

    private function artifactSymbol(string $symbol): string
    {
        return preg_replace('/[^A-Z0-9._-]/', '_', strtoupper($symbol)) ?: 'UNKNOWN';
    }

    private function resolveArtifactPath(string $runDirectory, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $relative)) {
            throw new RuntimeException('Recovery manifest contains an unsafe artifact path.');
        }
        $path = $runDirectory.'/'.str_replace('\\', '/', $relative);
        $real = realpath($path);
        $root = realpath($runDirectory);
        if ($real === false || $root === false) {
            throw new RuntimeException("Recovery artifact is missing: {$relative}");
        }
        $prefix = rtrim($root, '\\/').DIRECTORY_SEPARATOR;
        if (! str_starts_with($real, $prefix)) {
            throw new RuntimeException('Recovery artifact resolves outside its run directory.');
        }

        return $real;
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->makePrivateDirectory(dirname($path));
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION,
        ).PHP_EOL;
        $temporary = $path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Unable to create recovery JSON artifact {$temporary}.");
        }

        try {
            $this->writeAllAndSync($handle, $json, $temporary);
            @chmod($temporary, 0600);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporary);

            throw $exception;
        }
        fclose($handle);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to atomically replace recovery JSON artifact {$path}.");
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read recovery JSON artifact {$path}.");
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Recovery JSON artifact is not an object: {$path}");
        }

        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function writeGzipJson(string $path, array $data): void
    {
        $this->makePrivateDirectory(dirname($path));
        $compressed = gzencode($this->canonicalJson($data), 6, FORCE_GZIP);
        if ($compressed === false) {
            throw new RuntimeException("Unable to encode recovery gzip artifact {$path}.");
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Immutable recovery artifact already exists or cannot be created: {$path}");
        }

        try {
            $this->writeAllAndSync($handle, $compressed, $path);
            @chmod($path, 0400);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);

            throw $exception;
        }
        fclose($handle);
    }

    /** @param resource $handle */
    private function writeAllAndSync($handle, string $contents, string $path): void
    {
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException("Short write while creating recovery artifact {$path}.");
            }
            $offset += $written;
        }
        if (! fflush($handle)) {
            throw new RuntimeException("Unable to flush recovery artifact {$path}.");
        }
        if (function_exists('fsync') && ! fsync($handle)) {
            throw new RuntimeException("Unable to sync recovery artifact {$path}.");
        }
    }

    /** @return array<string,mixed> */
    private function readGzipJson(string $path): array
    {
        $compressed = file_get_contents($path);
        if ($compressed === false) {
            throw new RuntimeException("Unable to read recovery gzip artifact {$path}.");
        }
        $json = gzdecode($compressed);
        if ($json === false) {
            throw new RuntimeException("Recovery gzip artifact is corrupt: {$path}");
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Recovery gzip artifact is not an object: {$path}");
        }

        return $decoded;
    }

    private function canonicalJson(mixed $value): string
    {
        $normalized = $this->canonicalValue($value);

        return json_encode(
            $normalized,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    private function strikeKey(float $strike): string
    {
        if (! is_finite($strike) || $strike <= 0 || $strike >= 1000000) {
            throw new RuntimeException('Recovery strike is outside the destination range.');
        }
        if (abs($strike - round($strike, 2)) > 0.000001) {
            throw new RuntimeException('Recovery strike would lose precision in DECIMAL(8,2).');
        }

        return number_format($strike, 2, '.', '');
    }

    private function positiveNumberOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number > 0 ? $number : null;
    }

    private function nullableFiniteNumber(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new RuntimeException("Recovery {$field} is not numeric.");
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            throw new RuntimeException("Recovery {$field} is not finite.");
        }

        return $number;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (! is_numeric($value)) {
            throw new RuntimeException("Recovery {$field} is not numeric.");
        }
        $number = (float) $value;
        if (! is_finite($number) || $number < 0 || floor($number) !== $number || $number > PHP_INT_MAX) {
            throw new RuntimeException("Recovery {$field} is outside the integer range.");
        }

        return (int) $number;
    }

    private function fixedDecimal(?float $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, $scale, '.', '');
    }

    private function float32(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $unpacked = unpack('Gvalue', pack('G', $value));

        return isset($unpacked['value']) ? (float) $unpacked['value'] : $value;
    }

    private function normalizeUtcTimestamp(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            throw new RuntimeException('Recovery row data timestamp is empty.');
        }
        try {
            return CarbonImmutable::parse($raw, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $exception) {
            throw new RuntimeException('Recovery row data timestamp is invalid.', previous: $exception);
        }
    }
}
