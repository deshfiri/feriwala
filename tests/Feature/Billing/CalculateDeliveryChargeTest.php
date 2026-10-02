<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\SetDeliveryChargeSettings;
use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Catalog\Data\ProductLogistics;
use App\Domain\Courier\Models\CourierProvider;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * What a shipment's delivery costs, computed exactly from its lines' own
 * logistics figures (beta-critical batch, Commit 2). Every assertion here is
 * on an exact decimal string -- never a float comparison -- because that is
 * the one thing this calculator exists to guarantee.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function deliveryChargeTestLogistics(array $overrides = []): ProductLogistics
{
    return new ProductLogistics(
        netWeightGrams: $overrides['net_weight_grams'] ?? 500,
        shippingWeightGrams: $overrides['shipping_weight_grams'] ?? null,
        lengthCm: $overrides['length_cm'] ?? null,
        widthCm: $overrides['width_cm'] ?? null,
        heightCm: $overrides['height_cm'] ?? null,
        shipsByBox: $overrides['ships_by_box'] ?? false,
        piecesPerBox: $overrides['pieces_per_box'] ?? null,
        boxWeightGrams: $overrides['box_weight_grams'] ?? null,
        boxLengthCm: $overrides['box_length_cm'] ?? null,
        boxWidthCm: $overrides['box_width_cm'] ?? null,
        boxHeightCm: $overrides['box_height_cm'] ?? null,
        isFragile: $overrides['is_fragile'] ?? false,
    );
}

function deliveryChargeTestRule(array $overrides = []): DeliveryChargeRule
{
    return DeliveryChargeRule::create([
        'weight_from_grams' => $overrides['weight_from_grams'] ?? 0,
        'weight_to_grams' => $overrides['weight_to_grams'] ?? null,
        'currency_code' => 'BDT',
        'base_charge' => Money::fromDecimal($overrides['base_charge'] ?? '60.00', Currency::BDT),
        'per_kg_charge' => isset($overrides['per_kg_charge']) ? Money::fromDecimal($overrides['per_kg_charge'], Currency::BDT) : null,
        'area' => $overrides['area'] ?? null,
        'courier_provider_id' => $overrides['courier_provider_id'] ?? null,
        'priority' => $overrides['priority'] ?? 0,
        'effective_from' => $overrides['effective_from'] ?? CarbonImmutable::now()->subDay(),
        'effective_until' => $overrides['effective_until'] ?? null,
        'is_active' => $overrides['is_active'] ?? true,
    ]);
}

it('charges the general rule\'s base charge when weight carries no per-kg rate', function () {
    deliveryChargeTestRule(['base_charge' => '60.00']);

    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(['net_weight_grams' => 500]), 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($result->actualWeightGrams)->toBe(500)
        ->and($result->chargeableWeightGrams)->toBe(500)
        ->and($result->baseCharge->toDecimal())->toBe('60.00')
        ->and($result->finalCharge->toDecimal())->toBe('60.00');
});

it('calculates pieces per box with a partial final box counted whole', function () {
    deliveryChargeTestRule();

    $logistics = deliveryChargeTestLogistics([
        'ships_by_box' => true,
        'pieces_per_box' => 10,
        'box_weight_grams' => 5500,
    ]);

    // 25 units at 10 per box: two full boxes and one partial -- three boxes total.
    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => $logistics, 'quantity' => 25]],
        currency: Currency::BDT,
    );

    expect($result->boxes)->toBe(3)
        ->and($result->actualWeightGrams)->toBe(3 * 5500);
});

it('uses the greater of actual and volumetric weight when the setting says so', function () {
    app(SetDeliveryChargeSettings::class)->handle(
        testPlatformStaff(PlatformRole::Admin),
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::zero(Currency::BDT),
        fragileHandlingCharge: Money::zero(Currency::BDT),
        minimumCharge: Money::zero(Currency::BDT),
        maximumCharge: null,
        freeDeliveryThreshold: null,
    );
    deliveryChargeTestRule(['weight_to_grams' => null]);
    deliveryChargeTestRule(['weight_from_grams' => 0, 'weight_to_grams' => 100000, 'base_charge' => '999.00', 'priority' => 100]);

    // A light but bulky box: 40cm x 30cm x 30cm = 36000 cm3 / 5000 = 7.2kg -> 7200g volumetric,
    // far more than its 500g actual weight.
    $logistics = deliveryChargeTestLogistics([
        'net_weight_grams' => 500,
        'length_cm' => '40.00',
        'width_cm' => '30.00',
        'height_cm' => '30.00',
    ]);

    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => $logistics, 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($result->actualWeightGrams)->toBe(500)
        ->and($result->volumetricWeightGrams)->toBe(7200)
        ->and($result->usedVolumetricWeight)->toBeTrue()
        ->and($result->chargeableWeightGrams)->toBe(7200);
});

it('rounds a fractional volumetric weight up to the next whole gram', function () {
    app(SetDeliveryChargeSettings::class)->handle(
        testPlatformStaff(PlatformRole::Admin),
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::zero(Currency::BDT),
        fragileHandlingCharge: Money::zero(Currency::BDT),
        minimumCharge: Money::zero(Currency::BDT),
        maximumCharge: null,
        freeDeliveryThreshold: null,
    );
    deliveryChargeTestRule();

    // 10 x 10 x 10.01 = 1001 cm3 / 5000 = 0.2002kg = 200.2g -> rounds up to 201g.
    $logistics = deliveryChargeTestLogistics([
        'net_weight_grams' => 1,
        'length_cm' => '10.00',
        'width_cm' => '10.00',
        'height_cm' => '10.01',
    ]);

    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => $logistics, 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($result->volumetricWeightGrams)->toBe(201);
});

it('computes an exact per-kg charge on top of the base charge', function () {
    deliveryChargeTestRule(['base_charge' => '60.00', 'per_kg_charge' => '20.00']);

    // 2500g = 2.5kg x 20.00/kg = 50.00 exactly.
    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(['net_weight_grams' => 2500]), 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($result->weightCharge->toDecimal())->toBe('50.00')
        ->and($result->finalCharge->toDecimal())->toBe('110.00');
});

it('prefers a courier+area-specific rule over an area-only, courier-only or general rule', function () {
    $provider = CourierProvider::query()->where('code', 'manual')->firstOrFail();

    deliveryChargeTestRule(['base_charge' => '60.00']); // general
    deliveryChargeTestRule(['base_charge' => '80.00', 'area' => 'Dhaka Metro']); // area-only
    deliveryChargeTestRule(['base_charge' => '90.00', 'courier_provider_id' => $provider->id]); // courier-only
    deliveryChargeTestRule(['base_charge' => '120.00', 'area' => 'Dhaka Metro', 'courier_provider_id' => $provider->id]); // both

    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(), 'quantity' => 1]],
        currency: Currency::BDT,
        area: 'Dhaka Metro',
        courierProviderId: $provider->id,
    );

    expect($result->baseCharge->toDecimal())->toBe('120.00');
});

it('clamps the final charge to the configured minimum and maximum', function () {
    $staff = testPlatformStaff(PlatformRole::Admin);

    app(SetDeliveryChargeSettings::class)->handle(
        $staff,
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::zero(Currency::BDT),
        fragileHandlingCharge: Money::zero(Currency::BDT),
        minimumCharge: Money::fromDecimal('50.00', Currency::BDT),
        maximumCharge: Money::fromDecimal('200.00', Currency::BDT),
        freeDeliveryThreshold: null,
    );
    // Non-overlapping tiers, the only shape ManageDeliveryChargeRules'
    // overlap refusal actually allows at the same scope.
    deliveryChargeTestRule(['weight_from_grams' => 0, 'weight_to_grams' => 10000, 'base_charge' => '10.00']);
    deliveryChargeTestRule(['weight_from_grams' => 10000, 'weight_to_grams' => null, 'base_charge' => '500.00']);

    $low = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(), 'quantity' => 1]],
        currency: Currency::BDT,
    );
    expect($low->finalCharge->toDecimal())->toBe('50.00');

    $high = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(['net_weight_grams' => 15000]), 'quantity' => 1]],
        currency: Currency::BDT,
    );
    expect($high->finalCharge->toDecimal())->toBe('200.00');
});

it('charges nothing once the order subtotal reaches the free-delivery threshold', function () {
    app(SetDeliveryChargeSettings::class)->handle(
        testPlatformStaff(PlatformRole::Admin),
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::zero(Currency::BDT),
        fragileHandlingCharge: Money::zero(Currency::BDT),
        minimumCharge: Money::zero(Currency::BDT),
        maximumCharge: null,
        freeDeliveryThreshold: Money::fromDecimal('2000.00', Currency::BDT),
    );
    deliveryChargeTestRule(['base_charge' => '60.00']);

    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(), 'quantity' => 1]],
        currency: Currency::BDT,
        orderSubtotal: Money::fromDecimal('2500.00', Currency::BDT),
    );

    expect($result->freeDeliveryApplied)->toBeTrue()
        ->and($result->finalCharge->toDecimal())->toBe('0.00');
});

it('adds a per-box and a fragile handling charge from the global settings', function () {
    app(SetDeliveryChargeSettings::class)->handle(
        testPlatformStaff(PlatformRole::Admin),
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::fromDecimal('15.00', Currency::BDT),
        fragileHandlingCharge: Money::fromDecimal('30.00', Currency::BDT),
        minimumCharge: Money::zero(Currency::BDT),
        maximumCharge: null,
        freeDeliveryThreshold: null,
    );
    deliveryChargeTestRule(['base_charge' => '60.00']);

    $logistics = deliveryChargeTestLogistics([
        'ships_by_box' => true,
        'pieces_per_box' => 5,
        'box_weight_grams' => 1000,
        'is_fragile' => true,
    ]);

    // 12 units at 5/box = 3 boxes.
    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => $logistics, 'quantity' => 12]],
        currency: Currency::BDT,
    );

    expect($result->boxes)->toBe(3)
        ->and($result->boxCharge->toDecimal())->toBe('45.00')
        ->and($result->handlingCharge->toDecimal())->toBe('30.00')
        ->and($result->finalCharge->toDecimal())->toBe('135.00');
});

it('answers zero when no rule covers the chargeable weight, never a guess', function () {
    $result = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => deliveryChargeTestLogistics(), 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($result->rule)->toBeNull()
        ->and($result->baseCharge->toDecimal())->toBe('0.00');
});
