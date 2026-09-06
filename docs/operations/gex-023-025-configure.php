<?php

declare(strict_types=1);

// Run from the site's current release. This helper never deploys, drains jobs,
// rebuilds manifests, clears caches, or prints environment values. The six
// readiness symbols are a rollout smoke check, not proof of global coverage.
final class Gex023025ConfigurationFailure extends RuntimeException {}

final class Gex023025Configuration
{
    public const USAGE = "Usage: php docs/operations/gex-023-025-configure.php universe-on|universe-off|tracking-on|reads-on|reads-off [--writers-drained]\n"
        ."tracking-on requires --writers-drained: an operator attestation that old writers have finished on both nodes, not an automatic check.\n"
        ."No arguments: show usage without changing anything. Rebuild config and restart the site's workers after a change.\n";

    private const MODES = [
        'universe-on' => ['GEX_EXPIRATION_UNIVERSE_ENABLED' => true, 'GEX_EXPIRATION_SHADOW_ENABLED' => false],
        'universe-off' => ['GEX_EXPIRATION_UNIVERSE_ENABLED' => false],
        'tracking-on' => ['EOD_SNAPSHOT_HEALTH_ENABLED' => true, 'EOD_SNAPSHOT_HEALTH_READ_ENABLED' => false],
        'reads-on' => ['EOD_SNAPSHOT_HEALTH_READ_ENABLED' => true],
        'reads-off' => ['EOD_SNAPSHOT_HEALTH_READ_ENABLED' => false],
    ];

    public static function changes(array $arguments): array
    {
        $mode = $arguments[0] ?? '';
        if (! isset(self::MODES[$mode])
            || ($mode === 'tracking-on' ? array_slice($arguments, 1) !== ['--writers-drained'] : count($arguments) !== 1)) {
            throw new Gex023025ConfigurationFailure(self::USAGE);
        }

        return self::MODES[$mode];
    }

    /** Inputs must be resolved with realpath before this allowlist is consulted. */
    public static function allowedPaths(string $release, string $target): bool
    {
        foreach (['/home/forge/gexoptions.com', '/home/forge/stocks-options-ss7u2nu2.on-forge.com'] as $root) {
            if (str_starts_with($release, $root.'/') && str_starts_with($target, $root.'/')
                && basename($target) === '.env'
                && ! preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $release.'/'.$target)) {
                return true;
            }
        }

        return false;
    }

    /** Keep raw logical entries, including their original line endings. */
    private static function entries(string $original): array
    {
        $entries = [];
        $buffer = '';
        preg_match_all('/[^\r\n]*(?:\r\n|\n|\r|$)/', $original, $lines);
        try {
            foreach ($lines[0] as $line) {
                if ($line === '') {
                    continue;
                }
                if ($buffer === '' && (trim($line) === '' || str_starts_with(ltrim($line), '#'))) {
                    $entries[] = ['raw' => $line, 'key' => null, 'value' => null];

                    continue;
                }
                $buffer .= $line;
                $parsed = (new Dotenv\Parser\Parser)->parse($buffer);
                if ($parsed === []) {
                    continue; // A quoted multiline value is not an independent flag line.
                }
                if (count($parsed) !== 1) {
                    throw new RuntimeException;
                }
                $value = $parsed[0]->getValue()->getOrElse(null);
                $entries[] = ['raw' => $buffer, 'key' => $parsed[0]->getName(), 'value' => $value?->getChars()];
                $buffer = '';
            }
            if ($buffer !== '') {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            // Dotenv parser exception messages can contain secret values.
            throw new Gex023025ConfigurationFailure('The environment file could not be parsed safely; no flags were changed.');
        }

        return $entries;
    }

    public static function configuredTrue(string $original, string $key): bool
    {
        $found = false;
        foreach (self::entries($original) as $entry) {
            if ($entry['key'] === $key) {
                // Fail closed on conflicting duplicates or interpolated flags.
                if (! in_array(strtolower((string) $entry['value']), ['true', '(true)'], true)) {
                    return false;
                }
                $found = true;
            }
        }

        return $found;
    }

    public static function updated(string $original, array $changes): string
    {
        if (! in_array($changes, self::MODES, true)) {
            throw new Gex023025ConfigurationFailure('Only the fixed rollout flag sets may be changed.');
        }
        $updated = '';
        $seen = [];
        $newline = str_contains($original, "\r\n") ? "\r\n" : (str_contains($original, "\r") ? "\r" : "\n");
        foreach (self::entries($original) as $entry) {
            $key = $entry['key'];
            if (! array_key_exists($key ?? '', $changes)) {
                $updated .= $entry['raw'];

                continue;
            }
            preg_match('/(\r\n|\r|\n)$/', $entry['raw'], $ending);
            $eol = $ending[1] ?? '';
            // Duplicates become empty lines, preserving unrelated bytes/EOLs.
            $updated .= isset($seen[$key]) ? $eol : $key.'='.($changes[$key] ? 'true' : 'false').$eol;
            $seen[$key] = true;
        }
        foreach ($changes as $key => $value) {
            if (! isset($seen[$key])) {
                if ($updated !== '' && ! str_ends_with($updated, "\n") && ! str_ends_with($updated, "\r")) {
                    $updated .= $newline;
                }
                $updated .= $key.'='.($value ? 'true' : 'false').$newline;
            }
        }

        return $updated;
    }

    /** A valid empty 0D set is allowed; the symbol must have data somewhere. */
    public static function usable(?array $manifest): bool
    {
        if ($manifest === null || ($manifest['dirty'] ?? true) || ($manifest['expiration_count'] ?? 0) < 1) {
            return false;
        }
        foreach ($manifest['expirations'] ?? [] as $expiration) {
            if (is_string($expiration['selected_date'] ?? null) && ($expiration['selected_row_count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function preflight(string $mode, string $original): array
    {
        if (in_array($mode, ['universe-off', 'reads-off'], true)) {
            return []; // Rollback must not require a working database.
        }
        foreach (['eod_snapshot_states', 'eod_snapshot_mutations', 'eod_snapshot_manifests'] as $table) {
            if (! Illuminate\Support\Facades\Schema::hasTable($table)) {
                throw new Gex023025ConfigurationFailure('Run the additive GEX-025 migration before enabling a phase.');
            }
        }
        if ($mode !== 'reads-on') {
            return [];
        }
        if (! self::configuredTrue($original, 'EOD_SNAPSHOT_HEALTH_ENABLED')
            || ! self::configuredTrue($original, 'GEX_EXPIRATION_UNIVERSE_ENABLED')
            || ! config('eod_snapshot_health.enabled') || ! config('gex_performance.expiration_universe_enabled')) {
            throw new Gex023025ConfigurationFailure('Enable tracking and the expiration universe, then rebuild this release\'s config before enabling reads.');
        }
        $health = app(App\Support\EodSnapshotHealth::class);
        $policy = $health->policy(); // One anchor/ratio for all six checks; this node's policy, never the other node's.
        $symbols = ['SPY', 'QQQ', 'IWM', 'TSLA', 'AAPL', 'V'];
        foreach ($symbols as $symbol) {
            if (! self::usable($health->read($symbol, $policy, requireCurrent: true))) {
                throw new Gex023025ConfigurationFailure('Reads remain unchanged: '.$symbol.' needs a clean, usable manifest for this release\'s current policy. Inspect gex:snapshot-health first.');
            }
        }
        if ($health->policy() !== $policy) {
            throw new Gex023025ConfigurationFailure('The session policy changed during inspection; retry with the current policy.');
        }

        return ['checked_symbols' => $symbols, 'policy' => $policy];
    }

    private static function writeProtected(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new Gex023025ConfigurationFailure('Could not create a protected rollout file; the environment was not replaced.');
        }
        try {
            if (! @chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents)
                || ! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new Gex023025ConfigurationFailure('Could not finish a protected rollout file; the environment was not replaced.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function run(array $arguments): int
    {
        if (PHP_SAPI !== 'cli') {
            return 1;
        }
        if ($arguments === []) {
            echo self::USAGE;

            return 0;
        }
        $changes = self::changes($arguments);
        $mode = $arguments[0];
        $cwd = getcwd();
        $release = realpath($cwd);
        $target = realpath($cwd.'/.env');
        if (! $release || ! $target || ! self::allowedPaths($release, $target)
            || realpath(__DIR__.'/../..') !== $release || ! is_file($target) || ! is_writable($target)) {
            throw new Gex023025ConfigurationFailure('Run the deployed helper from the allowed site\'s current release with its writable shared .env.');
        }
        require_once $release.'/vendor/autoload.php';
        $original = file_get_contents($target);
        if ($original === false) {
            throw new Gex023025ConfigurationFailure('Could not read the environment; no flags were changed.');
        }
        // Validate privately before Laravel can report dotenv parser details.
        $updated = self::updated($original, $changes);
        if (! in_array($mode, ['universe-off', 'reads-off'], true)) {
            $app = require $release.'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        }
        $checks = self::preflight($mode, $original);
        if ($updated === $original) {
            echo json_encode(['mode' => $mode, 'changed' => false, 'rebuild_config_and_restart_workers' => true] + $checks).PHP_EOL;

            return 0;
        }
        $oldMask = umask(0077);
        $temporary = null;
        try {
            $suffix = gmdate('YmdHis').'-'.bin2hex(random_bytes(5));
            self::writeProtected($target.'.gex023-025-backup-'.$suffix, $original);
            $temporary = $target.'.gex023-025-new-'.$suffix;
            self::writeProtected($temporary, $updated);
            // Refuse a concurrent editor or a release/env symlink change.
            if (realpath($cwd) !== $release || realpath($cwd.'/.env') !== $target
                || file_get_contents($target) !== $original || ! @rename($temporary, $target)) {
                throw new Gex023025ConfigurationFailure('The environment changed or could not be replaced; inspect it before retrying. A protected backup was retained.');
            }
            $temporary = null;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary); // Only the exact temporary file created by this attempt.
            }
            umask($oldMask);
        }
        echo json_encode([
            'mode' => $mode, 'changed' => true, 'changed_keys' => array_keys($changes),
            'protected_backup_created' => true, 'writers_drained_attested' => $mode === 'tracking-on',
            'writers_drained_automatically_verified' => false,
            'rebuild_config_and_restart_workers' => true,
        ] + $checks, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        return 0;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    try {
        exit(Gex023025Configuration::run(array_slice($argv, 1)));
    } catch (Gex023025ConfigurationFailure $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);
    } catch (Throwable) {
        fwrite(STDERR, "Rollout preflight or file operation failed. No environment contents are shown. Inspect this release before retrying.\n");
    }
    exit(1);
}
