<?php

declare(strict_types=1);

require_once __DIR__ . '/config/push_notifications.php';

require_method('POST');

$input = json_input();
$userId = (int) ($input['user_id'] ?? 0);
$appInstanceId = trim((string) ($input['app_instance_id'] ?? ''));
$fcmToken = trim((string) ($input['fcm_token'] ?? ''));
$platform = trim((string) ($input['platform'] ?? 'android'));
$deviceLabel = trim((string) ($input['device_label'] ?? ''));

if ($userId <= 0 || $appInstanceId === '' || $fcmToken === '') {
    respond([
        'success' => false,
        'message' => 'Usuario, instalacion y token son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :user_id AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['user_id' => $userId]);

    if (!$stmt->fetch()) {
        respond([
            'success' => false,
            'message' => 'Usuario no disponible para recibir notificaciones.',
        ], 404);
    }

    register_push_device(
        $pdo,
        userId: $userId,
        appInstanceId: $appInstanceId,
        fcmToken: $fcmToken,
        platform: $platform !== '' ? $platform : 'android',
        deviceLabel: $deviceLabel !== '' ? $deviceLabel : null,
    );

    respond([
        'success' => true,
        'message' => 'Dispositivo registrado para notificaciones.',
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible registrar el dispositivo.',
        'error' => $exception->getMessage(),
    ], 500);
}
