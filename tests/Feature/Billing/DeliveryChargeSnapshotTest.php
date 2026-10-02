<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\SetDeliveryChargeSettings;
use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Catalog\Data\ProductLogistics;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Order\Models\Order;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

/*
 * A shipment's delivery charge and the rule snapshot it was computed from
 * are frozen the moment the shipment is created (beta-critical batch,
 * Commit 2) -- the migration's own locked-columns trigger holds this at the
 * database level; this proves it holds in practice against a later rule or
 * settings change.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::Admin);
});

it('freezes the exact calculation breakdown into the shipment, immune to a later rule change', function () {
    DeliveryChargeRule::create([
        'weight_from_grams' => 0,
        'weight_to_grams' => null,
        'currency_code' => 'BDT',
        'base_charge' => Money::fromDecimal('60.00', Currency::BDT),
        'is_active' => true,
        'effective_from' => CarbonImmutable::now()->subDay(),
    ]);

    $logistics = new ProductLogistics(
        netWeightGrams: 500,
        shippingWeightGrams: null,
        lengthCm: null,
        widthCm: null,
        heightCm: null,
        shipsByBox: false,
        piecesPerBox: null,
        boxWeightGrams: null,
        boxLengthCm: null,
        boxWidthCm: null,
        boxHeightCm: null,
        isFragile: false,
    );

    $calculation = app(CalculateDeliveryCharge::class)->handle(
        lines: [['logistics' => $logistics, 'quantity' => 1]],
        currency: Currency::BDT,
    );

    expect($calculation->finalCharge->toDecimal())->toBe('60.00');

    $order = Order::factory()->create();

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $order,
        CourierProviderCode::Manual,
        $calculation->finalCharge,
        [['weight' => '0.500']],
        $this->staff,
        calculation: $calculation,
    );

    expect($shipment->delivery_charge->toDecimal())->toBe('60.00')
        ->and($shipment->delivery_charge_rule_snapshot['chargeable_weight_grams'])->toBe(500)
        ->and($shipment->delivery_charge_rule_snapshot['base_charge']['amount'])->toBe('60.00');

    // The rule is closed and replaced with a much higher charge -- the
    // already-created shipment must not move.
    app(SetDeliveryChargeSettings::class)->handle(
        $this->staff,
        volumetricDivisor: 5000,
        useGreaterOfActualAndVolumetric: true,
        additionalPerKgCharge: Money::zero(Currency::BDT),
        perBoxCharge: Money::zero(Currency::BDT),
        fragileHandlingCharge: Money::zero(Currency::BDT),
        minimumCharge: Money::zero(Currency::BDT),
        maximumCharge: null,
        freeDeliveryThreshold: null,
    );
    DeliveryChargeRule::query()->update(['is_active' => false]);
    DeliveryChargeRule::create([
        'weight_from_grams' => 0,
        'weight_to_grams' => null,
        'currency_code' => 'BDT',
        'base_charge' => Money::fromDecimal('999.00', Currency::BDT),
        'is_active' => true,
        'effective_from' => CarbonImmutable::now(),
    ]);

    expect($shipment->refresh()->delivery_charge->toDecimal())->toBe('60.00');
});

it('refuses to update a shipment\'s locked delivery charge columns directly', function () {
    $order = Order::factory()->create();

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        [['weight' => '1.000']],
        $this->staff,
    );

    expect(fn () => $shipment->forceFill(['delivery_charge' => Money::fromDecimal('999.00', Currency::BDT)])->save())
        ->toThrow(QueryException::class);
});
