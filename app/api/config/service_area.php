<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DEFAULT_SERVICE_AREA_NAME = 'Pitalito urbano';
const DEFAULT_SERVICE_AREA_CITY = 'Pitalito, Huila';
const DEFAULT_SERVICE_AREA_CENTER_LATITUDE = 1.8537;
const DEFAULT_SERVICE_AREA_CENTER_LONGITUDE = -76.0507;
const DEFAULT_SERVICE_AREA_RADIUS_METERS = 10000;

function default_service_area(): array
{
    return [
        'name' => DEFAULT_SERVICE_AREA_NAME,
        'city_label' => DEFAULT_SERVICE_AREA_CITY,
        'center_latitude' => DEFAULT_SERVICE_AREA_CENTER_LATITUDE,
        'center_longitude' => DEFAULT_SERVICE_AREA_CENTER_LONGITUDE,
        'radius_meters' => DEFAULT_SERVICE_AREA_RADIUS_METERS,
    ];
}

function service_zone_table_exists(PDO $pdo): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $exists = (bool) $pdo
            ->query("SHOW TABLES LIKE 'service_zones'")
            ->fetchColumn();
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

function active_service_area(PDO $pdo): array
{
    if (!service_zone_table_exists($pdo)) {
        return default_service_area();
    }

    $stmt = $pdo->query(
        'SELECT
            name,
            city_label,
            center_latitude,
            center_longitude,
            radius_meters
         FROM service_zones
         WHERE is_active = 1
         ORDER BY id ASC
         LIMIT 1'
    );
    $zone = $stmt->fetch();

    if (!$zone) {
        return default_service_area();
    }

    return [
        'name' => (string) $zone['name'],
        'city_label' => (string) $zone['city_label'],
        'center_latitude' => (float) $zone['center_latitude'],
        'center_longitude' => (float) $zone['center_longitude'],
        'radius_meters' => (int) $zone['radius_meters'],
    ];
}

function service_area_distance_meters(
    float $latitudeOne,
    float $longitudeOne,
    float $latitudeTwo,
    float $longitudeTwo,
): float {
    $earthRadiusMeters = 6371000.0;
    $latitudeDistance = deg2rad($latitudeTwo - $latitudeOne);
    $longitudeDistance = deg2rad($longitudeTwo - $longitudeOne);
    $startLatitude = deg2rad($latitudeOne);
    $endLatitude = deg2rad($latitudeTwo);

    $a = sin($latitudeDistance / 2) ** 2 +
        cos($startLatitude) * cos($endLatitude) *
        sin($longitudeDistance / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadiusMeters * $c;
}

function assert_coordinates_in_active_service_area(
    PDO $pdo,
    ?float $latitude,
    ?float $longitude,
): array {
    if ($latitude === null || $longitude === null) {
        throw new DomainException(
            'Para crear pedidos en XpertGoPitalito debes confirmar tu ubicacion actual dentro de Pitalito.'
        );
    }

    $zone = active_service_area($pdo);
    $distance = service_area_distance_meters(
        $latitude,
        $longitude,
        $zone['center_latitude'],
        $zone['center_longitude'],
    );

    if ($distance > (float) $zone['radius_meters']) {
        throw new DomainException(
            sprintf(
                'Por ahora XpertGoPitalito solo opera dentro de %s. Tu punto actual aparece fuera del area de cobertura.',
                $zone['city_label']
            )
        );
    }

    $zone['distance_meters'] = (int) round($distance);

    return $zone;
}
