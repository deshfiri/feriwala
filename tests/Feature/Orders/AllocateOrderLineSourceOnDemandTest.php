<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Allocating to an approved on_demand/pre_order offer with no physical
 * stock (Supplier Bulk Product Listing batch, commit 5 of 5, corrections
 * 6-10): the capacity reservation (SupplierFulfilmentCommitment) stands in
 * for a stock reservation, created atomically alongside the allocation and
 * the payable, and released atomically on reallocation -- never a fake
 * zero-stock row, never a real StockReservation.
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

/** An approved on_demand/pre_order offer with no stock row at all. */
function fulfilmentCommitmentTestOffer(
    SupplyMode $mode = SupplyMode::OnDemand,
    ?int $capacity = null,
    string $rate = '900.00',
): SupplierOffer {
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id,
        'product_id' => test()->product->id,
        'status' => OfferStatus::Active,
        'supplier_rate' => Money::fromDecimal($rate, Currency::BDT),
        'platform_rate' => Money::fromDecimal('1300.00', Currency::BDT),
        'currency_code' => 'BDT',
        'wholesale_enabled' => true,
        'activated_at' => now(),
        'supply_mode' => $mode->value,
        'fulfilment_capacity' => $capacity,
        'lead_time_days' => 5,
    ]);

    supplierTestOfferPriceVersion($offer);

    return $offer->refresh();
}

function fulfilmentCommitmentTestAllocate(AllocationSourceType $type, string $sourceId, ?OrderItemAllocation $replacing = null)
{
    return app(AllocateOrderLineSource::class)->handle(
        test()->line->refresh(),
        $type,
        $sourceId,
        test()->staff,
        'Chosen by staff after comparing sources.',
        replacingAllocationId: $replacing?->public_id,
    );
}

test('an on_demand offer with no stock is still shown, honestly, as zero, and reports its supply details', function () {
    $offer = fulfilmentCommitmentTestOffer(capacity: 5);

    $candidate = collect(app(AllocationSourceCandidates::class)->forLine($this->line))
        ->firstOrFail(fn ($c) => $c->sourceType === AllocationSourceType::SupplierOffer);

    expect($candidate->isEligible)->toBeTrue()
        ->and($candidate->available)->toBe(0)
        ->and($candidate->availableToPromise)->toBe(0)
        ->and($candidate->supplyMode)->toBe(SupplyMode::OnDemand)
        ->and($candidate->fulfilmentCapacity)->toBe(5)
        ->and($candidate->requiresConfirmation)->toBeTrue()
        ->and($candidate->leadTimeDays)->toBe(5);
});

test('allocating an on_demand offer creates a commitment and a payable, but no stock or reservation', function () {
    $offer = fulfilmentCommitmentTestOffer();

    $allocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $offer->public_id);

    expect($allocation->stock_reservation_id)->toBeNull()
        ->and($allocation->reservation)->toBeNull()
        ->and($offer->stock)->toBeNull();

    $commitment = SupplierFulfilmentCommitment::query()->sole();

    expect($commitment->order_item_allocation_id)->toBe($allocation->id)
        ->and($commitment->supplier_offer_id)->toBe($offer->id)
        ->and($commitment->quantity)->toBe(2)
        ->and($commitment->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation)
        ->and($commitment->due_at)->not->toBeNull();

    expect(SupplierPayable::query()->sole()->order_item_allocation_id)->toBe($allocation->id);
});

test('allocating a ready_stock offer creates no fulfilment commitment', function () {
    $offer = fulfilmentCommitmentTestOffer(mode: SupplyMode::ReadyStock);
    $offer->stock()->create(['quantity' => 10]);

    $allocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $offer->public_id);

    expect($allocation->stock_reservation_id)->not->toBeNull()
        ->and(SupplierFulfilmentCommitment::query()->count())->toBe(0);
});

test('a bounded capacity refuses the allocation that would exceed it, and nothing is written', function () {
    $offer = fulfilmentCommitmentTestOffer(capacity: 2);

    // Uses the whole capacity.
    $firstOrder = Order::factory()->create();
    $firstLine = OrderItem::create([
        'order_id' => $firstOrder->id, 'line_number' => 1, 'product_id' => $this->product->id,
        'sku' => $this->product->sku, 'product_name' => $this->product->name, 'quantity' => 2,
        'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT), 'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
        'created_at' => now(),
    ]);
    app(AllocateOrderLineSource::class)->handle($firstLine, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'First line.');

    expect(fn () => fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $offer->public_id))
        ->toThrow(AllocationRefused::class, 'This offer can commit to 2 units');

    expect(SupplierFulfilmentCommitment::query()->count())->toBe(1)
        ->and(OrderItemAllocation::query()->where('order_item_id', $this->line->id)->count())->toBe(0);
});

test('an unbounded capacity never refuses', function () {
    $offer = fulfilmentCommitmentTestOffer(capacity: null);

    for ($i = 0; $i < 3; $i++) {
        $order = Order::factory()->create();
        $line = OrderItem::create([
            'order_id' => $order->id, 'line_number' => 1, 'product_id' => $this->product->id,
            'sku' => $this->product->sku, 'product_name' => $this->product->name, 'quantity' => 5,
            'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('6500.00', Currency::BDT), 'line_total' => Money::fromDecimal('6500.00', Currency::BDT),
            'created_at' => now(),
        ]);
        app(AllocateOrderLineSource::class)->handle($line, AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Line '.$i);
    }

    expect(SupplierFulfilmentCommitment::query()->count())->toBe(3);
});

test('reallocating away from an on_demand offer cancels its commitment atomically', function () {
    $original = fulfilmentCommitmentTestOffer(capacity: 2);
    $replacement = fulfilmentCommitmentTestOffer(capacity: 2);

    $firstAllocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $original->public_id);
    $originalCommitment = SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $firstAllocation->id)->sole();

    $secondAllocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $replacement->public_id, replacing: $firstAllocation);

    expect($originalCommitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Cancelled)
        ->and($originalCommitment->cancelled_at)->not->toBeNull()
        ->and(SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $secondAllocation->id)->sole()->status)
        ->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation)
        // The freed capacity on the original offer now admits a new line.
        ->and(fn () => app(AllocateOrderLineSource::class)->handle(
            OrderItem::create([
                'order_id' => Order::factory()->create()->id, 'line_number' => 1, 'product_id' => $this->product->id,
                'sku' => $this->product->sku, 'product_name' => $this->product->name, 'quantity' => 2,
                'currency_code' => 'BDT', 'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
                'line_subtotal' => Money::fromDecimal('2600.00', Currency::BDT), 'line_total' => Money::fromDecimal('2600.00', Currency::BDT),
                'created_at' => now(),
            ]),
            AllocationSourceType::SupplierOffer,
            $original->public_id,
            $this->staff,
            'Capacity freed by the earlier reallocation.',
        ))->not->toThrow(AllocationRefused::class);
});

test('a reviewer who cannot see supplier pricing cannot manage a fulfilment commitment', function () {
    $offer = fulfilmentCommitmentTestOffer();
    $allocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $offer->public_id);
    $commitment = SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $allocation->id)->sole();

    $limited = User::factory()->staff()->create();
    $limited->givePermissionTo(['order.view', 'order.edit']);

    $this->actingAs($limited)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $commitment,
    ]), ['action' => 'confirm'])->assertForbidden();

    expect($commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::AwaitingConfirmation);
});

test('staff can confirm a commitment through the http route', function () {
    $offer = fulfilmentCommitmentTestOffer();
    $allocation = fulfilmentCommitmentTestAllocate(AllocationSourceType::SupplierOffer, $offer->public_id);
    $commitment = SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $allocation->id)->sole();

    $reviewer = User::factory()->staff()->create();
    $reviewer->givePermissionTo(['order.view', 'order.edit', 'supplier_pricing.view']);

    $this->actingAs($reviewer)->post(route('admin.orders.lines.fulfilment-commitments.advance', [
        $this->order, $this->line, $commitment,
    ]), ['action' => 'confirm'])->assertSessionHasNoErrors();

    expect($commitment->refresh()->status)->toBe(FulfilmentCommitmentStatus::Confirmed)
        ->and($commitment->confirmed_by)->toBe($reviewer->id);
});
