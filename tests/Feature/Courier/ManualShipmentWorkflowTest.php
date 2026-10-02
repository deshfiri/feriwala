<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Actions\EvaluateSupplierPayableEligibility;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The manual courier workflow (Advanced Order Management batch, Commit 5;
 * D8): the only provider implemented end-to-end, driving the order's own
 * courier and delivery axes through the existing Commit 1 actions, never by
 * assigning either attribute directly.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create();
});

function courierTestPackage(): array
{
    return [['weight' => '1.500', 'length' => '20.00', 'width' => '15.00', 'height' => '10.00']];
}

it('creates a manual shipment and moves the order to CourierAssigned/Assigned', function () {
    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $this->order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        courierTestPackage(),
        $this->staff,
        trackingNumber: 'TRK-1',
    );

    expect($shipment->status)->toBe(OrderCourierStatus::Assigned)
        ->and($shipment->tracking_number)->toBe('TRK-1')
        ->and($shipment->delivery_charge->toDecimal())->toBe('80.00')
        ->and($shipment->courierProvider->code)->toBe(CourierProviderCode::Manual)
        ->and($shipment->packages()->count())->toBe(1)
        ->and($shipment->statusHistory()->count())->toBe(1);

    $this->order->refresh();
    expect($this->order->courier_status)->toBe(OrderCourierStatus::Assigned)
        ->and($this->order->delivery_status)->toBe(OrderDeliveryStatus::CourierAssigned);
});

it('refuses a shipment through a disabled provider', function () {
    expect(fn () => app(CreateShipmentFromAllocations::class)->handle(
        $this->order,
        CourierProviderCode::Steadfast,
        Money::fromDecimal('80.00', Currency::BDT),
        courierTestPackage(),
        $this->staff,
    ))->toThrow(CourierProviderUnavailable::class);
});

it('refuses a shipment with no packages', function () {
    expect(fn () => app(CreateShipmentFromAllocations::class)->handle(
        $this->order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        [],
        $this->staff,
    ))->toThrow(InvalidArgumentException::class);
});

it('advances the shipment through to Delivered, hopping the order through OutForDelivery, and rolls the order up to Delivered', function () {
    // OrderStatus started at Shipped (a legal predecessor of Delivered on
    // that axis) so RollUpOrderStatus's own Order::moveTo() call succeeds --
    // a fresh order's default PaymentPending cannot reach Delivered in one
    // move, the same gap OrderLifecycleStateMachineTest's own rollup test
    // works around. delivery_status is left at its own separate default
    // (NotShipped) so the shipment's CourierAssigned move is still legal.
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        courierTestPackage(),
        $this->staff,
    );

    $advance = app(RecordManualCourierStatusUpdate::class);
    $advance->handle($shipment->refresh(), OrderCourierStatus::PickupRequested, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::PickedUp, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::InTransit, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::Delivered, $this->staff);

    $shipment->refresh();
    $order->refresh();

    expect($shipment->status)->toBe(OrderCourierStatus::Delivered)
        ->and($shipment->trackingEvents()->count())->toBe(4)
        ->and($order->courier_status)->toBe(OrderCourierStatus::Delivered)
        ->and($order->delivery_status)->toBe(OrderDeliveryStatus::Delivered)
        ->and($order->status)->toBe(OrderStatus::Delivered);
});

it('requires a reason to cancel or to record a failed delivery', function () {
    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $this->order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        courierTestPackage(),
        $this->staff,
    );

    expect(fn () => app(RecordManualCourierStatusUpdate::class)->handle($shipment->refresh(), OrderCourierStatus::Cancelled, $this->staff))
        ->toThrow(InvalidArgumentException::class);

    app(RecordManualCourierStatusUpdate::class)->handle($shipment->refresh(), OrderCourierStatus::Cancelled, $this->staff, 'Customer asked to cancel.');

    expect($shipment->refresh()->status)->toBe(OrderCourierStatus::Cancelled)
        ->and($shipment->cancelled_by)->toBe($this->staff->id)
        ->and($shipment->cancelled_at)->not->toBeNull();
});

it('marks a Supplier payable delivered through the shipment reaching Delivered, the same as the delivery-status axis does directly', function () {
    $product = websiteTestProduct();
    $offer = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $product, supplierRate: '900.00');
    $offer->stock()->update(['quantity' => 10]);
    supplierTestOfferPriceVersion($offer);

    $line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);

    app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Chosen by staff.');
    $payable = SupplierPayable::query()->sole();
    app(EvaluateSupplierPayableEligibility::class)->markPaymentSettled($payable);

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $this->order->refresh(),
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        courierTestPackage(),
        $this->staff,
    );

    $advance = app(RecordManualCourierStatusUpdate::class);
    $advance->handle($shipment->refresh(), OrderCourierStatus::PickupRequested, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::PickedUp, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::InTransit, $this->staff);
    $advance->handle($shipment->refresh(), OrderCourierStatus::Delivered, $this->staff);

    expect($payable->refresh()->status)->toBe(PayableStatus::Eligible);
});
