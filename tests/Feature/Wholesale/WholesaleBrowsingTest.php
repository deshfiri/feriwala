<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
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
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Wholesale browsing inside the ERP (P4-1, §13).
 *
 * Search, category and brand filters shipped with the business catalogue
 * (P3-10); this adds stock and price filters and each card's stock state. Stock
 * is what this account can order — available in an active warehouse, or
 * allocated to the account itself — never another account's allocation, never a
 * quantity, a warehouse or an allocation on the card. Eligibility still decides
 * what is listed at all.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = wholesaleBrowsePackage();
    $this->karim = wholesaleBrowseAccount($this->package);
    $this->rahim = wholesaleBrowseAccount($this->package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->sylhet = Warehouse::create(['code' => 'SYL', 'name' => 'Sylhet']);

    // Shared stock.
    $this->kettle = wholesaleBrowseProduct('FW-KT', 'Electric kettle', ['wholesale_price_minor' => 200000]);
    wholesaleBrowseStock($this->kettle, null, $this->dhaka, 5);

    // Never brought into inventory.
    $this->pan = wholesaleBrowseProduct('FW-PAN', 'Frying pan', ['wholesale_price_minor' => 350000]);

    // Only stock allocated to Karim.
    $this->pot = wholesaleBrowseProduct('FW-POT', 'Steel pot', ['wholesale_price_minor' => 120000]);
    $potItem = wholesaleBrowseStock($this->pot, null, $this->dhaka, 3);
    app(StockAllocations::class)->allocate($potItem, $this->karim, 3);

    // Stock only on a variation that is switched off.
    $this->shirt = wholesaleBrowseProduct('FW-SH', 'Polo shirt', ['wholesale_price_minor' => 90000]);
    $medium = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-M', 'combination_key' => 'm', 'is_active' => false]);
    $large = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-L', 'combination_key' => 'l', 'is_active' => true]);
    wholesaleBrowseStock($this->shirt, $medium, $this->dhaka, 10);
    wholesaleBrowseStock($this->shirt, $large, $this->dhaka, 0);

    // Stock only in a warehouse that is switched off.
    $this->offsite = wholesaleBrowseProduct('FW-OFF', 'Off-site kettle', ['wholesale_price_minor' => 210000]);
    wholesaleBrowseStock($this->offsite, null, $this->sylhet, 8);
    $this->sylhet->forceFill(['is_active' => false])->save();

    // In stock, but offered to another package.
    $this->exclusive = wholesaleBrowseProduct('FW-EXCL', 'Exclusive kettle', ['package_scope' => PackageScope::SelectedPackages]);
    $this->exclusive->eligiblePackages()->attach(wholesaleBrowsePackage()->id);
    wholesaleBrowseStock($this->exclusive, null, $this->dhaka, 9);
});

function wholesaleBrowsePackage(): Package
{
    return Package::create([
        'slug' => 'wholesale-'.Str::lower(Str::random(8)),
        'name' => 'Wholesale package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleBrowseAccount(Package $package): BusinessAccount
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
function wholesaleBrowseProduct(string $sku, string $name, array $attributes = []): Product
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

function wholesaleBrowseStock(Product $product, ?ProductVariant $variant, Warehouse $warehouse, int $units): StockItem
{
    $item = StockItem::create([
        'warehouse_id' => $warehouse->id,
        'product_id' => $product->id,
        'product_variant_id' => $variant?->id,
    ]);

    if ($units > 0) {
        app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);
    }

    return $item;
}

/**
 * @return array<string, bool>
 */
function wholesaleBrowseStates(Collection $cards): array
{
    return $cards->mapWithKeys(fn (array $card) => [$card['sku'] => $card['in_stock']])->sortKeys()->all();
}

describe('stock on the wholesale catalogue', function () {
    it('marks each card in stock or out of stock, from stock this account can order, and nothing more', function () {
        $this->actingAs($this->karim->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 5)
                ->where('products.data', fn (Collection $cards) => wholesaleBrowseStates($cards) === [
                    'FW-KT' => true,
                    'FW-OFF' => false,
                    'FW-PAN' => false,
                    'FW-POT' => true,
                    'FW-SH' => false,
                ])
                ->where('products.data', fn (Collection $cards) => $cards->every(fn (array $card) => ! array_key_exists('quantity', $card)
                    && ! array_key_exists('warehouse', $card)
                    && ! array_key_exists('allocated', $card)
                    && ! array_key_exists('base_cost', $card))));
    });

    it('counts only the reader\'s own allocation', function () {
        $this->actingAs($this->rahim->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn (Collection $cards) => wholesaleBrowseStates($cards)['FW-POT'] === false));
    });

    it('filters by stock in the database, and ignores a filter it does not know', function () {
        $owner = $this->karim->owner;

        $this->actingAs($owner)
            ->get(route('catalog.wholesale.index', ['stock' => 'in_stock']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.stock', 'in_stock')
                ->where('products.data', fn (Collection $cards) => $cards->pluck('sku')->sort()->values()->all() === ['FW-KT', 'FW-POT']));

        $this->actingAs($owner)
            ->get(route('catalog.wholesale.index', ['stock' => 'out_of_stock']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn (Collection $cards) => $cards->pluck('sku')->sort()->values()->all() === ['FW-OFF', 'FW-PAN', 'FW-SH']));

        $this->actingAs($this->rahim->owner)
            ->get(route('catalog.wholesale.index', ['stock' => 'in_stock']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data', fn (Collection $cards) => $cards->pluck('sku')->all() === ['FW-KT']));

        $this->actingAs($owner)
            ->get(route('catalog.wholesale.index', ['stock' => 'plenty']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.stock', null)->has('products.data', 5));
    });

    it('never lists a product outside the account\'s eligibility, whatever stock it holds', function () {
        $this->actingAs($this->karim->owner)
            ->get(route('catalog.wholesale.index', ['stock' => 'in_stock', 'search' => 'FW-EXCL']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    });
});

describe('price on the wholesale catalogue', function () {
    it('filters by wholesale price in taka, both bounds inclusive', function () {
        $this->actingAs($this->karim->owner)
            ->get(route('catalog.wholesale.index', ['price_min' => '2000', 'price_max' => '3500.00']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.price_min', Money::of(200000, Currency::BDT)->toDecimal())
                ->where('filters.price_max', Money::of(350000, Currency::BDT)->toDecimal())
                ->where('products.data', fn (Collection $cards) => $cards->pluck('sku')->sort()->values()->all() === ['FW-KT', 'FW-OFF', 'FW-PAN']));
    });

    it('ignores a bound that is not an amount rather than guessing one', function (string $bound) {
        $this->actingAs($this->karim->owner)
            ->get(route('catalog.wholesale.index', ['price_min' => $bound, 'price_max' => $bound]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.price_min', null)
                ->where('filters.price_max', null)
                ->has('products.data', 5));
    })->with([
        'text' => ['cheap'],
        'negative' => ['-5'],
        'too many decimals' => ['12.345'],
        'absurdly large' => ['99999999999'],
    ]);
});

it('keeps stock and price out of the dropshipping catalogue', function () {
    $hose = wholesaleBrowseProduct('FW-HOSE', 'Garden hose', [
        'wholesale_status' => ProductStatus::WholesaleDisabled,
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
    ]);
    wholesaleBrowseStock($hose, null, $this->dhaka, 4);

    $this->actingAs($this->karim->owner)
        ->get(route('catalog.dropshipping.index', ['stock' => 'out_of_stock', 'price_max' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.stock', null)
            ->where('filters.price_max', null)
            ->has('products.data', 1)
            ->missing('products.data.0.in_stock')
            ->missing('products.data.0.wholesale_price'));
});
