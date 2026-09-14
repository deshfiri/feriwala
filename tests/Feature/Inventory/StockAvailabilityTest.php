<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Central stock shared across every website (P3-27, §19.1, contract §5.2).
 *
 * One answer per SKU, the same for every website: the available bucket summed
 * across active warehouses, in the contract's shape and nothing more — never a
 * warehouse, never a reservation, never another bucket.
 */

beforeEach(function () {
    $category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $this->shirt = Product::create(['name' => 'Panjabi', 'sku' => 'FW-PJ', 'category_id' => $category->id]);
    $this->medium = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);
    $this->large = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-PJ-L', 'combination_key' => 'l']);

    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram']);

    $this->availability = app(StockAvailability::class);
});

function availabilityStock(Warehouse $warehouse, Product $product, ?ProductVariant $variant, int $available): StockItem
{
    $item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => $variant?->id]);

    if ($available > 0) {
        app(StockLedger::class)->move($item, null, StockBucket::Available, $available, StockMovementType::Adjustment);
    }

    return $item->refresh();
}

it('sums the available bucket across active warehouses, and only that bucket', function () {
    $dhaka = availabilityStock($this->dhaka, $this->kettle, null, 10);
    availabilityStock($this->chattogram, $this->kettle, null, 5);

    // Units that are not free to sell do not count.
    app(StockLedger::class)->move($dhaka, StockBucket::Available, StockBucket::Damaged, 2, StockMovementType::Adjustment);
    app(StockReservations::class)->reserve($this->kettle, null, 3, ReservationKind::OnlinePayment, 'ORD-1');

    // A switched-off warehouse is not somewhere an order can be filled from.
    $closed = Warehouse::create(['code' => 'OLD', 'name' => 'Old depot', 'is_active' => false]);
    availabilityStock($closed, $this->kettle, null, 100);

    $answer = $this->availability->forSkus(['FW-KT'])['FW-KT'];

    expect($answer['quantity'])->toBe(10)
        ->and($answer['in_stock'])->toBeTrue()
        ->and($answer['updated_at'])->not->toBeNull();
});

it('answers in the contract shape and nothing more — no warehouse, no reservation, no other bucket', function () {
    availabilityStock($this->dhaka, $this->kettle, null, 4);
    app(StockReservations::class)->reserve($this->kettle, null, 1, ReservationKind::CashOnDelivery, 'ORD-2');

    expect(array_keys($this->availability->forSkus(['FW-KT'])['FW-KT']))->toBe(['sku', 'in_stock', 'quantity', 'updated_at']);
});

it('reports variations per SKU, a unit with no stock as out of stock, and omits SKUs that are not stockable units', function () {
    availabilityStock($this->dhaka, $this->shirt, $this->medium, 6);

    $answers = $this->availability->forSkus(['fw-pj-m', 'FW-PJ-L', 'FW-PJ', 'NOT-A-SKU', 'FW-KT']);

    expect(array_keys($answers))->toEqualCanonicalizing(['FW-PJ-M', 'FW-PJ-L', 'FW-KT'])
        ->and($answers['FW-PJ-M'])->toMatchArray(['in_stock' => true, 'quantity' => 6])
        ->and($answers['FW-PJ-L'])->toMatchArray(['in_stock' => false, 'quantity' => 0, 'updated_at' => null])
        ->and($answers['FW-KT'])->toMatchArray(['in_stock' => false, 'quantity' => 0]);
});

it('gives every unit of a product in order, and says whether the product has stock at all', function () {
    availabilityStock($this->dhaka, $this->shirt, $this->large, 2);

    expect(collect($this->availability->forProduct($this->shirt))->pluck('quantity', 'sku')->all())
        ->toBe(['FW-PJ-M' => 0, 'FW-PJ-L' => 2])
        ->and($this->availability->productHasStock($this->shirt))->toBeTrue()
        ->and($this->availability->productHasStock($this->kettle))->toBeFalse();
});

describe('the website availability screen', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        availabilityStock($this->dhaka, $this->kettle, null, 7);
        availabilityStock($this->chattogram, $this->kettle, null, 1);
    });

    it('shows each product with the answer every website receives for its SKUs', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.availability.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/availability')
                ->has('products.data', 2)
                ->where('products.data.0.name', 'Kettle')
                ->where('products.data.0.units.0', fn ($unit) => collect($unit)->only(['sku', 'in_stock', 'quantity'])->all() === ['sku' => 'FW-KT', 'in_stock' => true, 'quantity' => 8])
                ->where('products.data.1.units', fn ($units) => collect($units)->pluck('sku')->all() === ['FW-PJ-M', 'FW-PJ-L']));
    });

    it('filters by stock state and SKU in the database', function () {
        $viewer = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($viewer)
            ->get(route('admin.inventory.availability.index', ['state' => 'out_of_stock']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.name', 'Panjabi'));

        $this->actingAs($viewer)
            ->get(route('admin.inventory.availability.index', ['search' => 'pj-l']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.sku', 'FW-PJ'));
    });

    it('refuses a partner and staff without inventory', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->get(route('admin.inventory.availability.index'))
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.inventory.availability.index'))
            ->assertForbidden();
    });
});
