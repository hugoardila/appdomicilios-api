<?php

declare(strict_types=1);

require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$email = trim((string) ($input['email'] ?? ''));
$password = trim((string) ($input['password'] ?? ''));

if ($email === '' || $password === '') {
    respond([
        'success' => false,
        'message' => 'Correo y contrasena son obligatorios.',
    ], 422);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT id, role, approval_status, full_name, national_id, phone, email, city, password_hash
         FROM users
         WHERE email = :email AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond([
            'success' => false,
            'message' => 'Credenciales invalidas.',
        ], 401);
    }

    $token = issue_token($pdo, (int) $user['id']);

    $user = hydrate_user_with_wallet($pdo, $user);

    respond([
        'success' => true,
        'message' => 'Sesion iniciada.',
        'user' => user_payload($user, $token),
        'wallet_policy' => wallet_policy_payload(),
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible iniciar sesion.',
        'error' => $exception->getMessage(),
    ], 500);
}
