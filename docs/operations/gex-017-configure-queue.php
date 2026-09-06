<?php

// Operator helper, CLI only. Prepare reads the password from an encrypted
// stdin pipe. No credential is accepted in arguments or printed to output.
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
$roots = ['/home/forge/gexoptions.com', '/home/forge/stocks-options-ss7u2nu2.on-forge.com'];
$target = realpath(getcwd().'/.env');
if (! in_array($mode, ['prepare', 'activate', 'rollback'], true) || $target === false) {
    fwrite(STDERR, "Use prepare, activate, or rollback from the current release.\n"); exit(1);
}
$allowed = false;
foreach ($roots as $root) {
    if (str_starts_with($target, $root.'/') && str_starts_with((string) realpath(getcwd()), $root.'/')) { $allowed = true; }
}
if (! $allowed || ! is_file($target) || ! is_writable($target)) {
    fwrite(STDERR, "Refusing an unexpected or unwritable environment file.\n"); exit(1);
}
$original = file_get_contents($target);
if ($original === false) { exit(1); }
$changes = ['REDIS_QUEUE_CONNECTION' => $mode === 'activate' ? 'queue' : 'default'];
if ($mode === 'prepare') {
    $password = trim(stream_get_contents(STDIN));
    if (! preg_match('/^[a-f0-9]{64}$/D', $password)) {
        fwrite(STDERR, "Expected the generated queue credential on stdin.\n"); exit(1);
    }
    $changes += ['REDIS_QUEUE_HOST' => '10.10.0.3', 'REDIS_QUEUE_PORT' => '6380', 'REDIS_QUEUE_DB' => '0', 'REDIS_QUEUE_PASSWORD' => $password];
    // An existing URL or named ACL user could silently override these values.
    foreach (['REDIS_QUEUE_URL', 'REDIS_QUEUE_USERNAME'] as $key) {
        if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*[^\r\n]+/m', $original)) {
            fwrite(STDERR, "An existing queue URL or ACL user needs review.\n"); exit(1);
        }
    }
} elseif ($mode === 'activate') {
    foreach (['REDIS_QUEUE_HOST=10.10.0.3', 'REDIS_QUEUE_PORT=6380', 'REDIS_QUEUE_DB=0'] as $required) {
        if (! preg_match('/^'.preg_quote($required, '/').'\r?$/m', $original)) {
            fwrite(STDERR, "Run prepare and verify the dedicated connection first.\n"); exit(1);
        }
    }
    if (! preg_match('/^REDIS_QUEUE_PASSWORD=[a-f0-9]{64}\r?$/m', $original)) { exit(1); }
}
$updated = $original;
foreach ($changes as $key => $value) {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $line = $key.'='.$value;
    $updated = preg_match($pattern, $updated)
        ? preg_replace_callback($pattern, static fn () => $line, $updated)
        : rtrim($updated, "\r\n")."\n".$line."\n";
}
if ($updated === $original) { echo "Environment already matches requested mode.\n"; exit(0); }
umask(0077);
$suffix = gmdate('YmdHis').'-'.bin2hex(random_bytes(5));
$backup = $target.'.gex017-backup-'.$suffix;
$temporary = $target.'.gex017-new-'.$suffix;
$backupHandle = fopen($backup, 'x');
if (! $backupHandle || fwrite($backupHandle, $original) !== strlen($original)) { exit(1); }
fclose($backupHandle);
$handle = fopen($temporary, 'x');
if (! $handle || fwrite($handle, $updated) !== strlen($updated)) { exit(1); }
fflush($handle);
if (function_exists('fsync')) { fsync($handle); }
fclose($handle);
if (! rename($temporary, $target)) { exit(1); }
echo json_encode(['mode' => $mode, 'changed_keys' => array_keys($changes), 'protected_backup_created' => true, 'configuration_cache_must_be_rebuilt' => true]).PHP_EOL;
