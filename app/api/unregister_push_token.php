<?php

declare(strict_types=1);

require_once __DIR__ . '/config/push_notifications.php';

require_method('POST');

$input = json_input();
$userId = (int) ($input['user_id'] ?? 0);
$appInstanceId = trim((string) ($input['app_instance_id'] ?? ''));

if ($userId <= 0 || $appInstanceId === '') {
    respond([
        'success' => false,
        'message' => 'Usuario e instalacion son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    deactivate_push_device($pdo, $userId, $appInstanceId);

    respond([
        'success' => true,
        'message' => 'Dispositivo retirado de notificaciones.',
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible retirar el dispositivo.',
        'error' => $exception->getMessage(),
    ], 500);
}
