<?php

declare(strict_types=1);

$firebaseLocalPath = __DIR__ . '/firebase.local.php';
if (is_file($firebaseLocalPath)) {
    require_once $firebaseLocalPath;
}

defined('FCM_PROJECT_ID') || define('FCM_PROJECT_ID', '');
defined('FCM_SERVICE_ACCOUNT_FILE') || define('FCM_SERVICE_ACCOUNT_FILE', __DIR__ . '/firebase-service-account.local.json');
defined('FCM_OAUTH_TOKEN_URL') || define('FCM_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token');
defined('FCM_API_BASE_URL') || define('FCM_API_BASE_URL', 'https://fcm.googleapis.com');

function fcm_is_configured(): bool
{
    return FCM_PROJECT_ID !== '' && is_file(FCM_SERVICE_ACCOUNT_FILE);
}

function fcm_send_message(array $message): bool
{
    $accessToken = fcm_access_token();
    if ($accessToken === null) {
        return false;
    }

    $endpoint = sprintf(
        '%s/v1/projects/%s/messages:send',
        rtrim(FCM_API_BASE_URL, '/'),
        rawurlencode(FCM_PROJECT_ID)
    );

    $payload = ['message' => $message];
    $response = fcm_http_post_json($endpoint, $payload, [
        'Authorization: Bearer ' . $accessToken,
    ]);

    return $response['status_code'] >= 200 && $response['status_code'] < 300;
}

function fcm_access_token(): ?string
{
    static $cachedToken = null;
    static $cachedExpiresAt = 0;

    if (!fcm_is_configured()) {
        return null;
    }

    if ($cachedToken !== null && time() < ($cachedExpiresAt - 60)) {
        return $cachedToken;
    }

    $serviceAccount = fcm_load_service_account();
    if ($serviceAccount === null) {
        return null;
    }

    $tokenUri = (string) ($serviceAccount['token_uri'] ?? FCM_OAUTH_TOKEN_URL);
    $issuedAt = time();
    $expiresAt = $issuedAt + 3600;

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => (string) $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUri,
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $encodedHeader = fcm_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedClaims = fcm_base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $encodedHeader . '.' . $encodedClaims;

    $signature = '';
    $signed = openssl_sign(
        $signingInput,
        $signature,
        (string) $serviceAccount['private_key'],
        OPENSSL_ALGO_SHA256
    );

    if (!$signed) {
        return null;
    }

    $jwt = $signingInput . '.' . fcm_base64url_encode($signature);
    $response = fcm_http_post_form($tokenUri, [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        return null;
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        return null;
    }

    $cachedToken = (string) $decoded['access_token'];
    $cachedExpiresAt = $issuedAt + (int) ($decoded['expires_in'] ?? 3600);

    return $cachedToken;
}

function fcm_load_service_account(): ?array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    if (!fcm_is_configured()) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents(FCM_SERVICE_ACCOUNT_FILE), true);
    if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
        return null;
    }

    $cached = $decoded;
    return $cached;
}

function fcm_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function fcm_http_post_json(string $url, array $payload, array $headers = []): array
{
    return fcm_http_request(
        url: $url,
        method: 'POST',
        body: json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        headers: array_merge(
            [
                'Content-Type: application/json; charset=utf-8',
            ],
            $headers
        ),
    );
}

function fcm_http_post_form(string $url, array $payload, array $headers = []): array
{
    return fcm_http_request(
        url: $url,
        method: 'POST',
        body: http_build_query($payload),
        headers: array_merge(
            [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            $headers
        ),
    );
}

function fcm_http_request(
    string $url,
    string $method,
    string $body,
    array $headers = [],
): array {
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12,
        ]);

        $rawBody = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [
            'status_code' => $statusCode,
            'body' => is_string($rawBody) ? $rawBody : '',
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 12,
        ],
    ]);

    $rawBody = @file_get_contents($url, false, $context);
    $statusCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        $statusCode = (int) $matches[1];
    }

    return [
        'status_code' => $statusCode,
        'body' => is_string($rawBody) ? $rawBody : '',
    ];
}
