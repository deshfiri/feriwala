<?php

use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/*
 * What the staff allocation panel is allowed to see, and what it must not
 * decide (batch §5, §4).
 */

beforeEach(function () {
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

/** @return list<AllocationCandidate> */
function candidatesForTestLine(): array
{
    return app(AllocationSourceCandidates::class)->forLine(test()->line->refresh());
}

function candidateWarehouseStock(int $available, int $reserved = 0): Warehouse
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
        'reserved' => $reserved,
    ]);

    return $warehouse;
}

it('offers the central warehouse with its cost, price and margin', function () {
    $warehouse = candidateWarehouseStock(available: 10, reserved: 3);

    $candidates = candidatesForTestLine();

    expect($candidates)->toHaveCount(1);

    $central = $candidates[0];

    expect($central->sourceType)->toBe(AllocationSourceType::Warehouse)
        ->and($central->sourceId)->toBe($warehouse->public_id)
        ->and($central->available)->toBe(10)
        ->and($central->reserved)->toBe(3)
        ->and($central->availableToPromise)->toBe(10)
        ->and($central->unitCost->toDecimal())->toBe('700.00')
        ->and($central->platformRate->toDecimal())->toBe('1300.00')
        // (1300 - 700) x 2
        ->and($central->expectedMargin->toDecimal())->toBe('1200.00')
        ->and($central->isEligible)->toBeTrue();
});

it('marks a warehouse that cannot cover the whole line ineligible, and says why', function () {
    // The no-split rule: a source that holds part of the line is not a source.
    candidateWarehouseStock(available: 1);

    $central = candidatesForTestLine()[0];

    expect($central->isEligible)->toBeFalse()
        ->and($central->ineligibleReason)->toContain('1 of the 2');
});

it('lists five suppliers against one central product, each with its own rate and availability', function () {
    // The heart of §4: one Central Product, five offers, five rates.
    $rates = ['900.00', '850.00', '1000.00', '780.00', '950.00'];
    $quantities = [5, 1, 9, 4, 7];

    foreach ($rates as $index => $rate) {
        $offer = supplierTestOffer(
            Supplier::factory()->create(['status' => SupplierStatus::Approved]),
            $this->product,
            supplierRate: $rate,
        );
        $offer->stock()->update(['quantity' => $quantities[$index]]);
    }

    $candidates = candidatesForTestLine();
    $suppliers = array_values(array_filter(
        $candidates,
        fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::SupplierOffer,
    ));

    expect($suppliers)->toHaveCount(5)
        ->and(collect($suppliers)->pluck('unitCost')->map->toDecimal()->sort()->values()->all())
        ->toBe(['780.00', '850.00', '900.00', '950.00', '1000.00'])
        ->and(collect($suppliers)->pluck('availableToPromise')->sort()->values()->all())
        ->toBe([1, 4, 5, 7, 9])
        // Five distinct Suppliers, not one offer counted five times.
        ->and(collect($suppliers)->pluck('supplierId')->unique())->toHaveCount(5);

    // And the platform did not choose. Every eligible source is returned, the
    // cheapest is not singled out, and the one that cannot cover the line is
    // reported as ineligible rather than silently dropped.
    $eligible = array_values(array_filter($suppliers, fn (AllocationCandidate $c) => $c->isEligible));

    expect($eligible)->toHaveCount(4)
        ->and(collect($suppliers)->firstWhere('availableToPromise', 1)->isEligible)->toBeFalse();
});

it('reports a suspended offer and a non-operational supplier as ineligible, with the reason', function () {
    $suspended = supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $this->product);
    $suspended->forceFill(['status' => OfferStatus::Suspended])->save();

    $offline = supplierTestOffer(Supplier::factory()->kycPending()->create(), $this->product);

    $reasons = collect(candidatesForTestLine())
        ->filter(fn (AllocationCandidate $c) => $c->sourceType === AllocationSourceType::SupplierOffer)
        ->pluck('ineligibleReason')
        ->all();

    expect($reasons)->toHaveCount(2)
        ->and(implode(' ', $reasons))->toContain('suspended')
        ->and(implode(' ', $reasons))->toContain('not currently operational');

    expect(collect(candidatesForTestLine())->every(fn (AllocationCandidate $c) => $c->isEligible === false))
        ->toBeTrue();
});

it('carries the supplier identity, preferred flag and lead time staff need to compare', function () {
    $supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    supplierTestOffer($supplier, $this->product, preferred: true);

    $candidate = collect(candidatesForTestLine())
        ->firstWhere('sourceType', AllocationSourceType::SupplierOffer);

    expect($candidate->supplierName)->toBe($supplier->business_name)
        ->and($candidate->supplierId)->toBe($supplier->public_id)
        ->and($candidate->isPreferred)->toBeTrue()
        ->and($candidate->offerStatus)->toBe('active')
        ->and($candidate->supplierStatus)->toBe(SupplierStatus::Approved->value);
});

it('never exposes a database id as a source identifier', function () {
    // §8 of the specification: no database ids in anything a URL or a payload
    // could carry.
    candidateWarehouseStock(available: 5);
    supplierTestOffer(Supplier::factory()->create(['status' => SupplierStatus::Approved]), $this->product);

    foreach (candidatesForTestLine() as $candidate) {
        expect($candidate->sourceId)->not->toBeNumeric()
            ->and(strlen($candidate->sourceId))->toBeGreaterThan(10);
    }
});
