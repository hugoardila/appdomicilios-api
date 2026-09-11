<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';

require_method('GET');

$orderId = (int) ($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido invalido.',
    ], 422);
}

try {
    $order = fetch_order(db(), $orderId);

    if ($order === null) {
        respond([
            'success' => false,
            'message' => 'Pedido no encontrado.',
        ], 404);
    }

    respond([
        'success' => true,
        'order' => $order,
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible consultar el detalle del pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}

