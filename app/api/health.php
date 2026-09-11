<?php

declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

try {
    $pdo = db();
    $version = $pdo->query('SELECT VERSION() AS version')->fetch();

    respond([
        'success' => true,
        'message' => 'API local activa.',
        'database' => [
            'name' => DB_NAME,
            'version' => $version['version'] ?? null,
        ],
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible conectar con la base de datos.',
        'error' => $exception->getMessage(),
    ], 500);
}

