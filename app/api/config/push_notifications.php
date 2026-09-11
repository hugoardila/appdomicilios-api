<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/firebase.php';

function register_push_device(
    PDO $pdo,
    int $userId,
    string $appInstanceId,
    string $fcmToken,
    string $platform = 'android',
    ?string $deviceLabel = null,
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO push_devices (
            user_id,
            app_instance_id,
            platform,
            fcm_token,
            device_label,
            is_active,
            last_seen_at
         ) VALUES (
            :user_id,
            :app_instance_id,
            :platform,
            :fcm_token,
            :device_label,
            1,
            NOW()
         )
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            platform = VALUES(platform),
            fcm_token = VALUES(fcm_token),
            device_label = VALUES(device_label),
            is_active = 1,
            last_seen_at = NOW()'
    );
    $stmt->execute([
        'user_id' => $userId,
        'app_instance_id' => $appInstanceId,
        'platform' => $platform,
        'fcm_token' => $fcmToken,
        'device_label' => $deviceLabel,
    ]);
}

function deactivate_push_device(PDO $pdo, int $userId, string $appInstanceId): int
{
    $stmt = $pdo->prepare(
        'UPDATE push_devices
         SET is_active = 0
         WHERE user_id = :user_id AND app_instance_id = :app_instance_id'
    );
    $stmt->execute([
        'user_id' => $userId,
        'app_instance_id' => $appInstanceId,
    ]);

    return $stmt->rowCount();
}

function notify_new_pending_order(PDO $pdo, int $orderId, string $customerName, int $deliveryFee): void
{
    $tokens = approved_courier_push_tokens($pdo);
    if ($tokens === []) {
        return;
    }

    $body = sprintf(
        '%s necesita un domicilio. Cobro base: %s.',
        $customerName !== '' ? $customerName : 'Un cliente',
        format_cop($deliveryFee)
    );

    push_to_tokens(
        $tokens,
        [
            'title' => 'Nueva orden disponible',
            'body' => $body,
            'channel_id' => 'xpertgopitalito_available_orders',
        ],
        [
            'notification_type' => 'new_pending_order',
            'order_id' => (string) $orderId,
            'target_role' => 'courier',
            'customer_name' => $customerName,
            'title' => 'Nueva orden disponible',
            'body' => $body,
        ],
    );
}

function notify_order_chat_message(
    PDO $pdo,
    int $orderId,
    int $recipientUserId,
    string $senderName,
    string $preview,
): void {
    if ($recipientUserId <= 0) {
        return;
    }

    $tokens = user_push_tokens($pdo, $recipientUserId);
    if ($tokens === []) {
        return;
    }

    $recipientRole = user_role_label($pdo, $recipientUserId);
    if ($recipientRole === null) {
        return;
    }

    push_to_tokens(
        $tokens,
        [
            'title' => $senderName !== '' ? $senderName : 'Nuevo mensaje',
            'body' => $preview,
            'channel_id' => 'xpertgopitalito_chat_messages',
        ],
        [
            'notification_type' => 'order_chat_message',
            'order_id' => (string) $orderId,
            'target_role' => $recipientRole,
            'sender_name' => $senderName,
            'title' => $senderName !== '' ? $senderName : 'Nuevo mensaje',
            'body' => $preview,
        ],
    );
}

function approved_courier_push_tokens(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT pd.fcm_token
         FROM push_devices pd
         INNER JOIN users u ON u.id = pd.user_id
         WHERE pd.is_active = 1
           AND u.is_active = 1
           AND u.role = 'courier'
           AND u.approval_status = 'approved'
           AND pd.fcm_token <> ''"
    );

    return array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['fcm_token'] ?? '')),
        $stmt->fetchAll()
    )));
}

function user_push_tokens(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT fcm_token
         FROM push_devices
         WHERE user_id = :user_id
           AND is_active = 1
           AND fcm_token <> ''"
    );
    $stmt->execute(['user_id' => $userId]);

    return array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['fcm_token'] ?? '')),
        $stmt->fetchAll()
    )));
}

function user_role_label(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT role
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return $row['role'] === 'courier' ? 'courier' : 'customer';
}

function push_to_tokens(array $tokens, array $notification, array $data): int
{
    if (!fcm_is_configured()) {
        return 0;
    }

    $sent = 0;

    foreach (array_unique($tokens) as $token) {
        if ($token === '') {
            continue;
        }

        $message = [
            'token' => $token,
            'notification' => [
                'title' => (string) ($notification['title'] ?? 'XpertGoPitalito'),
                'body' => (string) ($notification['body'] ?? ''),
            ],
            'data' => normalize_fcm_data($data),
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'channel_id' => (string) ($notification['channel_id'] ?? 'xpertgopitalito_chat_messages'),
                    'sound' => 'default',
                ],
            ],
        ];

        if (fcm_send_message($message)) {
            $sent++;
        }
    }

    return $sent;
}

function normalize_fcm_data(array $data): array
{
    $normalized = [];

    foreach ($data as $key => $value) {
        if ($value === null) {
            continue;
        }

        if (is_bool($value)) {
            $normalized[$key] = $value ? 'true' : 'false';
            continue;
        }

        $normalized[$key] = (string) $value;
    }

    return $normalized;
}

function format_cop(int $amount): string
{
    return '$' . number_format($amount, 0, ',', '.');
}
