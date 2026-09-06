<?php

// Standalone local-test writer. Never accepts a production table or database.
$root = dirname(__DIR__, 2);
require $root.'/tests/mysql-bootstrap.php';
$table = $argv[1] ?? '';
if (! preg_match('/^gex_intraday_idx_test_[a-f0-9]{16}$/D', $table)) {
    throw new RuntimeException('Only a disposable intraday index fixture is permitted.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql' || ! preg_match('/_(test|testing)$/i', $db->getDatabaseName())) {
    throw new RuntimeException('Concurrent index writer requires an explicit MySQL test database.');
}
for ($batch = 0; $batch < 20; $batch++) {
    $rows = [];
    for ($offset = 0; $offset < 100; $offset++) {
        $index = $batch * 100 + $offset;
        $rows[] = ['symbol' => 'CONCURRENT', 'contract_symbol' => 'O:CONCURRENT'.str_pad((string) $index, 8, '0', STR_PAD_LEFT),
            'contract_type' => $index % 2 ? 'call' : 'put', 'expiration_date' => '2026-09-11',
            'strike_price' => 100 + $index / 100, 'captured_at' => '2026-09-08 16:00:00',
            'implied_volatility' => '0.250000', 'volume' => $index, 'open_interest' => $index * 2];
    }
    $db->table($table)->upsert($rows, ['contract_symbol', 'captured_at'], ['volume', 'open_interest']);
    if ($batch === 0) {
        echo "WRITER_READY\n";
        flush();
    }
    usleep(100000);
}
echo "WRITER_COMPLETE rows=2000\n";
