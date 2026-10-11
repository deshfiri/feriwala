<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\ConfirmProductSourceLink;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Order\Queries\AllocationSourceCandidates;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

/*
 * Confirmed source links are legacy history. Allocation offers only sources for
 * exactly the ordered Product and variation — its Supplier Offers and its
 * warehouse stock — and never anything catalogued under another Product or BPC,
 * whether or not an old link says it is related.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
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

    // This Product's own sources.
    $this->ownOffer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        $this->product,
        supplierRate: '900.00',
    );
    supplierTestOfferPriceVersion($this->ownOffer);

    $this->ownWarehouse = Warehouse::create(['name' => 'Dhaka Central', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
    StockItem::create(['warehouse_id' => $this->ownWarehouse->id, 'product_id' => $this->product->id, 'product_variant_id' => null, 'available' => 10]);

    // Another Product / BPC, with its own offer and stock.
    $this->otherProduct = websiteTestProduct();
    $this->otherOffer = supplierTestOffer(
        Supplier::factory()->create(['status' => SupplierStatus::Approved]),
        $this->otherProduct,
        supplierRate: '650.00',
    );
    supplierTestOfferPriceVersion($this->otherOffer);

    $this->otherWarehouse = Warehouse::create(['name' => 'Chattogram Depot', 'code' => 'CTG-'.random_int(100, 999), 'is_active' => true]);
    $this->otherStockItem = StockItem::create(['warehouse_id' => $this->otherWarehouse->id, 'product_id' => $this->otherProduct->id, 'product_variant_id' => null, 'available' => 10]);
});

function sourceLinkCandidateIds($test): array
{
    return collect(app(AllocationSourceCandidates::class)->forLine($test->line->refresh()))
        ->map(fn ($candidate) => $candidate->sourceType->value.':'.$candidate->sourceId)
        ->all();
}

it('offers exactly this Product\'s Supplier Offer and warehouse, and nothing from another BPC', function () {
    $ids = sourceLinkCandidateIds($this);

    expect($ids)->toContain('supplier_offer:'.$this->ownOffer->public_id)
        ->and($ids)->toContain('warehouse:'.$this->ownWarehouse->public_id)
        ->and($ids)->not->toContain('supplier_offer:'.$this->otherOffer->public_id)
        ->and($ids)->not->toContain('warehouse:'.$this->otherWarehouse->public_id)
        ->and($ids)->toHaveCount(2);
});

it('no longer turns an old confirmed source link into candidates', function () {
    // A link written while the mechanism was live: the other Product's offer and
    // stock were confirmed as sources for this one.
    $confirm = app(ConfirmProductSourceLink::class);
    $confirm->handle($this->product, null, AllocationSourceType::SupplierOffer, $this->otherOffer->public_id, $this->staff, 'Confirmed while the mechanism was live.');
    $confirm->handle($this->product, null, AllocationSourceType::Warehouse, $this->otherStockItem->public_id, $this->staff, 'Confirmed while the mechanism was live.');

    expect(ProductSourceLink::query()->count())->toBe(2);

    $ids = sourceLinkCandidateIds($this);

    expect($ids)->not->toContain('supplier_offer:'.$this->otherOffer->public_id)
        ->and($ids)->not->toContain('warehouse:'.$this->otherWarehouse->public_id)
        ->and($ids)->toHaveCount(2)
        // The history itself is left exactly as it was.
        ->and(ProductSourceLink::query()->count())->toBe(2);
});

it('searches only within this line\'s own sources', function () {
    $this->actingAs($this->staff)
        ->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id]))
        ->assertOk()
        ->assertJsonCount(2, 'candidates');

    $found = collect($this->getJson(route('admin.orders.lines.sources.search', [$this->order->public_id, $this->line->public_id, 'source_type' => 'supplier_offer']))->json('candidates'))
        ->pluck('source_id')->all();

    expect($found)->toBe([$this->ownOffer->public_id]);
});

it('has no route left for confirming a cross-product source link', function () {
    expect(Route::has('admin.orders.lines.sources.confirm-link'))->toBeFalse();
});

it('refuses to allocate to a source catalogued under another Product', function () {
    expect(fn () => app(AllocateOrderLineSource::class)->handle(
        $this->line->refresh(),
        AllocationSourceType::SupplierOffer,
        $this->otherOffer->public_id,
        $this->staff,
        'Trying another Product\'s Supplier.',
    ))->toThrow(Exception::class);

    expect(SupplierPayable::query()->count())->toBe(0);
});
