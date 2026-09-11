<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$itemId = (int) ($input['item_id'] ?? 0);
$status = trim((string) ($input['status'] ?? ''));

if ($orderId <= 0 || $itemId <= 0 || !in_array($status, ['pending', 'purchased', 'not_found'], true)) {
    respond([
        'success' => false,
        'message' => 'Parametros invalidos para actualizar el item.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $itemStmt = $pdo->prepare(
        'UPDATE order_items
         SET status = :status
         WHERE id = :item_id AND order_id = :order_id'
    );
    $itemStmt->execute([
        'status' => $status,
        'item_id' => $itemId,
        'order_id' => $orderId,
    ]);

    if ($itemStmt->rowCount() === 0) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'No se encontro el item para este pedido.',
        ], 404);
    }

    $statusStmt = $pdo->prepare(
        'SELECT status, request_type FROM orders WHERE id = :order_id FOR UPDATE'
    );
    $statusStmt->execute(['order_id' => $orderId]);
    $orderRow = $statusStmt->fetch();
    $currentOrderStatus = (string) ($orderRow['status'] ?? 'waiting');

    if (($orderRow['request_type'] ?? 'shopping') !== 'shopping') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo los pedidos de compra permiten marcar productos.',
        ], 409);
    }

    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*) AS total
         FROM order_items
         WHERE order_id = :order_id AND status = 'pending'"
    );
    $pendingStmt->execute(['order_id' => $orderId]);
    $pendingCount = (int) ($pendingStmt->fetch()['total'] ?? 0);

    $nextOrderStatus = $currentOrderStatus;
    if ($currentOrderStatus === 'taken') {
        $nextOrderStatus = 'shopping';
    }
    if ($pendingCount === 0 && in_array($nextOrderStatus, ['taken', 'shopping'], true)) {
        $nextOrderStatus = 'on_the_way';
    }

    $orderUpdateStmt = $pdo->prepare(
        'UPDATE orders
         SET status = :status
         WHERE id = :order_id'
    );
    $orderUpdateStmt->execute([
        'status' => $nextOrderStatus,
        'order_id' => $orderId,
    ]);

    if ($nextOrderStatus !== $currentOrderStatus) {
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
            'Estado actualizado a ' . ($labels[$nextOrderStatus] ?? $nextOrderStatus) . '.'
        );
    }

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Item actualizado.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible actualizar el item.',
        'error' => $exception->getMessage(),
    ], 500);
}
