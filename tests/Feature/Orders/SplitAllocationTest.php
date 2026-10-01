<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Exceptions\AllocationRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Split allocation (Advanced Order Management batch, Commit 3): one order
 * line held by more than one simultaneously active allocation at once, each
 * for part of its quantity. AllocateOrderLineSource::handle()'s $quantity
 * and $replacingAllocationId parameters are the whole mechanism; omitting
 * both keeps every pre-split caller behaving exactly as before.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $offer = function (string $rate) use ($supplier) {
        $offer = SupplierOffer::create([
            'supplier_id' => $supplier->id,
            'product_id' => $this->product->id,
            'status' => OfferStatus::Active,
            'supplier_rate' => Money::fromDecimal($rate, Currency::BDT),
            'platform_rate' => Money::fromDecimal('700.00', Currency::BDT),
            'currency_code' => 'BDT',
            'activated_at' => now(),
            'supply_mode' => SupplyMode::OnDemand->value,
            'fulfilment_capacity' => null,
            'lead_time_days' => null,
        ]);
        supplierTestOfferPriceVersion($offer);

        return $offer;
    };

    $this->offerA = $offer('500.00');
    $this->offerB = $offer('520.00');
    $this->offerC = $offer('540.00');

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 10,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('700.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('7000.00', Currency::BDT),
        'line_total' => Money::fromDecimal('7000.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->staff = testPlatformStaff(PlatformRole::Admin);
});

test('two sources can split one line, each with its own commitment and payable', function () {
    $allocate = app(AllocateOrderLineSource::class);

    $allocationA = $allocate->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Split part 1.', quantity: 6,
    );
    $allocationB = $allocate->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Split part 2.', quantity: 4,
    );

    expect($allocationA->quantity)->toBe(6)
        ->and($allocationB->quantity)->toBe(4);

    $active = OrderItemAllocation::query()->where('order_item_id', $this->line->id)->where('status', AllocationStatus::Active)->get();
    expect($active)->toHaveCount(2);

    expect(SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $allocationA->id)->sole()->quantity)->toBe(6)
        ->and(SupplierFulfilmentCommitment::query()->where('order_item_allocation_id', $allocationB->id)->sole()->quantity)->toBe(4);

    expect(SupplierPayable::query()->where('order_item_allocation_id', $allocationA->id)->exists())->toBeTrue()
        ->and(SupplierPayable::query()->where('order_item_allocation_id', $allocationB->id)->exists())->toBeTrue();
});

test('omitting the quantity allocates everything not yet covered, unchanged from before split allocation', function () {
    $allocation = app(AllocateOrderLineSource::class)->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Whole line.',
    );

    expect($allocation->quantity)->toBe(10);
});

test('a second split cannot push the sum of active allocations past the line quantity', function () {
    $allocate = app(AllocateOrderLineSource::class);
    $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Split part 1.', quantity: 6);

    expect(fn () => $allocate->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Too much.', quantity: 5,
    ))->toThrow(AllocationRefused::class, '4 unit');
});

test('the database refuses an active allocation sum exceeding the line quantity even bypassing the Action', function () {
    $allocate = app(AllocateOrderLineSource::class);
    $allocationA = $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Split part 1.', quantity: 6);

    $clone = $allocationA->replicate(['public_id', 'idempotency_key', 'supplier_offer_id', 'supplier_id']);
    $clone->supplier_offer_id = $this->offerB->id;
    $clone->supplier_id = $this->offerB->supplier_id;
    $clone->idempotency_key = 'test-clone-'.Str::lower((string) Str::ulid());
    $clone->quantity = 10;

    expect(fn () => $clone->save())->toThrow(QueryException::class, 'cannot hold');
});

test('reallocating one split share leaves the other untouched', function () {
    $allocate = app(AllocateOrderLineSource::class);
    $allocationA = $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Split part 1.', quantity: 6);
    $allocationB = $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Split part 2.', quantity: 4);

    $replacement = $allocate->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerC->public_id, $this->staff, 'Supplier A can no longer fulfil in time.',
        quantity: 6, replacingAllocationId: $allocationA->public_id,
    );

    expect($replacement->offer->public_id)->toBe($this->offerC->public_id)
        ->and($replacement->quantity)->toBe(6)
        ->and($allocationA->refresh()->status)->toBe(AllocationStatus::Superseded)
        ->and($allocationB->refresh()->status)->toBe(AllocationStatus::Active)
        ->and($allocationB->quantity)->toBe(4);
});

test('a like-for-like reallocation of a fully-allocated line is not refused for "no units left"', function () {
    $allocate = app(AllocateOrderLineSource::class);
    $allocationA = $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Whole line.');

    $replacement = $allocate->handle(
        $this->line, AllocationSourceType::SupplierOffer, $this->offerB->public_id, $this->staff, 'Switching Suppliers.',
        replacingAllocationId: $allocationA->public_id,
    );

    expect($replacement->quantity)->toBe(10)
        ->and($replacement->offer->public_id)->toBe($this->offerB->public_id);
});

test('AllocationSourceCandidates reports the remaining quantity, excluding the allocation being replaced', function () {
    $allocate = app(AllocateOrderLineSource::class);
    $allocationA = $allocate->handle($this->line, AllocationSourceType::SupplierOffer, $this->offerA->public_id, $this->staff, 'Split part 1.', quantity: 6);

    $candidates = app(AllocationSourceCandidates::class);

    expect($candidates->remainingQuantity($this->line))->toBe(4)
        ->and($candidates->remainingQuantity($this->line, $allocationA))->toBe(10);
});
