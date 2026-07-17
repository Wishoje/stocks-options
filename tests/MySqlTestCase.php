<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class MySqlTestCase extends TestCase
{
    protected function setUp(): void
    {
        $databaseUrl = $this->environmentValue('DB_URL');
        if ($databaseUrl !== null && trim($databaseUrl) !== '') {
            throw new RuntimeException('MySQL regression tests refuse DB_URL; use an explicit *_test database.');
        }

        $database = (string) ($this->environmentValue('DB_DATABASE') ?? '');
        if (!preg_match('/_(test|testing)$/i', $database)) {
            throw new RuntimeException(
                "MySQL regression tests require DB_DATABASE ending in _test or _testing; received [{$database}]."
            );
        }

        // The preflight above runs before RefreshDatabase can migrate or wipe a database.
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('This regression suite must run against MySQL.');
        }
    }

    private function environmentValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value === false || $value === null ? null : (string) $value;
    }
}
