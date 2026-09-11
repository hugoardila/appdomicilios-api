<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$orderId = (int) ($input['order_id'] ?? 0);
$customerId = (int) ($input['customer_id'] ?? 0);
$ratingValue = (int) ($input['rating_value'] ?? 0);

if ($orderId <= 0 || $customerId <= 0 || $ratingValue < 1 || $ratingValue > 5) {
    respond([
        'success' => false,
        'message' => 'Pedido, cliente y calificacion valida son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare(
        'SELECT id, customer_id, courier_id, status
         FROM orders
         WHERE id = :order_id
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

    if ((int) $order['customer_id'] !== $customerId) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo el cliente del pedido puede calificar este servicio.',
        ], 403);
    }

    if (($order['status'] ?? '') !== 'delivered') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo puedes calificar pedidos que ya fueron entregados.',
        ], 409);
    }

    $courierId = (int) ($order['courier_id'] ?? 0);
    if ($courierId <= 0) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido no tiene domiciliario asociado para calificar.',
        ], 409);
    }

    $existingStmt = $pdo->prepare(
        'SELECT id
         FROM courier_ratings
         WHERE order_id = :order_id
         LIMIT 1'
    );
    $existingStmt->execute(['order_id' => $orderId]);

    if ($existingStmt->fetch()) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido ya fue calificado.',
        ], 409);
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO courier_ratings (
            order_id,
            customer_id,
            courier_id,
            rating_value
         ) VALUES (
            :order_id,
            :customer_id,
            :courier_id,
            :rating_value
         )'
    );
    $insertStmt->execute([
        'order_id' => $orderId,
        'customer_id' => $customerId,
        'courier_id' => $courierId,
        'rating_value' => $ratingValue,
    ]);

    system_order_message(
        $pdo,
        $orderId,
        'El cliente califico este servicio con ' . $ratingValue . ' estrella(s).'
    );

    $courier = fetch_user_row($pdo, $courierId);
    $summary = courier_rating_summary($pdo, $courier ?: ['id' => $courierId, 'role' => 'courier']);

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Calificacion registrada.',
        'courier_rating' => $summary,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible registrar la calificacion.',
        'error' => $exception->getMessage(),
    ], 500);
}
