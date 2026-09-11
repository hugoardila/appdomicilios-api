<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$courierId = (int) ($input['courier_id'] ?? 0);

if ($orderId <= 0 || $courierId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido y domiciliario son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $courier = fetch_user_row($pdo, $courierId, true);

    if (
        !$courier ||
        $courier['role'] !== 'courier' ||
        (int) $courier['is_active'] !== 1 ||
        $courier['approval_status'] !== 'approved'
    ) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El domiciliario no esta aprobado para tomar pedidos.',
        ], 403);
    }

    $activeCountStmt = $pdo->prepare(
        "SELECT id
         FROM orders
         WHERE courier_id = :courier_id AND status <> 'delivered'
         FOR UPDATE"
    );
    $activeCountStmt->execute(['courier_id' => $courierId]);
    $activeCount = count($activeCountStmt->fetchAll());

    if ($activeCount >= 3) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este domiciliario ya tiene 3 pedidos activos.',
        ], 409);
    }

    $orderStmt = $pdo->prepare(
        'SELECT id, status
         FROM orders
         WHERE id = :order_id
         FOR UPDATE'
    );
    $orderStmt->execute(['order_id' => $orderId]);
    $order = $orderStmt->fetch();

    if (!$order || $order['status'] !== 'waiting') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El pedido ya no esta disponible.',
        ], 409);
    }

    apply_courier_charge_for_order($pdo, $orderId, $courier);

    $updateStmt = $pdo->prepare(
        "UPDATE orders
         SET courier_id = :courier_id, status = 'taken'
         WHERE id = :order_id"
    );
    $updateStmt->execute([
        'courier_id' => $courierId,
        'order_id' => $orderId,
    ]);

    system_order_message($pdo, $orderId, 'Pedido tomado por ' . $courier['full_name'] . '.');
    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Pedido tomado.',
    ]);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => $exception->getMessage(),
    ], 409);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible tomar el pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}
