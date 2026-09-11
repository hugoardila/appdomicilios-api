<?php

declare(strict_types=1);

require_once __DIR__ . '/config/orders.php';
require_once __DIR__ . '/config/delivery_pricing.php';
require_once __DIR__ . '/config/push_notifications.php';
require_once __DIR__ . '/config/service_area.php';
require_once __DIR__ . '/config/wallet.php';

require_method('POST');

$input = json_input();
$customerId = (int) ($input['customer_id'] ?? 0);
$serviceCategory = trim((string) ($input['service_category'] ?? 'shopping'));
$serviceSubcategory = trim((string) ($input['service_subcategory'] ?? ''));
$requestType = trim((string) ($input['request_type'] ?? 'shopping'));
$description = trim((string) ($input['description'] ?? ''));
$details = trim((string) ($input['details'] ?? ''));
$rawProducts = is_array($input['products'] ?? null) ? $input['products'] : [];
$rawItems = is_array($input['items'] ?? null) ? $input['items'] : [];
$addressText = trim((string) ($input['address_text'] ?? ''));
$addressReference = trim((string) ($input['address_reference'] ?? ''));
$shareLocation = !empty($input['share_location']) ? 1 : 0;
$latitude = isset($input['latitude']) && $input['latitude'] !== null ? (float) $input['latitude'] : null;
$longitude = isset($input['longitude']) && $input['longitude'] !== null ? (float) $input['longitude'] : null;
$destinationLatitude = isset($input['destination_latitude']) && $input['destination_latitude'] !== null
    ? (float) $input['destination_latitude']
    : null;
$destinationLongitude = isset($input['destination_longitude']) && $input['destination_longitude'] !== null
    ? (float) $input['destination_longitude']
    : null;
$destinationReference = trim((string) ($input['destination_reference'] ?? ''));
$paymentMethod = trim((string) ($input['payment_method'] ?? 'cash'));
$pickupAddress = trim((string) ($input['pickup_address'] ?? ''));
$pickupContactName = trim((string) ($input['pickup_contact_name'] ?? ''));
$pickupContactPhone = trim((string) ($input['pickup_contact_phone'] ?? ''));
$dropoffContactName = trim((string) ($input['dropoff_contact_name'] ?? ''));
$dropoffContactPhone = trim((string) ($input['dropoff_contact_phone'] ?? ''));
$pickupPaymentAmount = max(0, (int) ($input['pickup_payment_amount'] ?? 0));
$packageWeightKg = isset($input['package_weight_kg']) && $input['package_weight_kg'] !== null
    ? max(0.0, (float) $input['package_weight_kg'])
    : null;

if (!in_array($requestType, ['shopping', 'pickup_delivery', 'other'], true)) {
    $requestType = 'shopping';
}

if (!in_array($serviceCategory, ['shopping', 'restaurantes', 'domicilios', 'tramites', 'mototaxi', 'envios'], true)) {
    $serviceCategory = match ($requestType) {
        'pickup_delivery' => 'domicilios',
        'other' => 'tramites',
        default => 'shopping',
    };
}

if ($customerId <= 0 || $addressText === '') {
    respond([
        'success' => false,
        'message' => 'Cliente y direccion son obligatorios.',
    ], 422);
}

if (!in_array($paymentMethod, ['cash', 'transfer', 'digital'], true)) {
    $paymentMethod = 'cash';
}

$items = [];
foreach ($rawItems as $rawItem) {
    if (is_array($rawItem)) {
        $itemName = trim((string) ($rawItem['name'] ?? ''));
        $itemQuantity = max(1, (int) ($rawItem['quantity'] ?? 1));
    } else {
        $itemName = trim((string) $rawItem);
        $itemQuantity = 1;
    }

    if ($itemName === '') {
        continue;
    }

    $items[] = [
        'name' => $itemName,
        'quantity' => $itemQuantity,
    ];
}

if ($items === [] && $rawProducts !== []) {
    $items = array_values(array_filter(array_map(
        static function ($product): ?array {
            $cleaned = trim((string) $product);
            if ($cleaned === '') {
                return null;
            }

            return [
                'name' => $cleaned,
                'quantity' => 1,
            ];
        },
        $rawProducts
    )));
}

if ($items === [] && $requestType === 'shopping') {
    $descriptionLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $description) ?: [])));
    foreach ($descriptionLines as $line) {
        $items[] = [
            'name' => $line,
            'quantity' => 1,
        ];
    }
}

$requiresCatalogItems = in_array($serviceCategory, ['shopping', 'restaurantes', 'tramites'], true);
if ($requiresCatalogItems && $serviceSubcategory === '') {
    respond([
        'success' => false,
        'message' => 'Selecciona una subcategoria para continuar.',
    ], 422);
}

if ($requiresCatalogItems && $items === []) {
    respond([
        'success' => false,
        'message' => $serviceCategory === 'shopping'
            ? 'Agrega al menos un producto al pedido.'
            : ($serviceCategory === 'restaurantes'
                ? 'Agrega al menos un plato o bebida al pedido.'
                : 'Agrega al menos un tramite o gestion a la solicitud.'),
    ], 422);
}

if (
    in_array($serviceCategory, ['domicilios', 'envios'], true) &&
    (
        $pickupAddress === '' ||
        $pickupContactName === '' ||
        $pickupContactPhone === '' ||
        $dropoffContactName === '' ||
        $dropoffContactPhone === ''
    )
) {
    respond([
        'success' => false,
        'message' => 'Completa los datos de recogida y entrega para esta solicitud.',
    ], 422);
}

if ($serviceCategory === 'envios') {
    if ($serviceSubcategory === '') {
        respond([
            'success' => false,
            'message' => 'Selecciona el tipo de envio.',
        ], 422);
    }

    if ($packageWeightKg === null || $packageWeightKg <= 0) {
        respond([
            'success' => false,
            'message' => 'Indica el peso aproximado del envio.',
        ], 422);
    }
}

if ($serviceCategory === 'mototaxi') {
    if ($description === '' && $details === '') {
        $details = 'Servicio de mototaxi';
    }

    if ($destinationLatitude === null || $destinationLongitude === null) {
        respond([
            'success' => false,
            'message' => 'Confirma el destino del mototaxi en el mapa antes de enviar.',
        ], 422);
    }
}

if ($serviceCategory === 'tramites' && $details === '') {
    $details = 'Tramite solicitado por el cliente.';
}

$totalItemUnits = array_reduce(
    $items,
    static fn (int $carry, array $item): int => $carry + max(1, (int) ($item['quantity'] ?? 1)),
    0
);

$itemLines = array_map(
    static fn (array $item): string => sprintf('%d x %s', (int) $item['quantity'], (string) $item['name']),
    $items
);

$originalRequestText = match ($serviceCategory) {
    'shopping' => implode("\n", array_filter(array_merge(
        [$serviceSubcategory !== '' ? 'Subcategoria: ' . $serviceSubcategory : ''],
        $itemLines,
        [$details !== '' ? 'Notas: ' . $details : '']
    ))),
    'restaurantes' => implode("\n", array_filter(array_merge(
        [$serviceSubcategory !== '' ? 'Restaurante: ' . $serviceSubcategory : ''],
        $itemLines,
        [$details !== '' ? 'Notas: ' . $details : '']
    ))),
    'tramites' => implode("\n", array_filter(array_merge(
        [$serviceSubcategory !== '' ? 'Subcategoria: ' . $serviceSubcategory : ''],
        $itemLines,
        [$details !== '' ? 'Observaciones: ' . $details : '']
    ))),
    'envios' => implode("\n", array_filter([
        $serviceSubcategory !== '' ? 'Tipo de envio: ' . $serviceSubcategory : '',
        $packageWeightKg !== null ? 'Peso aproximado: ' . number_format($packageWeightKg, 2, '.', '') . ' kg' : '',
        'Recoger en: ' . $pickupAddress,
        'Entrega a: ' . $dropoffContactName,
        'Telefono entrega: ' . $dropoffContactPhone,
        $pickupPaymentAmount > 0 ? 'Valor a pagar al recoger: $' . number_format($pickupPaymentAmount, 0, ',', '.') : '',
        $details !== '' ? 'Notas: ' . $details : '',
    ])),
    'mototaxi' => implode("\n", array_filter([
        'Origen: ' . ($addressReference !== '' ? $addressReference : $addressText),
        'Destino: ' . $addressText,
        $destinationReference !== '' ? 'Referencia destino: ' . $destinationReference : '',
        $details !== '' ? 'Notas: ' . $details : '',
    ])),
    default => implode("\n", array_filter([
        'Recoger en: ' . $pickupAddress,
        'Entrega a: ' . $dropoffContactName,
        'Telefono entrega: ' . $dropoffContactPhone,
        $pickupPaymentAmount > 0 ? 'Valor a pagar al recoger: $' . number_format($pickupPaymentAmount, 0, ',', '.') : '',
        $details !== '' ? 'Notas: ' . $details : '',
    ])),
};

$deliveryFee = match ($serviceCategory) {
    'shopping' => calculate_delivery_fee(max(1, $totalItemUnits), 1),
    'restaurantes' => calculate_delivery_fee(max(1, $totalItemUnits), 1),
    'envios' => calculate_shipping_fee($packageWeightKg),
    default => URBAN_MINIMUM_DELIVERY_FEE,
};

try {
    $pdo = db();
    $pdo->beginTransaction();

    $customer = fetch_user_row($pdo, $customerId, true);
    if (!$customer || $customer['role'] !== 'customer' || (int) $customer['is_active'] !== 1) {
        $pdo->rollBack();
        respond([
            'success' => false,
            'message' => 'El cliente no esta disponible para crear pedidos.',
        ], 403);
    }

    $coverageZone = assert_coordinates_in_active_service_area($pdo, $latitude, $longitude);

    if ($serviceCategory === 'mototaxi') {
        assert_coordinates_in_active_service_area($pdo, $destinationLatitude, $destinationLongitude);
        $shareLocation = 1;
    }

    $storedAddressReference = $shareLocation === 1 && $addressReference !== '' ? $addressReference : null;
    $storedLatitude = $shareLocation === 1 ? $latitude : null;
    $storedLongitude = $shareLocation === 1 ? $longitude : null;
    $storedDestinationReference = $destinationReference !== '' ? $destinationReference : null;

    $stmt = $pdo->prepare(
        "INSERT INTO orders (
            customer_id,
            service_category,
            service_subcategory,
            request_type,
            status,
            address_text,
            address_reference,
            latitude,
            longitude,
            destination_latitude,
            destination_longitude,
            destination_reference,
            share_location,
            pickup_address,
            pickup_contact_name,
            pickup_contact_phone,
            dropoff_contact_name,
            dropoff_contact_phone,
            pickup_payment_amount,
            package_weight_kg,
            city,
            payment_method,
            service_fee,
            delivery_fee,
            purchase_places_count,
            original_request_text
         ) VALUES (
            :customer_id,
            :service_category,
            :service_subcategory,
            :request_type,
            'waiting',
            :address_text,
            :address_reference,
            :latitude,
            :longitude,
            :destination_latitude,
            :destination_longitude,
            :destination_reference,
            :share_location,
            :pickup_address,
            :pickup_contact_name,
            :pickup_contact_phone,
            :dropoff_contact_name,
            :dropoff_contact_phone,
            :pickup_payment_amount,
            :package_weight_kg,
            :city,
            :payment_method,
            0,
            :delivery_fee,
            1,
            :original_request_text
         )"
    );
    $stmt->execute([
        'customer_id' => $customerId,
        'service_category' => $serviceCategory,
        'service_subcategory' => $serviceSubcategory !== '' ? $serviceSubcategory : null,
        'request_type' => $requestType,
        'address_text' => $addressText,
        'address_reference' => $storedAddressReference,
        'latitude' => $storedLatitude,
        'longitude' => $storedLongitude,
        'destination_latitude' => $destinationLatitude,
        'destination_longitude' => $destinationLongitude,
        'destination_reference' => $storedDestinationReference,
        'share_location' => $shareLocation,
        'pickup_address' => $pickupAddress !== '' ? $pickupAddress : null,
        'pickup_contact_name' => $pickupContactName !== '' ? $pickupContactName : null,
        'pickup_contact_phone' => $pickupContactPhone !== '' ? $pickupContactPhone : null,
        'dropoff_contact_name' => $dropoffContactName !== '' ? $dropoffContactName : null,
        'dropoff_contact_phone' => $dropoffContactPhone !== '' ? $dropoffContactPhone : null,
        'pickup_payment_amount' => $pickupPaymentAmount,
        'package_weight_kg' => $packageWeightKg,
        'city' => $coverageZone['city_label'],
        'payment_method' => $paymentMethod,
        'delivery_fee' => $deliveryFee,
        'original_request_text' => $originalRequestText,
    ]);

    $orderId = (int) $pdo->lastInsertId();
    apply_customer_charge_for_order($pdo, $orderId, $customer);

    if (in_array($serviceCategory, ['shopping', 'restaurantes', 'tramites'], true)) {
        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items (order_id, name, quantity, status)
             VALUES (:order_id, :name, :quantity, 'pending')"
        );

        foreach ($items as $item) {
            $itemStmt->execute([
                'order_id' => $orderId,
                'name' => $item['name'],
                'quantity' => max(1, (int) $item['quantity']),
            ]);
        }
    }

    system_order_message($pdo, $orderId, 'Pedido creado y esperando un domiciliario.');
    $pdo->commit();

    try {
        notify_new_pending_order(
            $pdo,
            orderId: $orderId,
            customerName: (string) ($customer['full_name'] ?? ''),
            deliveryFee: $deliveryFee,
        );
    } catch (Throwable $pushException) {
        error_log('Push nueva orden: ' . $pushException->getMessage());
    }

    respond([
        'success' => true,
        'message' => 'Pedido creado.',
        'order_id' => (string) $orderId,
    ], 201);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => $exception->getMessage(),
    ], 409);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => 'No fue posible crear el pedido.',
        'error' => $exception->getMessage(),
    ], 500);
}
