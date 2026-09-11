<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/delivery_pricing.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$purchasePlacesCount = max(1, (int) ($input['purchase_places_count'] ?? 1));

if ($orderId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido invalido para actualizar lugares de compra.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT status, request_type FROM orders WHERE id = :order_id LIMIT 1'
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

    if (in_array($order['status'], ['delivered', 'cancelled'], true)) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido ya no permite ajustar lugares de compra.',
        ], 409);
    }

    if (($order['request_type'] ?? 'shopping') !== 'shopping') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo los pedidos de compra permiten ajustar lugares de compra.',
        ], 409);
    }

    $productCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM order_items WHERE order_id = " . (int) $orderId
    )->fetchColumn();
    $deliveryFee = calculate_delivery_fee($productCount, $purchasePlacesCount);

    $stmt = $pdo->prepare(
        'UPDATE orders
         SET purchase_places_count = :purchase_places_count,
             delivery_fee = :delivery_fee
         WHERE id = :order_id'
    );
    $stmt->execute([
        'purchase_places_count' => $purchasePlacesCount,
        'delivery_fee' => $deliveryFee,
        'order_id' => $orderId,
    ]);

    system_order_message(
        $pdo,
        $orderId,
        sprintf(
            'Tarifa actualizada: %d lugar(es) de compra y domicilio en %s.',
            $purchasePlacesCount,
            '$' . number_format($deliveryFee, 0, ',', '.')
        )
    );

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Tarifa de domicilio actualizada.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible actualizar los lugares de compra.',
        'error' => $exception->getMessage(),
    ], 500);
}
