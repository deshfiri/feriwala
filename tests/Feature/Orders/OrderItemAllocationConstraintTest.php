<?php

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Models\Supplier;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * The invariants around an order line's source, held by PostgreSQL rather than
 * by the care of whichever action is running.
 *
 * These are deliberately written against the table, not through an action:
 * a guarantee that only holds when the application remembers to check is not a
 * guarantee, and the actions built on top of this are entitled to rely on it.
 */

beforeEach(function () {
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

    $this->warehouse = Warehouse::query()->firstOr(fn () => Warehouse::create([
        'name' => 'Dhaka Central',
        'code' => 'DHK-1',
        'is_active' => true,
    ]));
});

/**
 * A warehouse allocation of the whole line, at 900 cost against 1,300 price.
 *
 * @param  array<string, mixed>  $overrides
 */
function allocationRow(array $overrides = []): OrderItemAllocation
{
    return OrderItemAllocation::create([
        'order_id' => test()->order->id,
        'order_item_id' => test()->line->id,
        'source_type' => AllocationSourceType::Warehouse,
        'warehouse_id' => test()->warehouse->id,
        'quantity' => 2,
        'unit_cost' => Money::fromDecimal('900.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'expected_margin' => Money::fromDecimal('800.00', Currency::BDT),
        'currency_code' => 'BDT',
        'status' => AllocationStatus::Active,
        'allocated_at' => now(),
        'idempotency_key' => 'alloc:'.Str::ulid(),
        ...$overrides,
    ]);
}

it('records a warehouse allocation with its cost, price and margin', function () {
    $allocation = allocationRow();

    expect($allocation->source_type)->toBe(AllocationSourceType::Warehouse)
        ->and($allocation->createsSupplierPayable())->toBeFalse()
        ->and($allocation->unit_cost->toDecimal())->toBe('900.00')
        ->and($allocation->expected_margin->toDecimal())->toBe('800.00')
        ->and($allocation->isActive())->toBeTrue();
});

it('refuses a second active allocation for the same line', function () {
    // The no-split rule and the never-two-reservations rule. Two staff
    // confirming different sources in the same moment: the second insert is
    // refused by the index, not by luck.
    allocationRow();

    // Nothing is asserted after this: a refused write aborts the surrounding
    // transaction, so a follow-up count would fail on the abort rather than
    // tell us anything about the index.
    expect(fn () => allocationRow())->toThrow(QueryException::class);
});

it('lets a superseded allocation sit beside the one that replaced it', function () {
    // Which is the whole reason allocation is its own table: the history has
    // to survive a reallocation the immutable order line cannot describe.
    $first = allocationRow();

    $first->forceFill([
        'status' => AllocationStatus::Superseded,
        'released_at' => now(),
        'release_reason' => 'Reallocated to a Supplier.',
    ])->save();

    $second = allocationRow();
    $first->forceFill(['superseded_by_allocation_id' => $second->id])->save();

    expect(OrderItemAllocation::query()->where('order_item_id', $this->line->id)->count())->toBe(2)
        ->and(OrderItemAllocation::query()->active()->where('order_item_id', $this->line->id)->count())->toBe(1)
        ->and($first->refresh()->superseded_by_allocation_id)->toBe($second->id);
});

it('refuses a margin that does not add up', function () {
    // (1300 - 900) × 2 is 800, not 500. Exact flat-Taka arithmetic, checked by
    // the database as well as by Money (D26).
    expect(fn () => allocationRow(['expected_margin' => Money::fromDecimal('500.00', Currency::BDT)]))
        ->toThrow(QueryException::class);
});

it('refuses a warehouse allocation that also names a Supplier', function () {
    $supplier = Supplier::factory()->create();

    expect(fn () => allocationRow(['supplier_id' => $supplier->id]))
        ->toThrow(QueryException::class);
});

it('refuses a Supplier allocation with no offer behind it', function () {
    $supplier = Supplier::factory()->create();

    expect(fn () => allocationRow([
        'source_type' => AllocationSourceType::SupplierOffer,
        'warehouse_id' => null,
        'supplier_id' => $supplier->id,
    ]))->toThrow(QueryException::class);
});

it('records a Supplier allocation and knows it owes the Supplier', function () {
    $supplier = Supplier::factory()->create();
    $offer = supplierTestOffer($supplier, $this->product, supplierRate: '900.00', platformRate: '1300.00');
    $version = supplierTestOfferPriceVersion($offer);

    $allocation = allocationRow([
        'source_type' => AllocationSourceType::SupplierOffer,
        'warehouse_id' => null,
        'supplier_id' => $supplier->id,
        'supplier_offer_id' => $offer->id,
        'supplier_offer_price_change_id' => $version->id,
    ]);

    expect($allocation->createsSupplierPayable())->toBeTrue()
        ->and($allocation->supplier->id)->toBe($supplier->id)
        ->and($allocation->offer->id)->toBe($offer->id);
});

it('refuses an allocation naming a Supplier that does not own the offer', function () {
    $owner = Supplier::factory()->create();
    $other = Supplier::factory()->create();
    $offer = supplierTestOffer($owner, $this->product);
    $version = supplierTestOfferPriceVersion($offer);

    expect(fn () => allocationRow([
        'source_type' => AllocationSourceType::SupplierOffer,
        'warehouse_id' => null,
        'supplier_id' => $other->id,
        'supplier_offer_id' => $offer->id,
        'supplier_offer_price_change_id' => $version->id,
    ]))->toThrow(QueryException::class);
});

it('refuses an allocation pointing at another order\'s line', function () {
    $otherOrder = Order::factory()->create();

    expect(fn () => allocationRow(['order_id' => $otherOrder->id]))
        ->toThrow(QueryException::class);
});

it('freezes the decision once it is recorded', function () {
    // A payable is calculated from these figures and an audit trail is read
    // against them; a rate edited afterwards would silently restate both.
    $allocation = allocationRow();

    expect(fn () => $allocation->forceFill([
        'unit_cost' => Money::fromDecimal('100.00', Currency::BDT),
    ])->save())->toThrow(QueryException::class);

    expect(fn () => OrderItemAllocation::query()->where('id', $allocation->id)
        ->update(['quantity' => 99]))->toThrow(QueryException::class);
});

it('lets the workflow columns move while the decision stays frozen', function () {
    $allocation = allocationRow();

    $allocation->forceFill([
        'status' => AllocationStatus::Released,
        'released_at' => now(),
        'release_reason' => 'Supplier went out of stock.',
    ])->save();

    expect($allocation->refresh()->status)->toBe(AllocationStatus::Released)
        ->and($allocation->isActive())->toBeFalse();
});

it('is never deleted', function () {
    $allocation = allocationRow();

    expect(fn () => $allocation->delete())->toThrow(QueryException::class);
});

it('refuses a released allocation that does not say when', function () {
    expect(fn () => allocationRow(['status' => AllocationStatus::Released]))
        ->toThrow(QueryException::class);
});

it('refuses a zero quantity', function () {
    expect(fn () => allocationRow([
        'quantity' => 0,
        'expected_margin' => Money::zero(Currency::BDT),
    ]))->toThrow(QueryException::class);
});
