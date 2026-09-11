<?php

declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

$paymentsLocalPath = __DIR__ . '/payments.local.php';
if (is_file($paymentsLocalPath)) {
    require_once $paymentsLocalPath;
}

defined('EPAYCO_TEST_MODE') || define('EPAYCO_TEST_MODE', filter_var(getenv('EPAYCO_TEST_MODE') ?: 'true', FILTER_VALIDATE_BOOLEAN));
defined('EPAYCO_RESPONSE_BASE_URL') || define('EPAYCO_RESPONSE_BASE_URL', getenv('EPAYCO_RESPONSE_BASE_URL') ?: '');
defined('EPAYCO_PUBLIC_CONFIRMATION_BASE_URL') || define('EPAYCO_PUBLIC_CONFIRMATION_BASE_URL', getenv('EPAYCO_PUBLIC_CONFIRMATION_BASE_URL') ?: '');
defined('EPAYCO_CHECKOUT_PAGE_BASE_URL') || define('EPAYCO_CHECKOUT_PAGE_BASE_URL', getenv('EPAYCO_CHECKOUT_PAGE_BASE_URL') ?: '');

defined('EPAYCO_P_CUST_ID_CLIENTE') || define('EPAYCO_P_CUST_ID_CLIENTE', getenv('EPAYCO_P_CUST_ID_CLIENTE') ?: '');
defined('EPAYCO_P_KEY') || define('EPAYCO_P_KEY', getenv('EPAYCO_P_KEY') ?: '');
defined('EPAYCO_PUBLIC_KEY') || define('EPAYCO_PUBLIC_KEY', getenv('EPAYCO_PUBLIC_KEY') ?: '');
defined('EPAYCO_PRIVATE_KEY') || define('EPAYCO_PRIVATE_KEY', getenv('EPAYCO_PRIVATE_KEY') ?: '');

function payments_are_configured(): bool
{
    return EPAYCO_P_CUST_ID_CLIENTE !== '' &&
        EPAYCO_P_KEY !== '' &&
        EPAYCO_PUBLIC_KEY !== '' &&
        EPAYCO_PRIVATE_KEY !== '';
}

function epayco_response_url(string $referenceCode): string
{
    return rtrim(EPAYCO_RESPONSE_BASE_URL, '/') . '/epayco_response.php?reference=' . urlencode($referenceCode);
}

function epayco_confirmation_url(): ?string
{
    $base = trim(EPAYCO_PUBLIC_CONFIRMATION_BASE_URL);
    if ($base === '') {
        return null;
    }

    return rtrim($base, '/') . '/epayco_confirmation.php';
}

function epayco_checkout_page_url(string $referenceCode, string $sessionId): string
{
    return rtrim(EPAYCO_CHECKOUT_PAGE_BASE_URL, '/') .
        '/wallet_checkout.php?reference=' . urlencode($referenceCode) .
        '&session_id=' . urlencode($sessionId);
}

function create_wallet_topup(PDO $pdo, int $userId, int $amount): array
{
    $referenceCode = 'TOPUP-' . strtoupper(bin2hex(random_bytes(4)));
    $invoiceCode = 'TOPUP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $stmt = $pdo->prepare(
        'INSERT INTO wallet_topups (
            user_id,
            reference_code,
            invoice_code,
            amount,
            status
         ) VALUES (
            :user_id,
            :reference_code,
            :invoice_code,
            :amount,
            :status
         )'
    );
    $stmt->execute([
        'user_id' => $userId,
        'reference_code' => $referenceCode,
        'invoice_code' => $invoiceCode,
        'amount' => $amount,
        'status' => 'pending',
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'user_id' => $userId,
        'reference_code' => $referenceCode,
        'invoice_code' => $invoiceCode,
        'amount' => $amount,
        'status' => 'pending',
    ];
}

function epayco_auth_token(): string
{
    $credentials = base64_encode(EPAYCO_PUBLIC_KEY . ':' . EPAYCO_PRIVATE_KEY);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Basic ' . $credentials,
    ];

    $response = curl_json_request(
        'https://apify.epayco.co/login',
        'POST',
        [],
        $headers
    );

    $token = (string) ($response['token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('ePayco no devolvio token de autenticacion.');
    }

    return $token;
}

function epayco_create_session(string $token, array $payload): array
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    $response = curl_json_request(
        'https://apify.epayco.co/payment/session/create',
        'POST',
        $payload,
        $headers
    );

    if (empty($response['success']) || empty($response['data']['sessionId'])) {
        throw new RuntimeException(
            (string) ($response['textResponse'] ?? 'ePayco no devolvio una sesion valida.')
        );
    }

    return $response;
}

function curl_json_request(string $url, string $method, array $payload, array $headers): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('No fue posible iniciar la conexion HTTP.');
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($method !== 'GET') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($handle);
    if ($body === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Error de red con ePayco: ' . $error);
    }

    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('ePayco devolvio una respuesta no valida.');
    }

    if ($statusCode >= 400) {
        throw new RuntimeException((string) ($decoded['textResponse'] ?? 'Error al comunicar con ePayco.'));
    }

    return $decoded;
}

function epayco_signature_is_valid(array $payload): bool
{
    if (!payments_are_configured()) {
        return false;
    }

    $signature = hash(
        'sha256',
        EPAYCO_P_CUST_ID_CLIENTE . '^' .
        EPAYCO_P_KEY . '^' .
        (string) ($payload['x_ref_payco'] ?? '') . '^' .
        (string) ($payload['x_transaction_id'] ?? '') . '^' .
        (string) ($payload['x_amount'] ?? '') . '^' .
        (string) ($payload['x_currency_code'] ?? '')
    );

    return hash_equals(strtolower($signature), strtolower((string) ($payload['x_signature'] ?? '')));
}
