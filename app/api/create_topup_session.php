<?php

declare(strict_types=1);

require_once __DIR__ . '/config/payments.php';

require_method('POST');

$input = json_input();
$userId = (int) ($input['user_id'] ?? 0);
$amount = (int) ($input['amount'] ?? 0);

if ($userId <= 0 || $amount <= 0) {
    respond([
        'success' => false,
        'message' => 'Usuario y monto son obligatorios.',
    ], 422);
}

if ($amount < WALLET_MINIMUM_TOP_UP) {
    respond([
        'success' => false,
        'message' => 'La recarga minima es de ' . WALLET_MINIMUM_TOP_UP . ' COP.',
    ], 422);
}

if (!payments_are_configured()) {
    respond([
        'success' => false,
        'message' => 'La integracion de pagos aun no esta configurada en el backend.',
    ], 503);
}

try {
    $pdo = db();
    $user = fetch_user_row($pdo, $userId);

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        respond([
            'success' => false,
            'message' => 'Usuario no disponible para recargar.',
        ], 404);
    }

    $topup = create_wallet_topup($pdo, $userId, $amount);
    $token = epayco_auth_token();

    $sessionPayload = [
        'checkout_version' => '2',
        'name' => 'AppDomicilios Pitalito',
        'currency' => 'COP',
        'amount' => $amount,
        'description' => 'Recarga de saldo AppDomicilios',
        'lang' => 'ES',
        'invoice' => $topup['invoice_code'],
        'country' => 'CO',
        'taxBase' => 0,
        'tax' => 0,
        'response' => epayco_response_url($topup['reference_code']),
        'method' => 'POST',
        'extras' => [
            'extra1' => $topup['reference_code'],
            'extra2' => (string) $userId,
            'extra3' => (string) $amount,
        ],
        'billing' => [
            'email' => (string) $user['email'],
            'name' => (string) $user['full_name'],
            'address' => 'Pitalito, Huila',
            'typeDoc' => 'CC',
            'numberDoc' => (string) $user['national_id'],
            'callingCode' => '+57',
            'mobilePhone' => (string) $user['phone'],
        ],
    ];

    $confirmationUrl = epayco_confirmation_url();
    if ($confirmationUrl !== null) {
        $sessionPayload['confirmation'] = $confirmationUrl;
    }

    $sessionResponse = epayco_create_session($token, $sessionPayload);
    $sessionId = (string) ($sessionResponse['data']['sessionId'] ?? '');

    $updateStmt = $pdo->prepare(
        'UPDATE wallet_topups
         SET response_payload = :response_payload
         WHERE id = :id'
    );
    $updateStmt->execute([
        'response_payload' => json_encode($sessionResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $topup['id'],
    ]);

    respond([
        'success' => true,
        'message' => 'Sesion de recarga creada.',
        'topup_reference' => $topup['reference_code'],
        'session_id' => $sessionId,
        'checkout_page_url' => epayco_checkout_page_url($topup['reference_code'], $sessionId),
        'confirmation_ready' => $confirmationUrl !== null,
    ]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible iniciar la recarga.',
        'error' => $exception->getMessage(),
    ], 500);
}
