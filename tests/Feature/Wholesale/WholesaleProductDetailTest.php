<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The wholesale product page (P4-2, §13).
 *
 * Variations, the wholesale price, quantity pricing, minimum and maximum order
 * and related products shipped with the business catalogue (P3-10, P3-11); this
 * adds stock: how many this account can order of the product, or of each active
 * variation, with each variation's own price and quantity bands resolved by the
 * server. Never the cost, a warehouse, or anybody else's allocation.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $package = wholesaleDetailPackage();
    $this->karim = wholesaleDetailAccount($package);
    $this->rahim = wholesaleDetailAccount($package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // Twelve units shared, and three more allocated to Karim.
    $this->kettle = wholesaleDetailProduct('FW-KT', 'Electric kettle', ['min_order_quantity' => 6, 'max_order_quantity' => 60]);
    ProductPriceTier::create(['product_id' => $this->kettle->id, 'min_quantity' => 24, 'unit_price_minor' => 180000]);
    $kettleItem = wholesaleDetailStock($this->kettle, null, 15);
    app(StockAllocations::class)->allocate($kettleItem, $this->karim, 3);

    // Variations: one with its own price, bands and stock; one with no stock;
    // one switched off with stock that must not show.
    $this->shirt = wholesaleDetailProduct('FW-SH', 'Polo shirt', ['wholesale_price_minor' => 100000]);
    $medium = ProductVariant::create([
        'product_id' => $this->shirt->id,
        'sku' => 'FW-SH-M',
        'combination_key' => 'm',
        'wholesale_price_minor' => 95000,
        'currency_code' => 'BDT',
        'is_active' => true,
    ]);
    $large = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-L', 'combination_key' => 'l', 'is_active' => true]);
    $small = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-S', 'combination_key' => 's', 'is_active' => false]);
    ProductPriceTier::create(['product_id' => $this->shirt->id, 'product_variant_id' => $medium->id, 'min_quantity' => 10, 'unit_price_minor' => 85000]);
    wholesaleDetailStock($this->shirt, $medium, 6);
    wholesaleDetailStock($this->shirt, $large, 0);
    wholesaleDetailStock($this->shirt, $small, 9);
});

function wholesaleDetailPackage(): Package
{
    return Package::create([
        'slug' => 'detail-'.Str::lower(Str::random(8)),
        'name' => 'Detail package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleDetailAccount(Package $package): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return $account->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function wholesaleDetailProduct(string $sku, string $name, array $attributes = []): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => test()->kitchen->id,
        'base_cost_minor' => 50000,
        'wholesale_price_minor' => 200000,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
        ...$attributes,
    ]);
}

function wholesaleDetailStock(Product $product, ?ProductVariant $variant, int $units): StockItem
{
    $item = StockItem::create([
        'warehouse_id' => test()->dhaka->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
    ]);

    if ($units > 0) {
        app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);
    }

    return $item;
}

it('shows how many of a product without variations this account can order, counting only its own allocation', function () {
    $this->actingAs($this->karim->owner)
        ->get(route('catalog.wholesale.show', $this->kettle->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.in_stock', true)
            ->where('product.available', 15)
            ->where('product.min_order_quantity', 6)
            ->where('product.max_order_quantity', 60)
            ->where('product.quantity_pricing.0.min_quantity', 24)
            ->where('product.quantity_pricing.0.unit_price.minor_units', 180000));

    $this->actingAs($this->rahim->owner)
        ->get(route('catalog.wholesale.show', $this->kettle->slug))
        ->assertInertia(fn (Assert $page) => $page->where('product.available', 12));
});

it('shows each active variation with its own price, quantity pricing and stock, and never a switched-off one', function () {
    $this->actingAs($this->karim->owner)
        ->get(route('catalog.wholesale.show', $this->shirt->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.in_stock', true)
            ->where('product.available', null)
            ->has('product.variants', 2)
            ->where('product.variants', function (Collection $variants) {
                $bySku = $variants->keyBy('sku');

                return $bySku->keys()->sort()->values()->all() === ['FW-SH-L', 'FW-SH-M']
                    && $bySku['FW-SH-M']['wholesale_price']['minor_units'] === 95000
                    && $bySku['FW-SH-M']['in_stock'] === true
                    && $bySku['FW-SH-M']['available'] === 6
                    && $bySku['FW-SH-M']['quantity_pricing'] === [[
                        'min_quantity' => 10,
                        'unit_price' => $bySku['FW-SH-M']['quantity_pricing'][0]['unit_price'],
                    ]]
                    && $bySku['FW-SH-M']['quantity_pricing'][0]['unit_price']['minor_units'] === 85000
                    && $bySku['FW-SH-L']['in_stock'] === false
                    && $bySku['FW-SH-L']['available'] === 0
                    && $bySku['FW-SH-L']['quantity_pricing'] === [];
            }));
});

it('never carries the cost, a warehouse or an allocation on the product page', function () {
    $this->actingAs($this->karim->owner)
        ->get(route('catalog.wholesale.show', $this->shirt->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->missing('product.base_cost')
            ->missing('product.warehouse')
            ->missing('product.allocated')
            ->where('product.variants', fn (Collection $variants) => $variants->every(fn (array $variant) => ! array_key_exists('base_cost', $variant)
                && ! array_key_exists('warehouse', $variant)
                && ! array_key_exists('allocated', $variant))));
});

it('marks related products in stock or not, and never shows one the account cannot open', function () {
    $exclusive = wholesaleDetailProduct('FW-EXCL', 'Exclusive kettle', ['package_scope' => PackageScope::SelectedPackages]);
    $exclusive->eligiblePackages()->attach(wholesaleDetailPackage()->id);
    wholesaleDetailStock($exclusive, null, 5);

    $this->kettle->relatedProducts()->attach([
        $this->shirt->id => ['position' => 1],
        $exclusive->id => ['position' => 2],
    ]);

    $this->actingAs($this->karim->owner)
        ->get(route('catalog.wholesale.show', $this->kettle->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->has('related', 1)
            ->where('related.0.sku', 'FW-SH')
            ->where('related.0.in_stock', true));
});

it('keeps stock off the dropshipping product page', function () {
    $hose = wholesaleDetailProduct('FW-HOSE', 'Garden hose', [
        'wholesale_status' => ProductStatus::WholesaleDisabled,
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
    ]);
    wholesaleDetailStock($hose, null, 4);

    $this->actingAs($this->karim->owner)
        ->get(route('catalog.dropshipping.show', $hose->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('product.in_stock')
            ->missing('product.available'));
});
