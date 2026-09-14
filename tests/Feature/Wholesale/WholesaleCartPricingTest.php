<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wholesale\Actions\SetCartLine;
use App\Domain\Wholesale\Data\CartLineQuote;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Pricing the wholesale cart on the server, every time (P4-5, §14, §36.1).
 *
 * Each line is priced at its quantity with the quantity pricing in force now and
 * each variation's own price. A price that has changed since the person last saw
 * it is pointed out and keeps checkout closed until they accept it. A line that
 * can no longer be bought — the product withdrawn, the variation switched off, a
 * raised minimum, stock gone to somebody else's order — says why and stays out of
 * the subtotal.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $package = wholesalePricingPackage();
    $this->karim = wholesalePricingAccount($package);
    $this->rahim = wholesalePricingAccount($package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->kettle = wholesalePricingProduct('FW-KT', 'Electric kettle', ['min_order_quantity' => 6]);
    ProductPriceTier::create(['product_id' => $this->kettle->id, 'min_quantity' => 24, 'unit_price_minor' => 180000]);
    wholesalePricingStock($this->kettle, null, 100);

    $this->shirt = wholesalePricingProduct('FW-SH', 'Polo shirt', ['wholesale_price_minor' => 100000]);
    $this->medium = ProductVariant::create([
        'product_id' => $this->shirt->id,
        'sku' => 'FW-SH-M',
        'combination_key' => 'm',
        'wholesale_price_minor' => 95000,
        'currency_code' => 'BDT',
        'is_active' => true,
    ]);
    ProductPriceTier::create(['product_id' => $this->shirt->id, 'product_variant_id' => $this->medium->id, 'min_quantity' => 10, 'unit_price_minor' => 85000]);
    wholesalePricingStock($this->shirt, $this->medium, 30);

    $this->cup = wholesalePricingProduct('FW-CUP', 'Tea cup', ['wholesale_price_minor' => 15000]);
    wholesalePricingStock($this->cup, null, 50);

    $this->lid = wholesalePricingProduct('FW-LID', 'Pot lid', ['wholesale_price_minor' => 30000]);
    wholesalePricingStock($this->lid, null, 50);

    $this->pot = wholesalePricingProduct('FW-POT', 'Steel pot', ['wholesale_price_minor' => 120000]);
    app(StockAllocations::class)->allocate(wholesalePricingStock($this->pot, null, 5), $this->karim, 5);
});

function wholesalePricingPackage(): Package
{
    return Package::create([
        'slug' => 'pricing-'.Str::lower(Str::random(8)),
        'name' => 'Pricing package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesalePricingAccount(Package $package): BusinessAccount
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
function wholesalePricingProduct(string $sku, string $name, array $attributes = []): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => test()->kitchen->id,
        'base_cost_minor' => 5000,
        'wholesale_price_minor' => 200000,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
        ...$attributes,
    ]);
}

function wholesalePricingStock(Product $product, ?ProductVariant $variant, int $units): StockItem
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

function wholesalePricingLine(BusinessAccount $account, Product $product, ?ProductVariant $variant, int $quantity): void
{
    app(SetCartLine::class)->handle($account->owner, $account, $product, $variant, $quantity);
}

/**
 * @return Collection<string, array<string, mixed>>
 */
function wholesalePricingBySku(Collection $lines): Collection
{
    return $lines->keyBy(fn (array $line) => $line['variant']['sku'] ?? $line['product']['sku']);
}

it('prices each line at its quantity, with the quantity pricing and variation price in force', function () {
    wholesalePricingLine($this->karim, $this->kettle, null, 30);
    wholesalePricingLine($this->karim, $this->shirt, $this->medium, 12);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cart.lines', 2)
            ->where('cart.lines', function (Collection $lines) {
                $bySku = wholesalePricingBySku($lines);

                return $bySku['FW-KT']['unit_price']['minor_units'] === 180000
                    && $bySku['FW-KT']['base_price']['minor_units'] === 200000
                    && $bySku['FW-KT']['line_total']['minor_units'] === 5400000
                    && $bySku['FW-SH-M']['unit_price']['minor_units'] === 85000
                    && $bySku['FW-SH-M']['base_price']['minor_units'] === 95000
                    && $bySku['FW-SH-M']['line_total']['minor_units'] === 1020000;
            })
            ->where('cart.subtotal.minor_units', 6420000)
            ->where('cart.has_problems', false)
            ->where('cart.has_price_changes', false)
            ->where('cart.ready_for_checkout', true));
});

it('points out a price that changed since it was shown, and keeps checkout closed until it is accepted', function () {
    wholesalePricingLine($this->karim, $this->kettle, null, 10);

    // A new quantity band now covers the line's quantity.
    ProductPriceTier::create(['product_id' => $this->kettle->id, 'min_quantity' => 10, 'unit_price_minor' => 190000]);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.lines.0.price_changed', true)
            ->where('cart.lines.0.unit_price_seen.minor_units', 200000)
            ->where('cart.lines.0.unit_price.minor_units', 190000)
            ->where('cart.subtotal.minor_units', 1900000)
            ->where('cart.has_price_changes', true)
            ->where('cart.ready_for_checkout', false));

    $this->actingAs($this->karim->owner)->post(route('wholesale.cart.prices.accept'))->assertRedirect();

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.lines.0.price_changed', false)
            ->where('cart.lines.0.unit_price_seen.minor_units', 190000)
            ->where('cart.ready_for_checkout', true));
});

it('leaves out of the subtotal what can no longer be bought, and says why', function () {
    wholesalePricingLine($this->karim, $this->kettle, null, 10);
    wholesalePricingLine($this->karim, $this->shirt, $this->medium, 12);
    wholesalePricingLine($this->karim, $this->lid, null, 6);
    wholesalePricingLine($this->karim, $this->pot, null, 5);
    wholesalePricingLine($this->karim, $this->cup, null, 6);

    $this->kettle->forceFill(['status' => ProductStatus::Inactive])->save();
    $this->medium->forceFill(['is_active' => false])->save();
    $this->lid->forceFill(['min_order_quantity' => 10])->save();
    // Three of Karim's five allocated pots go back to shared stock, and shared
    // stock is then counted short: two remain that Karim can order.
    app(StockAllocations::class)->release(StockAllocation::query()->where('business_account_id', $this->karim->id)->sole(), 3);
    app(StockLedger::class)->move(StockItem::query()->where('product_id', $this->pot->id)->sole(), StockBucket::Available, null, 3, StockMovementType::Adjustment);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.lines', function (Collection $lines) {
                $bySku = wholesalePricingBySku($lines);

                return $bySku['FW-KT']['problems'] === [CartLineQuote::UNAVAILABLE]
                    && $bySku['FW-KT']['unit_price'] === null
                    && $bySku['FW-SH-M']['problems'] === [CartLineQuote::VARIATION_UNAVAILABLE]
                    && $bySku['FW-LID']['problems'] === [CartLineQuote::BELOW_MINIMUM]
                    && $bySku['FW-POT']['problems'] === [CartLineQuote::INSUFFICIENT_STOCK]
                    && $bySku['FW-POT']['available'] === 2
                    && $bySku['FW-CUP']['problems'] === []
                    && $bySku['FW-CUP']['purchasable'] === true;
            })
            ->where('cart.subtotal.minor_units', 90000)
            ->where('cart.has_problems', true)
            ->where('cart.ready_for_checkout', false));
});

it('counts stock another account has since reserved or been allocated as gone', function () {
    wholesalePricingLine($this->karim, $this->kettle, null, 10);

    app(StockReservations::class)->reserve($this->kettle, null, 90, ReservationKind::OnlinePayment, 'ORD-RAHIM-1', $this->rahim);
    app(StockAllocations::class)->allocate(StockItem::query()->where('product_id', $this->kettle->id)->sole(), $this->rahim, 4);

    $this->actingAs($this->karim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cart.lines.0.problems', [CartLineQuote::INSUFFICIENT_STOCK])
            ->where('cart.lines.0.available', 6)
            ->where('cart.subtotal.minor_units', 0));
});

it('shows an empty cart to somebody who has none', function () {
    $this->actingAs($this->rahim->owner)
        ->get(route('wholesale.cart.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cart.lines', 0)
            ->where('cart.subtotal.minor_units', 0)
            ->where('cart.ready_for_checkout', false));
});
