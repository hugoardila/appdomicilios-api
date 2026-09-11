<?php

declare(strict_types=1);

require_once __DIR__ . '/config/service_area.php';

require_method('GET');

try {
    $pdo = db();
    $area = active_service_area($pdo);

    $latitude = isset($_GET['latitude']) ? (float) $_GET['latitude'] : null;
    $longitude = isset($_GET['longitude']) ? (float) $_GET['longitude'] : null;

    if ($latitude !== null && $longitude !== null) {
        $area = assert_coordinates_in_active_service_area($pdo, $latitude, $longitude);
        $area['inside'] = true;
    }

    respond([
        'success' => true,
        'message' => 'Area de cobertura consultada.',
        'service_area' => $area,
    ]);
} catch (DomainException $exception) {
    respond([
        'success' => false,
        'message' => $exception->getMessage(),
        'service_area' => active_service_area(db()),
    ], 422);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'No fue posible consultar el area de cobertura.',
        'error' => $exception->getMessage(),
    ], 500);
}

