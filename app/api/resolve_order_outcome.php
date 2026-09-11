<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$courierId = (int) ($input['courier_id'] ?? 0);
$resolutionReason = trim((string) ($input['resolution_reason'] ?? ''));

$allowedReasons = ['delivered', 'customer_not_found', 'customer_noncompliance'];

if ($orderId <= 0 || $courierId <= 0 || !in_array($resolutionReason, $allowedReasons, true)) {
    respond([
        'success' => false,
        'message' => 'Parametros invalidos para cerrar el pedido.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT id, customer_id, courier_id, status, resolution_reason
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

    if ((int) ($order['courier_id'] ?? 0) !== $courierId) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo el domiciliario asignado puede cerrar este pedido.',
        ], 403);
    }

    if (!in_array((string) ($order['status'] ?? ''), ['taken', 'shopping', 'on_the_way'], true)) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido ya no admite un cierre manual desde el domiciliario.',
        ], 409);
    }

    if (!empty($order['resolution_reason'])) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido ya fue cerrado antes.',
        ], 409);
    }

    $newStatus = $resolutionReason === 'delivered' ? 'delivered' : 'cancelled';

    $updateStmt = $pdo->prepare(
        'UPDATE orders
         SET
            status = :status,
            resolution_reason = :resolution_reason,
            resolved_by_user_id = :resolved_by_user_id,
            resolved_at = NOW()
         WHERE id = :order_id'
    );
    $updateStmt->execute([
        'status' => $newStatus,
        'resolution_reason' => $resolutionReason,
        'resolved_by_user_id' => $courierId,
        'order_id' => $orderId,
    ]);

    if ($resolutionReason === 'delivered') {
        system_order_message($pdo, $orderId, 'Pedido entregado por el domiciliario.');
    } elseif ($resolutionReason === 'customer_not_found') {
        penalize_customer_reputation_for_order(
            $pdo,
            orderId: $orderId,
            customerId: (int) $order['customer_id'],
            courierId: $courierId,
            reason: $resolutionReason,
        );
        system_order_message($pdo, $orderId, 'Pedido cerrado: cliente no encontrado. El cliente quedo marcado para futuras ordenes.');
    } else {
        penalize_customer_reputation_for_order(
            $pdo,
            orderId: $orderId,
            customerId: (int) $order['customer_id'],
            courierId: $courierId,
            reason: $resolutionReason,
        );
        system_order_message($pdo, $orderId, 'Pedido cerrado por incumplimiento del cliente. El cliente quedo marcado para futuras ordenes.');
    }

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Pedido cerrado correctamente.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible cerrar el pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}
