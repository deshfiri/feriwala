<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AdvanceOrderDeliveryStatus;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Supplier\Actions\EvaluateSupplierPayableEligibility;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Commit 4 of the Advanced Order Management batch (P13-26): OrderDeliveryStatus
 * reaching Delivered is the real fulfilment handover event -- every Supplier
 * payable on the order is now marked delivered automatically, the same way
 * the manual RecordSupplierPayableDelivery stand-in always did it by hand.
 * Delivery failing cancels a payable still Pending, the same rule order
 * cancellation already applies; an already-Eligible payable is left for the
 * Return domain to reverse, never touched here.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT),
        'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);
});

function deliveryPayableTestOffer(string $rate = '900.00', int $stock = 10): SupplierOffer
{
    $offer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        test()->product,
        supplierRate: $rate,
    );
    $offer->stock()->update(['quantity' => $stock]);
    supplierTestOfferPriceVersion($offer);

    return $offer->refresh();
}

function deliveryPayableTestAllocate(SupplierOffer $offer): void
{
    app(AllocateOrderLineSource::class)->handle(
        test()->line->refresh(),
        AllocationSourceType::SupplierOffer,
        $offer->public_id,
        test()->staff,
        'Chosen by staff after comparing sources.',
    );
}

function deliveryPayableTestAdvanceToDelivered(Order $order): void
{
    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($order->refresh(), OrderDeliveryStatus::CourierAssigned, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Shipped, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::OutForDelivery, test()->staff);
    $advance->handle($order->refresh(), OrderDeliveryStatus::Delivered, test()->staff);
}

it('marks the Supplier payable delivered and eligible once delivery lands, given payment already settled', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    app(EvaluateSupplierPayableEligibility::class)->markPaymentSettled($payable);
    expect($payable->refresh()->status)->toBe(PayableStatus::Pending);

    deliveryPayableTestAdvanceToDelivered($this->order);

    expect($payable->refresh()->status)->toBe(PayableStatus::Eligible)
        ->and($payable->delivered_at)->not->toBeNull()
        ->and($payable->eligible_at)->not->toBeNull();
});

it('marks delivered but stays Pending when payment has not settled yet', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    deliveryPayableTestAdvanceToDelivered($this->order);

    expect($payable->refresh()->status)->toBe(PayableStatus::Pending)
        ->and($payable->delivered_at)->not->toBeNull()
        ->and($payable->eligible_at)->toBeNull();
});

it('does not re-fire eligibility a second time once already Eligible', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    app(EvaluateSupplierPayableEligibility::class)->markPaymentSettled($payable);
    deliveryPayableTestAdvanceToDelivered($this->order);

    $historyCountBefore = $payable->refresh()->statusHistory()->count();

    app(EvaluateSupplierPayableEligibility::class)->markDelivered($payable);

    expect($payable->refresh()->status)->toBe(PayableStatus::Eligible)
        ->and($payable->statusHistory()->count())->toBe($historyCountBefore);
});

it('cancels a still-Pending Supplier payable when delivery fails', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::CourierAssigned, $this->staff);
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::Shipped, $this->staff);
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::OutForDelivery, $this->staff);
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::FailedDelivery, $this->staff, 'Customer unreachable at the address.');

    expect($payable->refresh()->status)->toBe(PayableStatus::Cancelled);
});

it('leaves an already-Eligible Supplier payable alone when the order is later returned', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    app(EvaluateSupplierPayableEligibility::class)->markPaymentSettled($payable);
    deliveryPayableTestAdvanceToDelivered($this->order);
    expect($payable->refresh()->status)->toBe(PayableStatus::Eligible);

    $advance = app(AdvanceOrderDeliveryStatus::class);
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::ReturnRequested, $this->staff, 'Customer requested a return.');
    $advance->handle($this->order->refresh(), OrderDeliveryStatus::Returned, $this->staff);

    // The Return domain (RequestOrderReturn -> ReceiveReturnedItems ->
    // ReverseSupplierPayable) is the one path that reverses an Eligible
    // payable; this coarser delivery axis only ever cancels a Pending one.
    expect($payable->refresh()->status)->toBe(PayableStatus::Eligible);
});

it('cancels a still-Pending Supplier payable if the order never leaves the warehouse', function () {
    $offer = deliveryPayableTestOffer();
    deliveryPayableTestAllocate($offer);
    $payable = SupplierPayable::query()->sole();

    app(AdvanceOrderDeliveryStatus::class)->handle($this->order->refresh(), OrderDeliveryStatus::Cancelled, $this->staff, 'Order cancelled before dispatch.');

    expect($payable->refresh()->status)->toBe(PayableStatus::Cancelled);
});
