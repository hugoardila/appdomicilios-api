<?php

declare(strict_types=1);

$databaseLocalPath = __DIR__ . '/database.local.php';
if (is_file($databaseLocalPath)) {
    require_once $databaseLocalPath;
}

defined('DB_HOST') || define('DB_HOST', getenv('APPDOMICILIOS_DB_HOST') ?: '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', (int)(getenv('APPDOMICILIOS_DB_PORT') ?: 3306));
defined('DB_NAME') || define('DB_NAME', getenv('APPDOMICILIOS_DB_NAME') ?: 'appdomicilios');
defined('DB_USER') || define('DB_USER', getenv('APPDOMICILIOS_DB_USER') ?: '');
defined('DB_PASS') || define('DB_PASS', getenv('APPDOMICILIOS_DB_PASS') ?: '');

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}
