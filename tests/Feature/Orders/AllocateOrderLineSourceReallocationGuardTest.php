<?php

use App\Domain\Order\Actions\AdvanceOrderFulfilmentStatus;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\OrderFulfillmentStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * AllocateOrderLineSource::canReallocate() -- completed in this batch from
 * two single-case stub enums into a real guard (§20, §21). Reallocation is
 * refused once fulfilment has reached picking, unless staff explicitly
 * override it, which is recorded against the resulting allocation.
 */

beforeEach(function () {
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->offerA = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => null,
        'lead_time_days' => 3,
    ]);
    supplierTestOfferPriceVersion($this->offerA);

    $this->offerB = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => $this->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal('520.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('720.00', Currency::BDT),
        'currency_code' => 'BDT',
        'activated_at' => now(),
        'supply_mode' => SupplyMode::OnDemand->value,
        'fulfilment_capacity' => null,
        'lead_time_days' => 5,
    ]);
    supplierTestOfferPriceVersion($this->offerB);

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('700.00', Currency::BDT),
        'line_total' => Money::fromDecimal('700.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->staff = User::factory()->staff()->create();

    app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Initial allocation.',
    );
});

function reallocationGuardAdvanceToPicking(Order $order, User $staff): void
{
    $advance = app(AdvanceOrderFulfilmentStatus::class);
    $advance->handle($order, OrderFulfillmentStatus::SourceAllocationPending, $staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Processing, $staff);
    $advance->handle($order->refresh(), OrderFulfillmentStatus::Picking, $staff);
}

test('reallocation is allowed while fulfilment is still early', function () {
    app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Switching before picking.',
    );

    $active = OrderItemAllocation::query()->where('order_item_id', $this->line->id)->where('status', 'active')->sole();

    expect($active->offer->public_id)->toBe($this->offerB->public_id)
        ->and($active->override_by)->toBeNull();
});

test('reallocation is refused once fulfilment has reached picking', function () {
    reallocationGuardAdvanceToPicking($this->order, $this->staff);

    expect(fn () => app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Too late.',
    ))->toThrow(AllocationRefused::class, 'picked or dispatched');

    $active = OrderItemAllocation::query()->where('order_item_id', $this->line->id)->where('status', 'active')->sole();
    expect($active->offer->public_id)->toBe($this->offerA->public_id);
});

test('an explicit override reallocates past picking and is recorded on the new allocation', function () {
    reallocationGuardAdvanceToPicking($this->order, $this->staff);

    app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Overriding: Supplier A can no longer fulfil in time.', override: true,
    );

    $active = OrderItemAllocation::query()->where('order_item_id', $this->line->id)->where('status', 'active')->sole();

    expect($active->offer->public_id)->toBe($this->offerB->public_id)
        ->and($active->override_by)->toBe($this->staff->id)
        ->and($active->override_at)->not->toBeNull()
        ->and($active->override_reason)->toBe('Overriding: Supplier A can no longer fulfil in time.');
});
