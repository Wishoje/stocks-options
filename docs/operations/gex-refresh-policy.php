<?php

// Explicit, bounded production activation. Never prints environment contents.
if (PHP_SAPI !== 'cli') { exit(1); }
$card = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$keys = ['020' => 'INTRADAY_FRESHNESS_ENABLED', '021' => 'PROVIDER_BACKPRESSURE_ENABLED'];
if (! isset($keys[$card]) || ! in_array($mode, ['enable', 'disable'], true)) {
    fwrite(STDERR, "Usage: php docs/operations/gex-refresh-policy.php 020 enable|disable\n"); exit(1);
}
$release = realpath(getcwd());
$target = realpath(getcwd().'/.env');
$allowed = false;
foreach (['/home/forge/gexoptions.com/', '/home/forge/stocks-options-ss7u2nu2.on-forge.com/'] as $root) {
    if ($release && $target && str_starts_with($release, $root) && str_starts_with($target, $root)) { $allowed = true; }
}
if (! $allowed || ! is_file($target) || ! is_writable($target)) { exit(1); }
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($mode === 'enable' && ! Illuminate\Support\Facades\Schema::hasTable('intraday_refresh_states')) {
    fwrite(STDERR, "The phase020 additive migration must run first.\n"); exit(1);
}
if ($card === '021' && $mode === 'enable') {
    foreach (['work_runs', 'symbol_bootstrap_phases'] as $table) {
        if (! Illuminate\Support\Facades\Schema::hasColumn($table, 'provider_admission_deferrals')) {
            fwrite(STDERR, "The phase021 additive migration must run first.\n"); exit(1);
        }
    }
    if (! config('services.massive.concurrency.enabled') || (int) config('services.massive.concurrency.limit') < 2
        || config('queue.connections.redis.connection') !== 'queue') {
        fwrite(STDERR, "Verified provider concurrency and dedicated queue transport are required.\n"); exit(1);
    }
}
$original = file_get_contents($target);
if ($original === false) { exit(1); }
$key = $keys[$card];
$line = $key.'='.($mode === 'enable' ? 'true' : 'false');
$pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';
$updated = preg_match($pattern, $original)
    ? preg_replace_callback($pattern, static fn () => $line, $original)
    : rtrim($original, "\r\n")."\n".$line."\n";
if ($updated === $original) { echo "Policy already configured.\n"; exit(0); }
umask(0077);
$suffix = gmdate('YmdHis').'-'.bin2hex(random_bytes(5));
$backup = fopen($target.'.gex'.$card.'-backup-'.$suffix, 'x');
if (! $backup || fwrite($backup, $original) !== strlen($original)) { exit(1); }
fclose($backup);
$temporary = $target.'.gex'.$card.'-new-'.$suffix;
$handle = fopen($temporary, 'x');
if (! $handle || fwrite($handle, $updated) !== strlen($updated)) { exit(1); }
fflush($handle);
if (function_exists('fsync')) { fsync($handle); }
fclose($handle);
if (! rename($temporary, $target)) { exit(1); }
echo json_encode(['card' => $card, 'mode' => $mode, 'changed_key' => $key, 'protected_backup_created' => true, 'rebuild_config_and_restart_workers' => true]).PHP_EOL;
