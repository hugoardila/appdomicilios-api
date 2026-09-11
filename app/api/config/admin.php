<?php

declare(strict_types=1);

require_once __DIR__ . '/wallet.php';

$adminLocalPath = __DIR__ . '/admin.local.php';
if (is_file($adminLocalPath)) {
    require_once $adminLocalPath;
}

defined('COURIER_REVIEW_ACCESS_KEY') || define('COURIER_REVIEW_ACCESS_KEY', '');

function require_review_access_key(string $providedKey): void
{
    if (COURIER_REVIEW_ACCESS_KEY === '' || !hash_equals(COURIER_REVIEW_ACCESS_KEY, trim($providedKey))) {
        respond([
            'success' => false,
            'message' => 'Clave de revision invalida.',
        ], 403);
    }
}

function review_file_url(?string $relativePath): ?string
{
    if ($relativePath === null || trim($relativePath) === '') {
        return null;
    }

    $scheme = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = (string) $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = (string) $_SERVER['REQUEST_SCHEME'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scriptDirectory = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    if ($host === '') {
        return '/' . trim($scriptDirectory . '/' . $relativePath, '/');
    }

    $prefix = $scriptDirectory === '' ? '' : '/' . $scriptDirectory;
    return $scheme . '://' . $host . $prefix . '/' . $relativePath;
}

function courier_document_label(?string $documentType): string
{
    return match ($documentType) {
        'driver_license_front' => 'Licencia (frente)',
        default => 'Cedula (frente)',
    };
}
