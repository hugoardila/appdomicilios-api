<?php

declare(strict_types=1);

require_once __DIR__ . '/config/wallet.php';

require_method('GET');

$userId = (int) ($_GET['user_id'] ?? 0);

if ($userId <= 0) {
    respond([
        'success' => false,
        'message' => 'Usuario invalido.',
    ], 422);
}

try {
    $pdo = db();
    $user = fetch_user_row($pdo, $userId);

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        respond([
            'success' => false,
            'message' => 'Usuario no encontrado.',
        ], 404);
    }

    $user = hydrate_user_with_wallet($pdo, $user);

    respond([
        'success' => true,
        'message' => 'Resumen de saldo consultado.',
        'user' => user_payload($user),
        'wallet_policy' => wallet_policy_payload(),
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible consultar el saldo.',
        'error' => $exception->getMessage(),
    ], 500);
}
