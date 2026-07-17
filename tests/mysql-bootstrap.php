<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$testingEnvironment = [];
$testingPath = dirname(__DIR__).'/.env.testing';
if (is_file($testingPath)) {
    $parsed = parse_ini_file($testingPath, false, INI_SCANNER_RAW);
    if (is_array($parsed)) {
        $testingEnvironment = $parsed;
    }
}

$effective = static function (string $key) use ($testingEnvironment): ?string {
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }

    $processValue = getenv($key);
    if ($processValue !== false) {
        return (string) $processValue;
    }

    return array_key_exists($key, $testingEnvironment)
        ? (string) $testingEnvironment[$key]
        : null;
};

$databaseUrl = trim((string) ($effective('DB_URL') ?? ''));
if ($databaseUrl !== '') {
    throw new RuntimeException('MySQL tests refuse DB_URL; configure an explicit disposable *_test database.');
}

$connection = strtolower(trim((string) ($effective('DB_CONNECTION') ?? 'mysql')));
if ($connection !== 'mysql') {
    throw new RuntimeException("MySQL tests require DB_CONNECTION=mysql; received [{$connection}].");
}

$database = trim((string) ($effective('DB_DATABASE') ?? ''));
if (preg_match('/_(test|testing)$/i', $database) !== 1) {
    throw new RuntimeException(
        "Refusing destructive tests: effective DB_DATABASE must end in _test or _testing; received [{$database}]."
    );
}
