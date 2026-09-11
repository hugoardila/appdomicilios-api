<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$status = trim((string) ($input['status'] ?? ''));

if ($orderId <= 0 || !in_array($status, ['waiting', 'taken', 'shopping', 'on_the_way', 'delivered'], true)) {
    respond([
        'success' => false,
        'message' => 'Parametros invalidos para actualizar el pedido.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'UPDATE orders
         SET status = :status
         WHERE id = :order_id'
    );
    $stmt->execute([
        'status' => $status,
        'order_id' => $orderId,
    ]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Pedido no encontrado o sin cambios.',
        ], 404);
    }

    $labels = [
        'waiting' => 'En espera',
        'taken' => 'Tomado',
        'shopping' => 'Comprando',
        'on_the_way' => 'En camino',
        'delivered' => 'Entregado',
    ];
    system_order_message(
        $pdo,
        $orderId,
        'Estado actualizado a ' . ($labels[$status] ?? $status) . '.'
    );

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Pedido actualizado.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible actualizar el pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}
