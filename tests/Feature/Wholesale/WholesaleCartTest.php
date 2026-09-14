<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
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
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wholesale\Models\CartItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Adding, changing and removing wholesale cart lines (P4-4, §14).
 *
 * A line is set to a quantity, never added to, so a repeated request leaves the
 * same cart. Before anything is written the server checks that the account may
 * buy the product wholesale, that a product with variations is bought per active
 * variation, that the quantity sits within the product's minimum and maximum,
 * and that the account can order that many now — its own allocation counting,
 * nobody else's. The browser sends no price the server would use, and a cart is
 * only ever its owner's.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = wholesaleCartPackage();
    $this->karim = wholesaleCartAccount($this->package);
    $this->rahim = wholesaleCartAccount($this->package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->kettle = wholesaleCartProduct('FW-KT', 'Electric kettle', ['min_order_quantity' => 6, 'max_order_quantity' => 60]);
    ProductPriceTier::create(['product_id' => $this->kettle->id, 'min_quantity' => 24, 'unit_price_minor' => 180000]);
    wholesaleCartStock($this->kettle, null, 100);

    // Never brought into inventory.
    $this->pan = wholesaleCartProduct('FW-PAN', 'Frying pan');

    // Only stock allocated to Karim.
    $this->pot = wholesaleCartProduct('FW-POT', 'Steel pot');
    app(StockAllocations::class)->allocate(wholesaleCartStock($this->pot, null, 5), $this->karim, 5);

    $this->shirt = wholesaleCartProduct('FW-SH', 'Polo shirt', ['wholesale_price_minor' => 100000]);
    $this->medium = ProductVariant::create([
        'product_id' => $this->shirt->id,
        'sku' => 'FW-SH-M',
        'combination_key' => 'm',
        'wholesale_price_minor' => 95000,
        'currency_code' => 'BDT',
        'is_active' => true,
    ]);
    $this->small = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-SH-S', 'combination_key' => 's', 'is_active' => false]);
    wholesaleCartStock($this->shirt, $this->medium, 30);
    wholesaleCartStock($this->shirt, $this->small, 30);
});

function wholesaleCartPackage(): Package
{
    return Package::create([
        'slug' => 'cart-'.Str::lower(Str::random(8)),
        'name' => 'Cart package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
}

function wholesaleCartAccount(Package $package): BusinessAccount
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
function wholesaleCartProduct(string $sku, string $name, array $attributes = []): Product
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

function wholesaleCartStock(Product $product, ?ProductVariant $variant, int $units): StockItem
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

/**
 * @param  array<string, mixed>  $payload
 */
function wholesaleCartAdd(User $user, array $payload): TestResponse
{
    return test()->actingAs($user)->post(route('wholesale.cart.items.store'), $payload);
}

/**
 * @return Collection<int, CartItem>
 */
function wholesaleCartLinesOf(BusinessAccount $account, ?User $user = null): Collection
{
    return CartItem::query()
        ->whereHas('cart', fn ($query) => $query
            ->where('user_id', ($user ?? $account->owner)->id)
            ->where('business_account_id', $account->id))
        ->get();
}

describe('setting a line', function () {
    it('sets the quantity rather than adding to it, so a repeated request leaves the same cart', function () {
        $owner = $this->karim->owner;

        wholesaleCartAdd($owner, ['product' => $this->kettle->slug, 'quantity' => 10])->assertSessionHasNoErrors()->assertRedirect();
        wholesaleCartAdd($owner, ['product' => $this->kettle->slug, 'quantity' => 10])->assertSessionHasNoErrors();

        expect(wholesaleCartLinesOf($this->karim))->toHaveCount(1)
            ->and(wholesaleCartLinesOf($this->karim)->sole()->quantity)->toBe(10);

        wholesaleCartAdd($owner, ['product' => $this->kettle->slug, 'quantity' => 12])->assertSessionHasNoErrors();

        expect(wholesaleCartLinesOf($this->karim)->sole()->quantity)->toBe(12);
    });

    it('uses no price the browser sends, and prices the line itself at its quantity', function () {
        wholesaleCartAdd($this->karim->owner, [
            'product' => $this->kettle->slug,
            'quantity' => 30,
            'unit_price' => 1,
            'unit_price_minor' => 1,
            'unit_price_seen_minor' => 1,
            'line_total' => 1,
            'total' => 1,
            'discount' => 999999,
        ])->assertSessionHasNoErrors();

        expect(wholesaleCartLinesOf($this->karim)->sole()->unit_price_seen_minor->minorUnits)->toBe(180000);

        $this->actingAs($this->karim->owner)
            ->get(route('wholesale.cart.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('wholesale/cart')
                ->where('facility_allowed', true)
                ->where('cart.lines.0.unit_price.minor_units', 180000)
                ->where('cart.lines.0.base_price.minor_units', 200000)
                ->where('cart.lines.0.line_total.minor_units', 5400000)
                ->where('cart.subtotal.minor_units', 5400000)
                ->missing('cart.lines.0.base_cost'));
    });

    it('refuses a quantity outside the product\'s rules, and accepts both bounds exactly', function (mixed $quantity, bool $accepted) {
        $response = wholesaleCartAdd($this->karim->owner, ['product' => $this->kettle->slug, 'quantity' => $quantity]);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            expect(wholesaleCartLinesOf($this->karim)->sole()->quantity)->toBe((int) $quantity);

            return;
        }

        $response->assertSessionHasErrors('quantity');
        expect(wholesaleCartLinesOf($this->karim))->toHaveCount(0);
    })->with([
        'below the minimum' => [5, false],
        'above the maximum' => [61, false],
        'zero' => [0, false],
        'negative' => [-3, false],
        'not a number' => ['ten', false],
        'a fraction' => ['6.5', false],
        'absurd' => [1000001, false],
        'exactly the minimum' => [6, true],
        'exactly the maximum' => [60, true],
    ]);

    it('says what the quantity rule is when it refuses', function () {
        wholesaleCartAdd($this->karim->owner, ['product' => $this->kettle->slug, 'quantity' => 5])
            ->assertSessionHasErrors(['quantity' => __('wholesale.refused.below_minimum', ['min' => 6])]);

        wholesaleCartAdd($this->karim->owner, ['product' => $this->kettle->slug, 'quantity' => 61])
            ->assertSessionHasErrors(['quantity' => __('wholesale.refused.above_maximum', ['max' => 60])]);
    });

    it('refuses more than this account can order, counting its own allocation and nobody else\'s', function () {
        wholesaleCartAdd($this->karim->owner, ['product' => $this->pan->slug, 'quantity' => 1])
            ->assertSessionHasErrors(['quantity' => __('wholesale.refused.insufficient_stock', ['available' => 0])]);

        wholesaleCartAdd($this->karim->owner, ['product' => $this->pot->slug, 'quantity' => 5])->assertSessionHasNoErrors();

        wholesaleCartAdd($this->karim->owner, ['product' => $this->pot->slug, 'quantity' => 6])
            ->assertSessionHasErrors(['quantity' => __('wholesale.refused.insufficient_stock', ['available' => 5])]);

        wholesaleCartAdd($this->rahim->owner, ['product' => $this->pot->slug, 'quantity' => 1])
            ->assertSessionHasErrors(['quantity' => __('wholesale.refused.insufficient_stock', ['available' => 0])]);

        expect(wholesaleCartLinesOf($this->karim)->sole()->quantity)->toBe(5)
            ->and(wholesaleCartLinesOf($this->rahim))->toHaveCount(0);
    });

    it('buys a product with variations per active variation of its own', function () {
        $owner = $this->karim->owner;

        wholesaleCartAdd($owner, ['product' => $this->shirt->slug, 'quantity' => 2])
            ->assertSessionHasErrors(['variant' => __('wholesale.refused.choose_variation')]);
        wholesaleCartAdd($owner, ['product' => $this->shirt->slug, 'variant' => 'FW-SH-S', 'quantity' => 2])
            ->assertSessionHasErrors(['variant' => __('wholesale.refused.variation_unavailable')]);
        wholesaleCartAdd($owner, ['product' => $this->shirt->slug, 'variant' => 'FW-KT', 'quantity' => 2])
            ->assertSessionHasErrors('variant');
        wholesaleCartAdd($owner, ['product' => $this->kettle->slug, 'variant' => 'FW-SH-M', 'quantity' => 6])
            ->assertSessionHasErrors('variant');

        expect(wholesaleCartLinesOf($this->karim))->toHaveCount(0);

        wholesaleCartAdd($owner, ['product' => $this->shirt->slug, 'variant' => 'FW-SH-M', 'quantity' => 2])->assertSessionHasNoErrors();

        $line = wholesaleCartLinesOf($this->karim)->sole();

        expect($line->product_variant_id)->toBe($this->medium->id)
            ->and($line->unit_price_seen_minor->minorUnits)->toBe(95000);
    });

    it('changes and removes a line in the person\'s own cart', function () {
        $owner = $this->karim->owner;
        wholesaleCartAdd($owner, ['product' => $this->kettle->slug, 'quantity' => 10]);
        $line = wholesaleCartLinesOf($this->karim)->sole();

        $this->actingAs($owner)->patch(route('wholesale.cart.items.update', $line->public_id), ['quantity' => 20])->assertSessionHasNoErrors();
        expect($line->refresh()->quantity)->toBe(20);

        $this->actingAs($owner)->patch(route('wholesale.cart.items.update', $line->public_id), ['quantity' => 5])->assertSessionHasErrors('quantity');
        expect($line->refresh()->quantity)->toBe(20);

        $this->actingAs($owner)->delete(route('wholesale.cart.items.destroy', $line->public_id))->assertRedirect();
        expect(wholesaleCartLinesOf($this->karim))->toHaveCount(0);
    });
});

describe('who reaches a cart', function () {
    it('finds no product the account may not buy wholesale, whatever is typed', function () {
        $exclusive = wholesaleCartProduct('FW-EXCL', 'Exclusive kettle', ['package_scope' => PackageScope::SelectedPackages]);
        $exclusive->eligiblePackages()->attach(wholesaleCartPackage()->id);
        wholesaleCartStock($exclusive, null, 9);

        $hose = wholesaleCartProduct('FW-HOSE', 'Garden hose', [
            'wholesale_status' => ProductStatus::WholesaleDisabled,
            'dropshipping_status' => ProductStatus::DropshippingEnabled,
        ]);
        wholesaleCartStock($hose, null, 9);

        foreach ([$exclusive->slug, $hose->slug, 'no-such-product'] as $slug) {
            wholesaleCartAdd($this->karim->owner, ['product' => $slug, 'quantity' => 1])->assertNotFound();
        }

        expect(wholesaleCartLinesOf($this->karim))->toHaveCount(0);
    });

    it('offers no cart to an account whose package does not include wholesale', function () {
        $package = wholesaleCartPackage();
        $package->features()->create(['feature' => PackageFeature::WholesaleEnabled->value, 'value' => '0']);
        $account = wholesaleCartAccount($package);

        wholesaleCartAdd($account->owner, ['product' => $this->kettle->slug, 'quantity' => 6])->assertNotFound();

        $this->actingAs($account->owner)
            ->get(route('wholesale.cart.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facility_allowed', false)
                ->where('cart', null)
                ->where('account.allowsWholesale', false));
    });

    it('turns platform staff away, since they have no business account', function () {
        $staff = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($staff)->get(route('wholesale.cart.show'))->assertRedirect();
        $this->actingAs($staff)->post(route('wholesale.cart.items.store'), ['product' => $this->kettle->slug, 'quantity' => 6])->assertRedirect();
    });

    it('keeps a line out of reach of another account and of another member of the same account', function () {
        wholesaleCartAdd($this->karim->owner, ['product' => $this->kettle->slug, 'quantity' => 10]);
        $line = wholesaleCartLinesOf($this->karim)->sole();

        $this->actingAs($this->rahim->owner)->patch(route('wholesale.cart.items.update', $line->public_id), ['quantity' => 7])->assertNotFound();
        $this->actingAs($this->rahim->owner)->delete(route('wholesale.cart.items.destroy', $line->public_id))->assertNotFound();

        $member = User::factory()->staff()->staffOf($this->karim, AccountRole::Staff)->create();

        $this->actingAs($member)
            ->get(route('wholesale.cart.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cart.lines', 0)
                ->where('account.wholesaleCartLines', 0));
        $this->actingAs($member)->patch(route('wholesale.cart.items.update', $line->public_id), ['quantity' => 7])->assertNotFound();

        $this->actingAs($this->karim->owner)
            ->get(route('wholesale.cart.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cart.lines', 1)
                ->where('account.wholesaleCartLines', 1));

        expect($line->refresh()->quantity)->toBe(10);
    });
});
