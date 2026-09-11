<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/delivery_pricing.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$name = trim((string) ($input['name'] ?? ''));

if ($orderId <= 0 || $name === '') {
    respond([
        'success' => false,
        'message' => 'Pedido y nombre del producto son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT request_type, status
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

    if (($order['request_type'] ?? 'shopping') !== 'shopping') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo los pedidos de compra permiten agregar productos.',
        ], 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO order_items (order_id, name, status)
         VALUES (:order_id, :name, 'pending')"
    );
    $stmt->execute([
        'order_id' => $orderId,
        'name' => $name,
    ]);

    $productCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM order_items WHERE order_id = " . (int) $orderId
    )->fetchColumn();
    $purchasePlacesCount = (int) $pdo->query(
        "SELECT purchase_places_count FROM orders WHERE id = " . (int) $orderId
    )->fetchColumn();

    $feeStmt = $pdo->prepare(
        "UPDATE orders
         SET delivery_fee = :delivery_fee
         WHERE id = :order_id"
    );
    $feeStmt->execute([
        'delivery_fee' => calculate_delivery_fee($productCount, $purchasePlacesCount),
        'order_id' => $orderId,
    ]);

    $textStmt = $pdo->prepare(
        "UPDATE orders
         SET original_request_text = CONCAT(original_request_text, '\n', :name)
         WHERE id = :order_id AND status NOT IN ('on_the_way', 'delivered')"
    );
    $textStmt->execute([
        'name' => $name,
        'order_id' => $orderId,
    ]);

    if ($textStmt->rowCount() === 0) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El pedido ya no permite agregar productos.',
        ], 409);
    }

    system_order_message($pdo, $orderId, 'El cliente agrego: ' . $name);
    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Producto agregado.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible agregar el producto.',
        'error' => $exception->getMessage(),
    ], 500);
}
