<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function request_input(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    return json_input();
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        respond([
            'success' => false,
            'message' => 'Metodo no permitido.',
        ], 405);
    }
}

function user_payload(array $user, ?string $token = null): array
{
    $payload = [
        'id' => (string) $user['id'],
        'role' => $user['role'],
        'approval_status' => $user['approval_status'],
        'full_name' => $user['full_name'],
        'national_id' => $user['national_id'],
        'phone' => $user['phone'],
        'email' => $user['email'],
        'city' => $user['city'],
    ];

    if (($user['role'] ?? '') === 'courier') {
        $payload['courier_rating'] = $user['courier_rating'] ?? [
            'average' => 0.0,
            'count' => 0,
            'warning_active' => false,
        ];
    }

    if (($user['role'] ?? '') === 'customer') {
        $payload['customer_reputation'] = $user['customer_reputation'] ?? [
            'score' => 5.0,
            'incidents_count' => 0,
            'warning_active' => false,
        ];
    }

    if (isset($user['wallet']) && is_array($user['wallet'])) {
        $payload['wallet'] = $user['wallet'];
    }

    if ($token !== null) {
        $payload['token'] = $token;
    }

    return $payload;
}

function issue_token(PDO $pdo, int $userId): string
{
    $token = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 30 DAY))'
    );
    $stmt->execute([
        'user_id' => $userId,
        'token' => $token,
    ]);

    return $token;
}

function ensure_upload_directory(string $relativeDirectory): string
{
    $baseDirectory = dirname(__DIR__);
    $targetDirectory = $baseDirectory . DIRECTORY_SEPARATOR . trim($relativeDirectory, DIRECTORY_SEPARATOR);

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('No fue posible preparar la carpeta de archivos.');
    }

    return $targetDirectory;
}

function uploaded_file_relative_path(array $file, string $relativeDirectory, string $prefix): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No fue posible subir una de las imagenes del registro.');
    }

    $mimeType = mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'image/jpeg');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mimeType])) {
        throw new RuntimeException('Solo se permiten imagenes JPG, PNG o WEBP.');
    }

    $directory = ensure_upload_directory($relativeDirectory);
    $fileName = sprintf(
        '%s_%s.%s',
        $prefix,
        bin2hex(random_bytes(8)),
        $allowed[$mimeType]
    );

    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('No fue posible guardar una de las imagenes del registro.');
    }

    return trim($relativeDirectory, '/\\') . '/' . $fileName;
}
