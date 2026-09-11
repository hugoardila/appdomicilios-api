<?php

declare(strict_types=1);

require_once __DIR__ . '/config/admin.php';

require_method('POST');

$input = request_input();
$accessKey = trim((string) ($input['access_key'] ?? ''));
$courierId = (int) ($input['courier_id'] ?? 0);
$decision = trim((string) ($input['decision'] ?? ''));

require_review_access_key($accessKey);

if ($courierId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    respond([
        'success' => false,
        'message' => 'Solicitud de revision invalida.',
    ], 422);
}

$nextStatus = $decision === 'approve' ? 'approved' : 'rejected';
$message = $decision === 'approve'
    ? 'Domiciliario aprobado correctamente.'
    : 'Domiciliario rechazado correctamente.';

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        "UPDATE users
         SET approval_status = :approval_status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
           AND role = 'courier'"
    );
    $stmt->execute([
        'approval_status' => $nextStatus,
        'id' => $courierId,
    ]);

    if ($stmt->rowCount() === 0) {
        respond([
            'success' => false,
            'message' => 'No encontramos ese domiciliario para revisar.',
        ], 404);
    }

    respond([
        'success' => true,
        'message' => $message,
        'approval_status' => $nextStatus,
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible actualizar el estado del domiciliario.',
        'error' => $exception->getMessage(),
    ], 500);
}
