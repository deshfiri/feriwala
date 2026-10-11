<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Actions\AssignProductToSupplier;
use App\Domain\Supplier\Actions\ChangeSupplierBasePrice;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * One Central Product, one BPC, many Supplier Offers. Allocation lists every
 * eligible offer for exactly that Product (and exact variation), alongside the
 * warehouse, and staff choose — nothing is picked for them.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->order = Order::factory()->create();
    $this->product = websiteTestProduct();
    $this->product->forceFill(['wholesale_price' => Money::fromDecimal('1200.00', Currency::BDT)])->save();
    $this->otherProduct = websiteTestProduct();
});

function offersPerProductLine($test, $product, ?ProductVariant $variant = null, int $quantity = 3): OrderItem
{
    return OrderItem::create([
        'order_id' => $test->order->id,
        'line_number' => OrderItem::query()->where('order_id', $test->order->id)->count() + 1,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
        'sku' => $variant?->sku ?? $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1300.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal((string) (1300 * $quantity).'.00', Currency::BDT),
        'line_total' => Money::fromDecimal((string) (1300 * $quantity).'.00', Currency::BDT),
        'created_at' => now(),
    ]);
}

function offersPerProductAssign($test, $product, ?ProductVariant $variant, string $price, ?Supplier $supplier = null): SupplierOffer
{
    return app(AssignProductToSupplier::class)->handle(
        $test->staff,
        $product,
        $variant,
        $supplier ?? Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        Money::fromDecimal($price, Currency::BDT),
        ['quantity' => 50],
    );
}

function offersPerProductCandidates($line): array
{
    return app(AllocationSourceCandidates::class)->forLine($line);
}

it('lists all ten Suppliers of one Product once each, with independent base prices, and no other BPC', function () {
    $offers = collect(range(0, 9))->map(fn (int $n) => offersPerProductAssign($this, $this->product, null, (string) (390 + $n * 10).'.00'));
    offersPerProductAssign($this, $this->otherProduct, null, '300.00');

    $candidates = collect(offersPerProductCandidates(offersPerProductLine($this, $this->product)))
        ->where('sourceType', AllocationSourceType::SupplierOffer);

    expect($candidates)->toHaveCount(10)
        ->and($candidates->pluck('sourceId')->unique())->toHaveCount(10)
        ->and($candidates->pluck('sourceId')->sort()->values()->all())->toBe($offers->pluck('public_id')->sort()->values()->all())
        ->and($candidates->every(fn ($candidate) => $candidate->isEligible))->toBeTrue()
        ->and($candidates->pluck('sourceProductBpc')->unique()->all())->toBe([$this->product->sku])
        ->and($candidates->pluck('unitCost')->map->toDecimal()->sort()->values()->all())
        ->toBe(collect(range(0, 9))->map(fn (int $n) => (string) (390 + $n * 10).'.00')->all())
        // Payable shown per candidate is its own price x the line quantity.
        ->and($candidates->first(fn ($c) => $c->unitCost->toDecimal() === '390.00')->expectedPayable->toDecimal())->toBe('1170.00');
});

it('matches the exact ordered variation and no other', function () {
    $small = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'VAR-S', 'combination_key' => 's', 'is_active' => true]);
    $large = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'VAR-L', 'combination_key' => 'l', 'is_active' => true]);

    $forSmall = offersPerProductAssign($this, $this->product, $small, '400.00');
    offersPerProductAssign($this, $this->product, $large, '410.00');

    $ids = collect(offersPerProductCandidates(offersPerProductLine($this, $this->product, $small)))
        ->where('sourceType', AllocationSourceType::SupplierOffer)
        ->pluck('sourceId')->all();

    expect($ids)->toBe([$forSmall->public_id]);
});

it('shows the warehouse and every Supplier together, and lets staff choose any eligible one', function () {
    $warehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'product_variant_id' => null, 'available' => 10]);

    $cheap = offersPerProductAssign($this, $this->product, null, '390.00');
    $dear = offersPerProductAssign($this, $this->product, null, '420.00');

    $line = offersPerProductLine($this, $this->product);
    $candidates = collect(offersPerProductCandidates($line));

    expect($candidates->where('sourceType', AllocationSourceType::Warehouse))->toHaveCount(1)
        ->and($candidates->where('sourceType', AllocationSourceType::SupplierOffer))->toHaveCount(2);

    // Nothing is chosen by listing them, and the dearer one can be picked.
    expect(SupplierPayable::query()->count())->toBe(0);

    $allocation = app(AllocateOrderLineSource::class)->handle($line->refresh(), AllocationSourceType::SupplierOffer, $dear->public_id, $this->staff, 'Staff prefer this Supplier.');

    expect($allocation->supplier_offer_id)->toBe($dear->id)
        ->and($allocation->unit_cost->toDecimal())->toBe('420.00')
        ->and(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('1260.00')
        ->and($cheap->refresh()->stock->reserved_quantity)->toBe(0);
});

it('keeps a historical payable at the snapshotted price when the base price changes', function () {
    $offer = offersPerProductAssign($this, $this->product, null, '400.00');
    $line = offersPerProductLine($this, $this->product);

    app(AllocateOrderLineSource::class)->handle($line->refresh(), AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Chosen.');

    app(ChangeSupplierBasePrice::class)->handle($this->staff, $offer, Money::fromDecimal('450.00', Currency::BDT), 'Costs rose.');

    expect(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('1200.00')
        ->and(SupplierPayable::query()->sole()->supplier_rate->toDecimal())->toBe('400.00');
});

it('accepts a base price above the platform rate, leaves every platform price alone, and still allocates it', function () {
    $before = $this->product->only(['wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price']);

    $offer = offersPerProductAssign($this, $this->product, null, '1500.00');

    expect($offer->supplier_rate->toDecimal())->toBe('1500.00')
        // The platform rate is the wholesale price as it stands, not raised to fit.
        ->and($offer->platform_rate->toDecimal())->toBe('1200.00')
        ->and(app(AssignProductToSupplier::class)->hasNoMargin($this->product, null, $offer->supplier_rate))->toBeTrue();

    $line = offersPerProductLine($this, $this->product);
    app(AllocateOrderLineSource::class)->handle($line->refresh(), AllocationSourceType::SupplierOffer, $offer->public_id, $this->staff, 'Chosen despite the lost margin.');

    expect(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('4500.00');

    // A later price change never moves the platform rate or the Product's prices.
    app(ChangeSupplierBasePrice::class)->handle($this->staff, $offer->refresh(), Money::fromDecimal('1600.00', Currency::BDT), 'Costs rose.');

    expect($offer->refresh()->platform_rate->toDecimal())->toBe('1200.00')
        ->and($this->product->refresh()->only(['wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price']))->toEqual($before)
        ->and(SupplierPayable::query()->sole()->gross_amount->toDecimal())->toBe('4500.00');
});
