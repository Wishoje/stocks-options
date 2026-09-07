<?php

// Child process for synthetic, guarded MySQL concurrency tests only.
require dirname(__DIR__, 2).'/tests/mysql-bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    if (! $app->environment('testing')) {
        throw new RuntimeException('Test environment required.');
    }
    config()->set([
        'cache.default' => 'array', 'eod_publications.write_enabled' => true,
        'eod_publications.read_enabled' => true, 'eod_snapshot_health.enabled' => false,
    ]);
    $order = (int) ($argv[1] ?? 0);
    if ($order < 200 || $order > 500) {
        throw new RuntimeException('Synthetic issuance required.');
    }
    app(App\Support\EodCacheVersion::class)->publish(['SPY'], ['activity'], 'synthetic-'.$order, $order);
    echo "published\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Synthetic publication failed: '.get_class($exception)."\n");
    exit(1);
}
