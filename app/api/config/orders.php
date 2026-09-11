<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function format_label(?string $timestamp): string
{
    if ($timestamp === null || $timestamp === '') {
        return '';
    }

    $unix = strtotime($timestamp);
    if ($unix === false) {
        return $timestamp;
    }

    return date('d M, H:i', $unix);
}

function system_order_message(PDO $pdo, int $orderId, string $message): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO order_messages (order_id, sender_user_id, sender_name, message_text)
         VALUES (:order_id, NULL, :sender_name, :message_text)'
    );
    $stmt->execute([
        'order_id' => $orderId,
        'sender_name' => 'Sistema',
        'message_text' => $message,
    ]);
}

function fetch_order(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT
            o.id,
            o.customer_id,
            o.courier_id,
            o.service_category,
            o.service_subcategory,
            o.request_type,
            o.status,
            o.address_text,
            o.address_reference,
            o.latitude,
            o.longitude,
            o.destination_latitude,
            o.destination_longitude,
            o.destination_reference,
            o.share_location,
            o.pickup_address,
            o.pickup_contact_name,
            o.pickup_contact_phone,
            o.dropoff_contact_name,
            o.dropoff_contact_phone,
            o.pickup_payment_amount,
            o.package_weight_kg,
            o.city,
            o.payment_method,
            o.service_fee,
            o.delivery_fee,
            o.purchase_places_count,
            o.customer_fee_mode,
            o.customer_fee_amount,
            o.customer_fee_reversed,
            o.courier_fee_mode,
            o.courier_fee_amount,
            o.courier_fee_reversed,
            o.resolution_reason,
            o.original_request_text,
            o.created_at,
            o.updated_at,
            customer.full_name AS customer_name,
            customer.phone AS customer_phone,
            customer.customer_reputation_score AS customer_reputation_score,
            customer.customer_incidents_count AS customer_incidents_count,
            courier.full_name AS courier_name
         FROM orders o
         INNER JOIN users customer ON customer.id = o.customer_id
         LEFT JOIN users courier ON courier.id = o.courier_id
         WHERE o.id = :order_id
         LIMIT 1'
    );
    $stmt->execute(['order_id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
        'SELECT id, name, quantity, status, created_at, updated_at
         FROM order_items
         WHERE order_id = :order_id
         ORDER BY id ASC'
    );
    $itemsStmt->execute(['order_id' => $orderId]);
    $items = $itemsStmt->fetchAll();

    $messagesStmt = $pdo->prepare(
        "SELECT
            om.id,
            om.sender_user_id,
            om.sender_name,
            om.message_text,
            om.image_path,
            om.latitude,
            om.longitude,
            om.location_label,
            om.seen_by_customer_at,
            om.seen_by_courier_at,
            om.created_at,
            CASE
                WHEN sender.role = 'courier' THEN 1
                ELSE 0
            END AS from_courier
         FROM order_messages om
         LEFT JOIN users sender ON sender.id = om.sender_user_id
         WHERE om.order_id = :order_id
         ORDER BY om.id ASC"
    );
    $messagesStmt->execute(['order_id' => $orderId]);
    $messages = $messagesStmt->fetchAll();

    $imagesStmt = $pdo->prepare(
        "SELECT file_path
         FROM order_images
         WHERE order_id = :order_id AND image_type = 'reference'
         ORDER BY id ASC"
    );
    $imagesStmt->execute(['order_id' => $orderId]);
    $images = array_map(
        static fn (array $row): string => (string) $row['file_path'],
        $imagesStmt->fetchAll()
    );

    $ratingStmt = $pdo->prepare(
        'SELECT rating_value
         FROM courier_ratings
         WHERE order_id = :order_id
         LIMIT 1'
    );
    $ratingStmt->execute(['order_id' => $orderId]);
    $rating = $ratingStmt->fetch();

    return [
        'id' => (string) $order['id'],
        'customer_id' => (string) $order['customer_id'],
        'customer_name' => $order['customer_name'],
        'customer_phone' => $order['customer_phone'] ?? '',
        'courier_id' => $order['courier_id'] !== null ? (string) $order['courier_id'] : '',
        'courier_name' => $order['courier_name'] ?? '',
        'service_category' => $order['service_category'] ?? '',
        'service_subcategory' => $order['service_subcategory'] ?? '',
        'request_type' => $order['request_type'] ?? 'shopping',
        'status' => $order['status'],
        'address_text' => $order['address_text'],
        'address_reference' => $order['address_reference'] ?? '',
        'share_location' => (bool) $order['share_location'],
        'latitude' => $order['latitude'] !== null ? (float) $order['latitude'] : null,
        'longitude' => $order['longitude'] !== null ? (float) $order['longitude'] : null,
        'destination_latitude' => $order['destination_latitude'] !== null ? (float) $order['destination_latitude'] : null,
        'destination_longitude' => $order['destination_longitude'] !== null ? (float) $order['destination_longitude'] : null,
        'destination_reference' => $order['destination_reference'] ?? '',
        'pickup_address' => $order['pickup_address'] ?? '',
        'pickup_contact_name' => $order['pickup_contact_name'] ?? '',
        'pickup_contact_phone' => $order['pickup_contact_phone'] ?? '',
        'dropoff_contact_name' => $order['dropoff_contact_name'] ?? '',
        'dropoff_contact_phone' => $order['dropoff_contact_phone'] ?? '',
        'pickup_payment_amount' => (int) ($order['pickup_payment_amount'] ?? 0),
        'package_weight_kg' => $order['package_weight_kg'] !== null ? (float) $order['package_weight_kg'] : null,
        'city' => $order['city'],
        'payment_method' => $order['payment_method'],
        'service_fee' => (int) round((float) $order['service_fee']),
        'delivery_fee' => (int) round((float) $order['delivery_fee']),
        'purchase_places_count' => max(1, (int) ($order['purchase_places_count'] ?? 1)),
        'customer_fee_mode' => $order['customer_fee_mode'],
        'customer_fee_amount' => (int) $order['customer_fee_amount'],
        'customer_fee_reversed' => (bool) $order['customer_fee_reversed'],
        'courier_fee_mode' => $order['courier_fee_mode'],
        'courier_fee_amount' => (int) $order['courier_fee_amount'],
        'courier_fee_reversed' => (bool) $order['courier_fee_reversed'],
        'resolution_reason' => $order['resolution_reason'] ?? '',
        'courier_rating_value' => $rating ? (int) $rating['rating_value'] : null,
        'customer_reputation' => [
            'score' => round((float) ($order['customer_reputation_score'] ?? 5.0), 2),
            'incidents_count' => (int) ($order['customer_incidents_count'] ?? 0),
            'warning_active' => (int) ($order['customer_incidents_count'] ?? 0) > 0 && (float) ($order['customer_reputation_score'] ?? 5.0) <= 3.0,
        ],
        'original_request_text' => $order['original_request_text'],
        'created_at_label' => format_label((string) $order['created_at']),
        'updated_at_label' => format_label((string) $order['updated_at']),
        'reference_images' => $images,
        'items' => array_map(
            static fn (array $item): array => [
                'id' => (string) $item['id'],
                'name' => $item['name'],
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'status' => $item['status'],
            ],
            $items
        ),
        'messages' => array_map(
            static fn (array $message): array => [
                'id' => (string) $message['id'],
                'sender_user_id' => $message['sender_user_id'] !== null ? (string) $message['sender_user_id'] : '',
                'sender_name' => $message['sender_name'],
                'message_text' => $message['message_text'] ?? '',
                'image_path' => $message['image_path'] ?? '',
                'latitude' => $message['latitude'] !== null ? (float) $message['latitude'] : null,
                'longitude' => $message['longitude'] !== null ? (float) $message['longitude'] : null,
                'location_label' => $message['location_label'] ?? '',
                'seen_by_customer' => !empty($message['seen_by_customer_at']),
                'seen_by_courier' => !empty($message['seen_by_courier_at']),
                'seen_by_customer_label' => format_label($message['seen_by_customer_at'] ?? null),
                'seen_by_courier_label' => format_label($message['seen_by_courier_at'] ?? null),
                'from_courier' => (bool) $message['from_courier'],
                'created_at_label' => format_label((string) $message['created_at']),
            ],
            $messages
        ),
    ];
}

function fetch_orders_by_ids(PDO $pdo, array $orderIds): array
{
    $orders = [];

    foreach ($orderIds as $orderId) {
        $order = fetch_order($pdo, (int) $orderId);
        if ($order !== null) {
            $orders[] = $order;
        }
    }

    return $orders;
}
