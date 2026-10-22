<?php

// Private CLI bridge. No HTTP routes, queued work, credentials in arguments, or row deletion.
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$root = realpath($argv[1] ?? '');
$operation = $argv[2] ?? '';
if (! $root || ! is_file($root.'/artisan')) {
    exit(2);
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function emit(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}

try {
    if ($operation === 'notify') {
        $email = $argv[3] ?? '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid alert email.');
        }
        $mailer = (string) config('mail.default');
        if (in_array(config('mail.mailers.'.$mailer.'.transport'), ['log', 'array', null], true)) {
            throw new RuntimeException('A real outbound mail transport is required.');
        }
        // Existing Mailtrap SMTP passwords are API tokens. HTTPS avoids the
        // installed Laravel version's implicit-TLS mapping on STARTTLS port 587.
        if (config('mail.mailers.'.$mailer.'.host') === 'live.smtp.mailtrap.io') {
            $token = (string) config('mail.mailers.'.$mailer.'.password');
            if ($token === '') {
                throw new RuntimeException('Mailtrap token is not configured.');
            }
            $response = Illuminate\Support\Facades\Http::withToken($token)->acceptJson()->timeout(15)
                ->post('https://send.api.mailtrap.io/api/send', [
                    'from' => ['email' => config('mail.from.address'), 'name' => config('mail.from.name')],
                    'to' => [['email' => $email]], 'subject' => $argv[4] ?? 'GEX disk maintenance',
                    'text' => $argv[5] ?? '', 'category' => 'Disk maintenance',
                ]);
            if (! $response->successful() || $response->json('success') !== true) {
                emit(['error_class' => 'MailtrapHttpError'.$response->status(), 'sent' => false]);
                exit(1);
            }
            emit(['sent' => true, 'transport' => 'mailtrap_https']);
            exit(0);
        }
        Illuminate\Support\Facades\Mail::raw($argv[5] ?? '', static function ($message) use ($email, $argv): void {
            $message->to($email)->subject($argv[4] ?? 'GEX disk maintenance');
        });
        emit(['sent' => true, 'transport' => $mailer]);
        exit(0);
    }
    $db = Illuminate\Support\Facades\DB::connection();
    $db->statement('SET SESSION information_schema_stats_expiry=0');
    $db->statement('SET SESSION lock_wait_timeout=3');
    $db->statement('SET SESSION innodb_lock_wait_timeout=3');
    $server = $db->selectOne('SELECT @@hostname AS hostname, @@datadir AS datadir, @@tmpdir AS tmpdir, @@innodb_file_per_table AS file_per_table');
    $local = strcasecmp((string) $server->hostname, (string) gethostname()) === 0
        && is_dir($server->datadir) && is_dir($server->tmpdir) && (int) $server->file_per_table === 1;
    if (! $local) {
        throw new RuntimeException('Refusing database maintenance on a remote or unsupported MySQL host.');
    }
    $tables = ['option_snapshots', 'intraday_option_volumes', 'calculator_expiry_publication_rows'];
    if ($operation === 'inspect') {
        $rows = $db->select("SELECT t.TABLE_NAME AS table_name, t.ENGINE AS engine,
            t.DATA_LENGTH+t.INDEX_LENGTH AS live_bytes, t.DATA_FREE AS reusable_bytes,
            s.FILE_SIZE AS file_bytes, s.SPACE_TYPE AS space_type,
            (SELECT COUNT(*) FROM information_schema.STATISTICS i WHERE i.TABLE_SCHEMA=t.TABLE_SCHEMA AND i.TABLE_NAME=t.TABLE_NAME AND i.INDEX_TYPE='FULLTEXT') AS fulltext_indexes
            FROM information_schema.TABLES t JOIN information_schema.INNODB_TABLESPACES s ON s.NAME=CONCAT(t.TABLE_SCHEMA,'/',t.TABLE_NAME)
            WHERE t.TABLE_SCHEMA=DATABASE() AND t.TABLE_NAME IN (?,?,?)", $tables);
        emit(['local_database' => true, 'datadir' => $server->datadir, 'tmpdir' => $server->tmpdir,
            'active_transactions' => (int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX')->n,
            'long_queries' => (int) $db->selectOne("SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND COMMAND<>'Sleep' AND TIME>=5")->n,
            'pending_metadata_locks' => (int) $db->selectOne("SELECT COUNT(*) AS n FROM performance_schema.metadata_locks WHERE LOCK_STATUS='PENDING'")->n,
            'tables' => $rows]);
    } elseif ($operation === 'compact') {
        $table = $argv[3] ?? '';
        $needed = (int) ($argv[4] ?? 0);
        if (! in_array($table, $tables, true) || $needed < 5 * 1024 ** 3) {
            throw new RuntimeException('Compaction admission failed.');
        }
        if (min(disk_free_space($server->datadir), disk_free_space($server->tmpdir)) < $needed) {
            emit(['table' => $table, 'result' => 'skipped', 'reason' => 'insufficient_working_space']);
            exit(0);
        }
        $identifier = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
        emit(['table' => $table, 'connection_id' => $identifier]);
        $db->statement('ALTER TABLE `'.$table.'` FORCE, ALGORITHM=INPLACE, LOCK=NONE');
        emit(['table' => $table, 'result' => 'compacted']);
    } elseif ($operation === 'cancel') {
        $id = (int) ($argv[3] ?? 0);
        $own = $db->selectOne("SELECT ID FROM information_schema.PROCESSLIST WHERE ID=? AND USER=SUBSTRING_INDEX(CURRENT_USER(),'@',1) AND DB=DATABASE() AND INFO LIKE 'ALTER TABLE % FORCE, ALGORITHM=INPLACE, LOCK=NONE'", [$id]);
        if ($own) {
            $db->statement('KILL QUERY '.(int) $own->ID);
        }
        emit(['cancel_requested' => (bool) $own]);
    } else {
        throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $error) {
    // Do not print exception text: transport errors can contain credentials.
    emit(['error' => 'Maintenance operation failed', 'operation' => $operation,
        'error_class' => get_class($error), 'driver_code' => $error instanceof Illuminate\Database\QueryException ? ($error->errorInfo[1] ?? null) : null]);
    exit(1);
}
