<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$viewerUserId = (int) ($input['viewer_user_id'] ?? 0);

if ($orderId <= 0 || $viewerUserId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido y usuario son obligatorios para marcar mensajes vistos.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT id, customer_id, courier_id, status
         FROM orders
         WHERE id = :order_id
         LIMIT 1
         FOR UPDATE'
    );
    $orderStmt->execute(['order_id' => $orderId]);
    $order = $orderStmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Pedido no encontrado.',
        ], 404);
    }

    $viewer = fetch_user_row($pdo, $viewerUserId, true);
    if (!$viewer || (int) ($viewer['is_active'] ?? 0) !== 1) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Usuario no valido para marcar mensajes vistos.',
        ], 403);
    }

    $isCustomer = (int) ($order['customer_id'] ?? 0) === $viewerUserId;
    $isCourier = (int) ($order['courier_id'] ?? 0) === $viewerUserId;

    if (!$isCustomer && !$isCourier) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo el cliente y el domiciliario asignado pueden ver este chat.',
        ], 403);
    }

    if ((string) ($order['status'] ?? '') === 'cancelled') {
        $pdo->commit();
        respond([
            'success' => true,
            'message' => 'Sin cambios en mensajes vistos.',
            'updated_count' => 0,
        ]);
    }

    if ($isCustomer) {
        $seenStmt = $pdo->prepare(
            "UPDATE order_messages om
             INNER JOIN users sender ON sender.id = om.sender_user_id
             SET om.seen_by_customer_at = NOW()
             WHERE om.order_id = :order_id
               AND sender.role = 'courier'
               AND om.seen_by_customer_at IS NULL"
        );
    } else {
        $seenStmt = $pdo->prepare(
            "UPDATE order_messages om
             INNER JOIN users sender ON sender.id = om.sender_user_id
             SET om.seen_by_courier_at = NOW()
             WHERE om.order_id = :order_id
               AND sender.role = 'customer'
               AND om.seen_by_courier_at IS NULL"
        );
    }

    $seenStmt->execute(['order_id' => $orderId]);
    $updatedCount = $seenStmt->rowCount();

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Mensajes vistos actualizados.',
        'updated_count' => $updatedCount,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible actualizar los mensajes vistos.',
        'error' => $exception->getMessage(),
    ], 500);
}
