<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The allocation lifecycle (batch §5, §6, §7): staff choose the source, the
 * platform commits to it exactly once, and a Supplier source — and only a
 * Supplier source — makes Feriwala owe something.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $this->product->forceFill(['base_cost' => Money::fromDecimal('700.00', Currency::BDT)])->save();

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

function allocateTestWarehouse(int $available = 10): Warehouse
{
    $warehouse = Warehouse::create([
        'name' => 'Dhaka Central',
        'code' => 'DHK-'.random_int(100, 999),
        'is_active' => true,
    ]);

    StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => test()->product->id,
        'product_variant_id' => null,
        'available' => $available,
    ]);

    return $warehouse;
}

function allocateTestOffer(string $rate = '900.00', int $stock = 10): SupplierOffer
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

function allocateTestLineTo(AllocationSourceType $type, string $sourceId, ?User $actor = null): OrderItemAllocation
{
    return app(AllocateOrderLineSource::class)->handle(
        test()->line->refresh(),
        $type,
        $sourceId,
        $actor ?? test()->staff,
        'Chosen by staff after comparing sources.',
    );
}

describe('allocating to the central warehouse', function () {
    it('reserves the stock, snapshots the decision and owes no Supplier', function () {
        $warehouse = allocateTestWarehouse();

        $allocation = allocateTestLineTo(AllocationSourceType::Warehouse, $warehouse->public_id);

        expect($allocation->source_type)->toBe(AllocationSourceType::Warehouse)
            ->and($allocation->warehouse_id)->toBe($warehouse->id)
            ->and($allocation->supplier_id)->toBeNull()
            ->and($allocation->quantity)->toBe(2)
            ->and($allocation->unit_cost->toDecimal())->toBe('700.00')
            ->and($allocation->expected_margin->toDecimal())->toBe('1200.00')
            ->and($allocation->allocated_by)->toBe($this->staff->id)
            ->and($allocation->status)->toBe(AllocationStatus::Active)
            ->and($allocation->reservation->status)->toBe(StockReservationStatus::Active);

        // §8: a warehouse-sourced line never creates a Supplier payable.
        expect(SupplierPayable::query()->count())->toBe(0);
    });

    it('takes the units out of available stock', function () {
        $warehouse = allocateTestWarehouse(available: 10);

        allocateTestLineTo(AllocationSourceType::Warehouse, $warehouse->public_id);

        $item = StockItem::query()->where('warehouse_id', $warehouse->id)->sole();

        expect($item->available)->toBe(8)
            ->and($item->reserved)->toBe(2);
    });
});

describe('allocating to a supplier offer', function () {
    it('reserves the supplier stock and raises one pending payable at the snapshotted rate', function () {
        $offer = allocateTestOffer(rate: '900.00');

        $allocation = allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);

        expect($allocation->source_type)->toBe(AllocationSourceType::SupplierOffer)
            ->and($allocation->supplier_id)->toBe($offer->supplier_id)
            ->and($allocation->supplier_offer_id)->toBe($offer->id)
            ->and($allocation->warehouse_id)->toBeNull()
            ->and($allocation->unit_cost->toDecimal())->toBe('900.00')
            // (1300 - 900) x 2
            ->and($allocation->expected_margin->toDecimal())->toBe('800.00');

        $payable = SupplierPayable::query()->sole();

        expect($payable->supplier_id)->toBe($offer->supplier_id)
            ->and($payable->order_item_allocation_id)->toBe($allocation->id)
            ->and($payable->quantity)->toBe(2)
            ->and($payable->supplier_rate->toDecimal())->toBe('900.00')
            // quantity x snapshotted Supplier Rate, exact flat Taka
            ->and($payable->gross_amount->toDecimal())->toBe('1800.00')
            ->and($payable->status)->toBe(PayableStatus::Pending)
            ->and($payable->triggering_event)->toBe('staff_allocation')
            // Pending earnings are a claim, never money: nothing settled.
            ->and($payable->settled_at)->toBeNull();
    });

    it('keeps paying the snapshotted rate after the offer is repriced', function () {
        $offer = allocateTestOffer(rate: '900.00');

        $allocation = allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);

        $offer->forceFill(['supplier_rate' => Money::fromDecimal('1250.00', Currency::BDT)])->save();

        expect($allocation->refresh()->unit_cost->toDecimal())->toBe('900.00')
            ->and(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('1800.00');
    });

    it('takes the units out of the supplier\'s approved availability', function () {
        $offer = allocateTestOffer(stock: 10);

        allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);

        expect($offer->stock()->sole()->quantity)->toBe(8)
            ->and($offer->stock()->sole()->reserved_quantity)->toBe(2);
    });
});

describe('choosing between suppliers', function () {
    it('allocates to the supplier staff picked, not the cheapest', function () {
        // The platform must never make this decision on price (§5).
        $cheapest = allocateTestOffer(rate: '500.00');
        $chosen = allocateTestOffer(rate: '1100.00');

        $allocation = allocateTestLineTo(AllocationSourceType::SupplierOffer, $chosen->public_id);

        expect($allocation->supplier_offer_id)->toBe($chosen->id)
            ->and($allocation->supplier_offer_id)->not->toBe($cheapest->id)
            ->and($allocation->unit_cost->toDecimal())->toBe('1100.00');
    });

    it('reports all five candidates for one product and allocates the one staff picked', function () {
        $offers = collect(['500.00', '650.00', '800.00', '950.00', '1100.00'])
            ->map(fn (string $rate) => allocateTestOffer(rate: $rate));

        $candidates = app(AllocationSourceCandidates::class)->forLine(test()->line);
        $supplierCandidates = collect($candidates)->filter(
            fn ($candidate) => $candidate->sourceType === AllocationSourceType::SupplierOffer,
        );

        expect($supplierCandidates)->toHaveCount(5)
            ->and($supplierCandidates->every(fn ($candidate) => $candidate->isEligible))->toBeTrue();

        // Staff picks the middle one — neither the cheapest nor the dearest.
        $picked = $offers->get(2);
        $allocation = allocateTestLineTo(AllocationSourceType::SupplierOffer, $picked->public_id);

        expect($allocation->supplier_offer_id)->toBe($picked->id)
            ->and($allocation->unit_cost->toDecimal())->toBe('800.00');
    });
});

describe('refusing an allocation', function () {
    it('refuses a source that cannot cover the whole line', function () {
        // No split: one authoritative source for the whole quantity.
        $offer = allocateTestOffer(stock: 1);

        expect(fn () => allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id))
            ->toThrow(AllocationRefused::class, '1 of the 2');

        expect(OrderItemAllocation::query()->count())->toBe(0)
            ->and(SupplierPayable::query()->count())->toBe(0);
    });

    it('refuses a source that does not serve this product', function () {
        $other = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]));

        expect(fn () => allocateTestLineTo(AllocationSourceType::SupplierOffer, $other->public_id))
            ->toThrow(AllocationRefused::class, 'does not serve this product');
    });

    it('refuses an allocation with no reason recorded', function () {
        $warehouse = allocateTestWarehouse();

        expect(fn () => app(AllocateOrderLineSource::class)->handle(
            $this->line, AllocationSourceType::Warehouse, $warehouse->public_id, $this->staff, '  ',
        ))->toThrow(AllocationRefused::class, 'reason is required');
    });
});

describe('confirming the same source twice', function () {
    it('returns the existing allocation and reserves nothing more', function () {
        // The confirm button is double-clickable and the request retryable.
        $offer = allocateTestOffer(stock: 10);

        $first = allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);
        $second = allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);
        $third = allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);

        expect($second->id)->toBe($first->id)
            ->and($third->id)->toBe($first->id)
            ->and(OrderItemAllocation::query()->count())->toBe(1)
            ->and(SupplierPayable::query()->count())->toBe(1)
            // Two units held, not six.
            ->and($offer->stock()->sole()->quantity)->toBe(8);
    });
});

describe('reallocating to a different source', function () {
    it('moves the reservation and the payable exactly once', function () {
        $first = allocateTestOffer(rate: '900.00', stock: 10);
        $second = allocateTestOffer(rate: '1000.00', stock: 10);

        $original = allocateTestLineTo(AllocationSourceType::SupplierOffer, $first->public_id);
        $replacement = allocateTestLineTo(AllocationSourceType::SupplierOffer, $second->public_id);

        $original->refresh();

        expect($original->status)->toBe(AllocationStatus::Superseded)
            ->and($original->superseded_by_allocation_id)->toBe($replacement->id)
            ->and($original->released_by)->toBe($this->staff->id)
            ->and($replacement->status)->toBe(AllocationStatus::Active);

        // Never two active allocations for one line.
        expect(OrderItemAllocation::query()->where('order_item_id', $this->line->id)
            ->where('status', AllocationStatus::Active)->count())->toBe(1);

        // The first Supplier's units came back; the second's are held.
        expect($first->stock()->sole()->quantity)->toBe(10)
            ->and($first->stock()->sole()->reserved_quantity)->toBe(0)
            ->and($second->stock()->sole()->quantity)->toBe(8);

        // One live payable, for the Supplier now actually serving the line.
        $live = SupplierPayable::query()->where('status', PayableStatus::Pending)->get();

        expect($live)->toHaveCount(1)
            ->and($live->first()->supplier_id)->toBe($second->supplier_id)
            ->and($live->first()->gross_amount->toDecimal())->toBe('2000.00')
            ->and($live->first()->triggering_event)->toBe('reallocation');

        // The first Supplier's claim is cancelled, not deleted.
        $cancelled = SupplierPayable::query()->where('status', PayableStatus::Cancelled)->sole();

        expect($cancelled->supplier_id)->toBe($first->supplier_id)
            ->and($cancelled->cancelled_at)->not->toBeNull();
    });

    it('moves a supplier line to the central warehouse and stops owing anyone', function () {
        $offer = allocateTestOffer(stock: 10);
        $warehouse = allocateTestWarehouse();

        allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);
        $replacement = allocateTestLineTo(AllocationSourceType::Warehouse, $warehouse->public_id);

        expect($replacement->source_type)->toBe(AllocationSourceType::Warehouse)
            ->and($replacement->createsSupplierPayable())->toBeFalse()
            // The Supplier's units are back and their claim is cancelled.
            ->and($offer->stock()->sole()->quantity)->toBe(10)
            ->and(SupplierPayable::query()->where('status', PayableStatus::Pending)->count())->toBe(0)
            ->and(SupplierPayable::query()->where('status', PayableStatus::Cancelled)->count())->toBe(1);
    });

    it('moves a warehouse line to a supplier and starts owing them', function () {
        $warehouse = allocateTestWarehouse(available: 10);
        $offer = allocateTestOffer(rate: '950.00', stock: 10);

        allocateTestLineTo(AllocationSourceType::Warehouse, $warehouse->public_id);
        allocateTestLineTo(AllocationSourceType::SupplierOffer, $offer->public_id);

        $item = StockItem::query()->where('warehouse_id', $warehouse->id)->sole();

        expect($item->available)->toBe(10)
            ->and($item->reserved)->toBe(0)
            ->and(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('1900.00');
    });
});
