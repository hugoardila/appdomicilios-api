<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/push_notifications.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = request_input();
$orderId = (int) ($input['order_id'] ?? 0);
$senderUserId = (int) ($input['sender_user_id'] ?? 0);
$messageText = trim((string) ($input['message_text'] ?? ''));
$latitude = isset($input['latitude']) && $input['latitude'] !== null ? (float) $input['latitude'] : null;
$longitude = isset($input['longitude']) && $input['longitude'] !== null ? (float) $input['longitude'] : null;
$locationLabel = trim((string) ($input['location_label'] ?? ''));
$hasImageUpload = isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($orderId <= 0 || $senderUserId <= 0) {
    respond([
        'success' => false,
        'message' => 'Pedido y remitente son obligatorios.',
    ], 422);
}

if ($messageText === '' && ($latitude === null || $longitude === null) && !$hasImageUpload) {
    respond([
        'success' => false,
        'message' => 'Debes enviar un mensaje, una ubicacion o una foto.',
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

    $sender = fetch_user_row($pdo, $senderUserId, true);
    if (!$sender || (int) ($sender['is_active'] ?? 0) !== 1) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Remitente no valido para este mensaje.',
        ], 403);
    }

    if ((string) ($order['status'] ?? '') === 'cancelled') {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Este pedido fue cancelado y ya no permite mensajes.',
        ], 409);
    }

    if (!in_array((string) ($order['status'] ?? ''), ['taken', 'shopping', 'on_the_way'], true)) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El chat solo esta disponible cuando la orden esta tomada, comprando o en camino.',
        ], 409);
    }

    $isCustomer = (int) ($order['customer_id'] ?? 0) === $senderUserId;
    $isCourier = (int) ($order['courier_id'] ?? 0) === $senderUserId;
    $recipientUserId = $isCustomer ? (int) ($order['courier_id'] ?? 0) : (int) ($order['customer_id'] ?? 0);

    if (!$isCustomer && !$isCourier) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'Solo el cliente y el domiciliario asignado pueden escribir en este chat.',
        ], 403);
    }

    $imagePath = null;
    if ($hasImageUpload) {
        $imagePath = uploaded_file_relative_path(
            $_FILES['image'],
            'uploads/order-chat',
            'chat_message'
        );
    }

    $messageStmt = $pdo->prepare(
        'INSERT INTO order_messages (
            order_id,
            sender_user_id,
            sender_name,
            message_text,
            image_path,
            latitude,
            longitude,
            location_label
         ) VALUES (
            :order_id,
            :sender_user_id,
            :sender_name,
            :message_text,
            :image_path,
            :latitude,
            :longitude,
            :location_label
         )'
    );
    $messageStmt->execute([
        'order_id' => $orderId,
        'sender_user_id' => $senderUserId,
        'sender_name' => $sender['full_name'],
        'message_text' => $messageText !== '' ? $messageText : null,
        'image_path' => $imagePath,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_label' => $locationLabel !== '' ? $locationLabel : null,
    ]);

    if ($imagePath !== null) {
        $imageRowStmt = $pdo->prepare(
            'INSERT INTO order_images (
                order_id,
                uploader_user_id,
                image_type,
                file_path
             ) VALUES (
                :order_id,
                :uploader_user_id,
                :image_type,
                :file_path
             )'
        );
        $imageRowStmt->execute([
            'order_id' => $orderId,
            'uploader_user_id' => $senderUserId,
            'image_type' => 'chat',
            'file_path' => $imagePath,
        ]);
    }

    $touchStmt = $pdo->prepare('UPDATE orders SET updated_at = NOW() WHERE id = :order_id');
    $touchStmt->execute(['order_id' => $orderId]);

    $pdo->commit();

    $preview = $messageText !== '' ? $messageText : (
        $imagePath !== null ? 'Te envio una foto.' : (
            ($latitude !== null && $longitude !== null) ? 'Te compartio una ubicacion.' : 'Tienes un mensaje nuevo.'
        )
    );

    try {
        notify_order_chat_message(
            $pdo,
            orderId: $orderId,
            recipientUserId: $recipientUserId,
            senderName: (string) ($sender['full_name'] ?? ''),
            preview: $preview,
        );
    } catch (Throwable $pushException) {
        error_log('Push mensaje chat: ' . $pushException->getMessage());
    }

    respond([
        'success' => true,
        'message' => 'Mensaje enviado.',
    ], 201);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible enviar el mensaje.',
        'error' => $exception->getMessage(),
    ], 500);
}
