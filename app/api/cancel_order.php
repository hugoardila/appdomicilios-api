<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$customerId = (int) ($input['customer_id'] ?? 0);

if ($orderId <= 0 || $customerId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido y cliente son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $customer = fetch_user_row($pdo, $customerId, true);
    if (!$customer || $customer['role'] !== 'customer' || (int) $customer['is_active'] !== 1) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El cliente no esta habilitado para cancelar pedidos.',
        ], 403);
    }

    $orderStmt = $pdo->prepare(
        'SELECT
            id,
            customer_id,
            status,
            customer_fee_mode,
            customer_fee_amount,
            customer_fee_reversed
         FROM orders
         WHERE id = :order_id
         LIMIT 1
         FOR UPDATE'
    );
    $orderStmt->execute(['order_id' => $orderId]);
    $order = $orderStmt->fetch();

    if (!$order || (int) $order['customer_id'] !== $customerId) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Ese pedido no le pertenece al cliente.',
        ], 403);
    }

    if ($order['status'] !== 'waiting') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo puedes cancelar pedidos que aun no han sido tomados.',
        ], 409);
    }

    reverse_customer_charge_for_cancelled_order($pdo, $order);

    $updateStmt = $pdo->prepare(
        "UPDATE orders
         SET
            status = 'cancelled',
            resolution_reason = 'cancelled_by_customer',
            resolved_by_user_id = :customer_id,
            resolved_at = NOW()
         WHERE id = :order_id"
    );
    $updateStmt->execute([
        'customer_id' => $customerId,
        'order_id' => $orderId,
    ]);

    system_order_message($pdo, $orderId, 'El cliente cancelo el pedido antes de que fuera tomado.');
    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Pedido cancelado.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible cancelar el pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}
