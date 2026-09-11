<?php

declare(strict_types=1);

require_once __DIR__ . '/config/admin.php';

require_method('POST');

$input = request_input();
$accessKey = trim((string) ($input['access_key'] ?? ''));

require_review_access_key($accessKey);

try {
    $pdo = db();

    $stmt = $pdo->query(
        "SELECT
            id,
            full_name,
            national_id,
            phone,
            email,
            approval_status,
            courier_document_type,
            courier_document_path,
            courier_selfie_path,
            courier_verification_submitted_at,
            created_at
         FROM users
         WHERE role = 'courier'
           AND approval_status = 'pending'
         ORDER BY courier_verification_submitted_at DESC, created_at DESC"
    );

    $couriers = array_map(
        static function (array $row): array {
            return [
                'id' => (string) $row['id'],
                'full_name' => (string) $row['full_name'],
                'national_id' => (string) $row['national_id'],
                'phone' => (string) $row['phone'],
                'email' => (string) $row['email'],
                'approval_status' => (string) $row['approval_status'],
                'document_type' => (string) ($row['courier_document_type'] ?? ''),
                'document_label' => courier_document_label($row['courier_document_type'] ?? null),
                'document_url' => review_file_url($row['courier_document_path'] ?? null),
                'selfie_url' => review_file_url($row['courier_selfie_path'] ?? null),
                'submitted_at' => (string) ($row['courier_verification_submitted_at'] ?? $row['created_at']),
            ];
        },
        $stmt->fetchAll()
    );

    respond([
        'success' => true,
        'couriers' => $couriers,
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible cargar los domiciliarios pendientes.',
        'error' => $exception->getMessage(),
    ], 500);
}
