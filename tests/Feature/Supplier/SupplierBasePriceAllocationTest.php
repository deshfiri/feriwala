<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Actions\AssignProductToSupplier;
use App\Domain\Supplier\Actions\ChangeSupplierBasePrice;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The Supplier Base Price of an assigned Product is what order allocation
 * offers, snapshots and owes — and a later price change never reaches back
 * into an allocation or payable already made.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $this->product->forceFill(['wholesale_price' => Money::fromDecimal('1200.00', Currency::BDT)])->save();

    $this->supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);

    $this->line = OrderItem::create([
        'order_id' => $this->order->id,
        'line_number' => 1,
        'product_id' => $this->product->id,
        'sku' => $this->product->sku,
        'product_name' => $this->product->name,
        'quantity' => 3,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('3900.00', Currency::BDT),
        'line_total' => Money::fromDecimal('3900.00', Currency::BDT),
        'created_at' => now(),
    ]);

    $this->offer = app(AssignProductToSupplier::class)->handle(
        $this->staff,
        $this->product,
        null,
        $this->supplier,
        Money::fromDecimal('850.00', Currency::BDT),
        ['quantity' => 20],
    );
});

function basePriceAllocate($test): OrderItemAllocation
{
    return app(AllocateOrderLineSource::class)->handle(
        $test->line->refresh(),
        AllocationSourceType::SupplierOffer,
        $test->offer->public_id,
        $test->staff,
        'Chosen by staff after comparing sources.',
    );
}

it('offers an assigned Supplier at its Supplier Base Price with the payable it would raise, and picks nothing', function () {
    $candidate = collect(app(AllocationSourceCandidates::class)->forLine($this->line))
        ->first(fn ($candidate) => $candidate->sourceId === $this->offer->public_id);

    expect($candidate)->not->toBeNull()
        ->and($candidate->unitCost->toDecimal())->toBe('850.00')
        ->and($candidate->expectedPayable?->toDecimal())->toBe('2550.00')
        ->and($candidate->supplierName)->toBe($this->supplier->business_name)
        ->and($candidate->supplyMode->value)->toBe('ready_stock')
        ->and($candidate->isEligible)->toBeTrue();

    // Nothing is chosen until staff confirm one.
    expect(OrderItemAllocation::query()->count())->toBe(0)
        ->and(SupplierPayable::query()->count())->toBe(0);
});

it('snapshots the base price and raises a payable of that price times the allocated quantity', function () {
    $allocation = basePriceAllocate($this);
    $payable = SupplierPayable::query()->sole();

    expect($allocation->unit_cost->toDecimal())->toBe('850.00')
        ->and($allocation->supplier_offer_id)->toBe($this->offer->id)
        ->and($payable->supplier_rate->toDecimal())->toBe('850.00')
        ->and($payable->quantity)->toBe(3)
        ->and($payable->gross_amount->toDecimal())->toBe('2550.00');
});

it('leaves an existing allocation and payable alone when the base price changes later', function () {
    $allocation = basePriceAllocate($this);

    app(ChangeSupplierBasePrice::class)->handle($this->staff, $this->offer, Money::fromDecimal('1000.00', Currency::BDT), 'Costs went up.');

    $payable = SupplierPayable::query()->sole();

    expect($this->offer->refresh()->supplier_rate->toDecimal())->toBe('1000.00')
        ->and($allocation->refresh()->unit_cost->toDecimal())->toBe('850.00')
        ->and($payable->supplier_rate->toDecimal())->toBe('850.00')
        ->and($payable->gross_amount->toDecimal())->toBe('2550.00');

    // The next line is offered at the new price.
    $candidate = collect(app(AllocationSourceCandidates::class)->forLine($this->line))
        ->first(fn ($candidate) => $candidate->sourceId === $this->offer->public_id);

    expect($candidate->unitCost->toDecimal())->toBe('1000.00');
});
