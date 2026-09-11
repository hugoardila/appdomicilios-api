<?php

declare(strict_types=1);

require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = request_input();

$fullName = trim((string) ($input['full_name'] ?? ''));
$nationalId = trim((string) ($input['national_id'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$password = trim((string) ($input['password'] ?? ''));
$role = trim((string) ($input['role'] ?? 'customer'));
$documentType = trim((string) ($input['document_type'] ?? ''));

if (
    $fullName === '' ||
    $nationalId === '' ||
    $phone === '' ||
    $email === '' ||
    $password === ''
) {
    respond([
        'success' => false,
        'message' => 'Todos los campos son obligatorios.',
    ], 422);
}

if (!in_array($role, ['customer', 'courier'], true)) {
    respond([
        'success' => false,
        'message' => 'Rol invalido.',
    ], 422);
}

if ($role === 'courier' && !in_array($documentType, ['national_id_front', 'driver_license_front'], true)) {
    respond([
        'success' => false,
        'message' => 'Selecciona si vas a subir cedula o licencia.',
    ], 422);
}

if (strlen($password) < 6) {
    respond([
        'success' => false,
        'message' => 'La contrasena debe tener al menos 6 caracteres.',
    ], 422);
}

$approvalStatus = $role === 'courier' ? 'pending' : 'approved';
$passwordHash = password_hash($password, PASSWORD_BCRYPT);
$courierDocumentPath = null;
$courierSelfiePath = null;
$courierVerificationSubmittedAt = null;

try {
    $pdo = db();

    $checkStmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = :email OR national_id = :national_id OR phone = :phone LIMIT 1'
    );
    $checkStmt->execute([
        'email' => $email,
        'national_id' => $nationalId,
        'phone' => $phone,
    ]);

    if ($checkStmt->fetch()) {
        respond([
            'success' => false,
            'message' => 'Ya existe una cuenta con ese correo, telefono o identificacion.',
        ], 409);
    }

    if ($role === 'courier') {
        if (!isset($_FILES['document_front'], $_FILES['selfie_photo'])) {
            respond([
                'success' => false,
                'message' => 'El registro de domiciliario exige documento frontal y selfie.',
            ], 422);
        }

        $courierDocumentPath = uploaded_file_relative_path(
            $_FILES['document_front'],
            'uploads/courier_verification',
            'document_front'
        );
        $courierSelfiePath = uploaded_file_relative_path(
            $_FILES['selfie_photo'],
            'uploads/courier_verification',
            'selfie'
        );
        $courierVerificationSubmittedAt = date('Y-m-d H:i:s');
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO users (
            role,
            approval_status,
            full_name,
            national_id,
            phone,
            email,
            password_hash,
            city,
            courier_document_type,
            courier_document_path,
            courier_selfie_path,
            courier_verification_submitted_at
        ) VALUES (
            :role,
            :approval_status,
            :full_name,
            :national_id,
            :phone,
            :email,
            :password_hash,
            :city,
            :courier_document_type,
            :courier_document_path,
            :courier_selfie_path,
            :courier_verification_submitted_at
        )'
    );

    $insertStmt->execute([
        'role' => $role,
        'approval_status' => $approvalStatus,
        'full_name' => $fullName,
        'national_id' => $nationalId,
        'phone' => $phone,
        'email' => $email,
        'password_hash' => $passwordHash,
        'city' => 'Pitalito, Huila',
        'courier_document_type' => $role === 'courier' ? $documentType : null,
        'courier_document_path' => $courierDocumentPath,
        'courier_selfie_path' => $courierSelfiePath,
        'courier_verification_submitted_at' => $courierVerificationSubmittedAt,
    ]);

    $userId = (int) $pdo->lastInsertId();
    $token = issue_token($pdo, $userId);

    $userStmt = $pdo->prepare(
        'SELECT id, role, approval_status, full_name, national_id, phone, email, city
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();
    if ($user) {
        ensure_wallet_account($pdo, $user);
        $user = hydrate_user_with_wallet($pdo, $user);
    }

    respond([
        'success' => true,
        'message' => 'Cuenta creada.',
        'user' => user_payload($user ?: [], $token),
        'wallet_policy' => wallet_policy_payload(),
    ], 201);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible crear la cuenta.',
        'error' => $exception->getMessage(),
    ], 500);
}
