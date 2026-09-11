<?php

declare(strict_types=1);

const URBAN_MINIMUM_DELIVERY_FEE = 5000;
const SAME_PLACE_MULTI_PRODUCT_FEE = 7000;
const EXTRA_PURCHASE_PLACE_FEE = 2000;
const SHIPPING_BASE_WEIGHT_KG = 2.0;
const SHIPPING_EXTRA_KILO_FEE = 2000;

function calculate_delivery_fee(int $productCount, int $purchasePlacesCount): int
{
    $normalizedProducts = max(1, $productCount);
    $normalizedPlaces = max(1, $purchasePlacesCount);

    $baseFee = $normalizedProducts <= 1
        ? URBAN_MINIMUM_DELIVERY_FEE
        : SAME_PLACE_MULTI_PRODUCT_FEE;

    return $baseFee + (($normalizedPlaces - 1) * EXTRA_PURCHASE_PLACE_FEE);
}

function calculate_shipping_fee(?float $packageWeightKg): int
{
    $normalizedWeight = max(0.0, (float) ($packageWeightKg ?? 0.0));
    if ($normalizedWeight <= SHIPPING_BASE_WEIGHT_KG) {
        return URBAN_MINIMUM_DELIVERY_FEE;
    }

    $additionalKilos = (int) ceil($normalizedWeight - SHIPPING_BASE_WEIGHT_KG);
    return URBAN_MINIMUM_DELIVERY_FEE + ($additionalKilos * SHIPPING_EXTRA_KILO_FEE);
}
