<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';

require_method('GET');

$role = trim((string) ($_GET['role'] ?? ''));
$userId = (int) ($_GET['user_id'] ?? 0);

if ($userId <= 0 || !in_array($role, ['customer', 'courier'], true)) {
    respond([
        'success' => false,
        'message' => 'Parametros invalidos para consultar pedidos.',
    ], 422);
}

try {
    $pdo = db();

    if ($role === 'customer') {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM orders
             WHERE customer_id = :user_id
             ORDER BY updated_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        respond([
            'success' => true,
            'orders' => fetch_orders_by_ids($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN)),
        ]);
    }

    $activeStmt = $pdo->prepare(
        "SELECT id
         FROM orders
         WHERE courier_id = :user_id AND status NOT IN ('delivered', 'cancelled')
         ORDER BY updated_at DESC"
    );
    $activeStmt->execute(['user_id' => $userId]);

    $availableStmt = $pdo->query(
        "SELECT id
         FROM orders
         WHERE status = 'waiting'
         ORDER BY updated_at DESC"
    );

    respond([
        'success' => true,
        'active_orders' => fetch_orders_by_ids($pdo, $activeStmt->fetchAll(PDO::FETCH_COLUMN)),
        'available_orders' => fetch_orders_by_ids($pdo, $availableStmt->fetchAll(PDO::FETCH_COLUMN)),
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible consultar los pedidos.',
        'error' => $exception->getMessage(),
    ], 500);
}
