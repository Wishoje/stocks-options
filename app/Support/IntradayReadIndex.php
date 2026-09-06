<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Explicit, additive-only index rollout. Never called by a migration. */
final class IntradayReadIndex
{
    public const TABLE = 'intraday_option_volumes';

    public const NAME = 'idx_intraday_symbol_capture_strike';

    public const COLUMNS = ['symbol', 'captured_at', 'strike_price'];

    public const MAX_ROWS = 1_000_000;

    public const MIN_FREE_BYTES = 536_870_912;

    public function __construct(private readonly string $table = self::TABLE, private readonly int $maximumRows = self::MAX_ROWS)
    {
        if ($table !== self::TABLE
            && (! app()->environment('testing') || ! preg_match('/^gex_intraday_idx_test_[a-f0-9]{16}$/D', $table))) {
            throw new InvalidArgumentException('Only the intraday table or an isolated test fixture is allowed.');
        }
        if ($maximumRows < 1 || $maximumRows > self::MAX_ROWS) {
            throw new InvalidArgumentException('The row admission limit may only be tightened below one million.');
        }
    }

    /** SELECT-only inspection, including a time- and row-bounded actual count. */
    public function inspect(): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('The intraday index rollout requires MySQL 8 and InnoDB.');
        }
        $version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
        if (! preg_match('/^8\./', $version) || stripos($version, 'mariadb') !== false) {
            throw new RuntimeException('This bounded index rollout is validated only for MySQL 8.');
        }
        $table = $connection->selectOne(
            'SELECT ENGINE AS engine, TABLE_TYPE AS table_type, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->table],
        );
        if (! $table || strtoupper((string) $table->engine) !== 'INNODB' || $table->table_type !== 'BASE TABLE') {
            throw new RuntimeException('The intraday table must exist as an InnoDB base table.');
        }

        $columns = collect($connection->select(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS max_length, NUMERIC_PRECISION AS numeric_precision, NUMERIC_SCALE AS numeric_scale, DATETIME_PRECISION AS datetime_precision, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->table],
        ))->keyBy('name');
        if (($columns->get('symbol')->type ?? null) !== 'varchar'
            || (int) ($columns->get('symbol')->max_length ?? 0) !== 16
            || ($columns->get('captured_at')->type ?? null) !== 'timestamp'
            || (int) ($columns->get('captured_at')->datetime_precision ?? -1) !== 0
            || ($columns->get('strike_price')->type ?? null) !== 'decimal'
            || (int) ($columns->get('strike_price')->numeric_precision ?? 0) !== 12
            || (int) ($columns->get('strike_price')->numeric_scale ?? -1) !== 4
            || collect(self::COLUMNS)->contains(fn (string $name): bool => ($columns->get($name)->nullable ?? null) !== 'NO')) {
            throw new RuntimeException('Intraday index columns differ from the reviewed schema; refusing DDL.');
        }

        $indexes = collect($connection->select(
            'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS sub_part, INDEX_TYPE AS index_type, IS_VISIBLE AS visible, COLLATION AS direction FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->table],
        ))->groupBy('name')->map(fn ($parts): array => $parts->map(fn ($part): array => (array) $part)->all())->all();
        if (isset($indexes[self::NAME]) && ! $this->compatible($indexes[self::NAME], exact: true)) {
            throw new RuntimeException('The candidate index name already exists with a conflicting definition.');
        }
        $compatible = null;
        foreach ($indexes as $name => $parts) {
            if ($this->compatible($parts)) {
                $compatible = $name;
                break;
            }
        }

        $count = (int) $connection->selectOne(
            'SELECT /*+ MAX_EXECUTION_TIME(3000) */ COUNT(*) AS row_count FROM (SELECT id FROM '.$this->identifier().' LIMIT '.($this->maximumRows + 1).') AS bounded_intraday_rows',
        )->row_count;
        // Budget a 512-MiB reserve plus 512 bytes per indexed row for the
        // secondary index, sorting and concurrent-write overhead. This is an
        // admission estimate, not a claim about measured database-host space.
        $requiredFree = self::MIN_FREE_BYTES + $count * 512;

        return [
            'table' => $this->table,
            'mysql_version' => $version,
            'index' => self::NAME,
            'columns' => self::COLUMNS,
            'compatible_index' => $compatible,
            'status' => $compatible ? 'already_present' : 'planned',
            'actual_rows_up_to_cap' => $count,
            'row_count_complete' => $count <= $this->maximumRows,
            'maximum_rows' => $this->maximumRows,
            'estimated_rows' => (int) $table->estimated_rows,
            'data_bytes' => (int) $table->data_bytes,
            'index_bytes' => (int) $table->index_bytes,
            'required_database_free_bytes' => $requiredFree,
            'database_free_bytes_verified_by_command' => false,
            'backup_verified_by_command' => false,
            'maximum_metadata_lock_wait_seconds' => 5,
            'sql' => $this->statement(),
            'existing_indexes' => $indexes,
        ];
    }

    public function apply(string $backupReference, int $databaseFreeBytes): array
    {
        if (trim($backupReference) === '') {
            throw new InvalidArgumentException('Apply requires a nonempty backup reference confirmed by the operator.');
        }
        if ($databaseFreeBytes < 0) {
            throw new InvalidArgumentException('Database-host free bytes must be a nonnegative integer.');
        }
        $report = $this->inspect();
        $report['operator_backup_reference_supplied'] = true;
        $report['operator_reported_database_free_bytes'] = $databaseFreeBytes;
        if ($report['compatible_index'] !== null) {
            return $report;
        }
        if (! $report['row_count_complete']) {
            throw new RuntimeException('The intraday row cap is exceeded; this requires a separately reviewed rollout.');
        }
        if ($databaseFreeBytes < $report['required_database_free_bytes']) {
            throw new RuntimeException('Reported database-host free space is below the bounded rollout requirement.');
        }
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('Index DDL must not run inside an application transaction.');
        }
        $originalTimeout = (int) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds')->seconds;
        $timeout = min(5, max(1, $originalTimeout));
        $started = hrtime(true);
        try {
            $connection->statement('SET SESSION lock_wait_timeout = '.$timeout);
            // Explicit algorithm and lock clauses fail instead of falling back
            // to a table-copy or write-blocking operation.
            $connection->statement($this->statement());
        } finally {
            $connection->statement('SET SESSION lock_wait_timeout = '.$originalTimeout);
        }
        $after = $this->inspect();
        if ($after['compatible_index'] === null) {
            throw new RuntimeException('DDL returned without the expected compatible index.');
        }

        return array_merge($after, [
            'status' => 'applied',
            'ddl_duration_ms' => round((hrtime(true) - $started) / 1_000_000, 3),
            'operator_backup_reference_supplied' => true,
            'operator_reported_database_free_bytes' => $databaseFreeBytes,
            'lock_wait_timeout_restored' => true,
        ]);
    }

    private function compatible(array $parts, bool $exact = false): bool
    {
        if (count($parts) < count(self::COLUMNS) || ($exact && count($parts) !== count(self::COLUMNS))) {
            return false;
        }
        if ($exact && (int) $parts[0]['non_unique'] !== 1) {
            return false;
        }
        foreach ($parts as $offset => $part) {
            if ($part['sub_part'] !== null || strtoupper((string) $part['index_type']) !== 'BTREE'
                || $part['visible'] !== 'YES' || $part['direction'] !== 'A'
                || ! is_string($part['column_name'])
                || ($offset < count(self::COLUMNS) && $part['column_name'] !== self::COLUMNS[$offset])) {
                return false;
            }
        }

        return true;
    }

    private function identifier(): string
    {
        return '`'.$this->table.'`';
    }

    private function statement(): string
    {
        return 'ALTER TABLE '.$this->identifier().' ADD INDEX `'.self::NAME.'` (`symbol`, `captured_at`, `strike_price`), ALGORITHM=INPLACE, LOCK=NONE';
    }
}
