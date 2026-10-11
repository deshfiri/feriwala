<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Partner's one Products workspace, and the header's wallet quick view.
 *
 * Every product the account may use appears once with the terms of each way it
 * may use it; what it may not use is absent, and nothing outside its own
 * account is ever reachable.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = partnerProductsAccount();
    $this->category = Category::create(['name' => 'Kitchen']);

    $this->both = partnerProductsProduct('PP-BOTH', 'Both ways', $this->category, [
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
        'suggested_selling_price' => Money::fromDecimal('2600.00', Currency::BDT),
        'minimum_selling_price' => Money::fromDecimal('2300.00', Currency::BDT),
    ]);
    $this->wholesaleOnly = partnerProductsProduct('PP-WHOLE', 'Wholesale only', $this->category, [
        'wholesale_status' => ProductStatus::WholesaleEnabled,
    ]);
    $this->sellingOnly = partnerProductsProduct('PP-SELL', 'Selling only', $this->category, [
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
    ]);
    $this->neither = partnerProductsProduct('PP-NONE', 'Neither', $this->category);
});

function partnerProductsAccount(array $features = []): BusinessAccount
{
    $package = Package::create([
        'slug' => 'pp-'.Str::lower(Str::random(8)),
        'name' => 'Products package',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($features as $feature => $value) {
        $package->features()->create(['feature' => $feature, 'value' => $value]);
    }

    $account = testBusinessAccount(AccountStatus::Active);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return $account->refresh();
}

function partnerProductsProduct(string $sku, string $name, Category $category, array $attributes = []): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => $category->id,
        'base_cost' => Money::fromDecimal('1500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'status' => ProductStatus::Active,
        'package_scope' => PackageScope::AllPackages,
        ...$attributes,
    ]);
}

describe('the unified Products page', function () {
    it('lists each product once, with the ways it can be used and never the cost', function () {
        $this->actingAs($this->account->owner)->get(route('catalog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/products')
                ->where('facility_allowed', true)
                ->has('products.data', 3)
                ->where('products.data.0.bpc', 'PP-BOTH')
                ->where('products.data.0.modes', ['wholesale' => true, 'partner_selling' => true])
                ->where('products.data.0.wholesale.price.amount', '2000.00')
                ->where('products.data.0.partner_selling.suggested_selling_price.amount', '2600.00')
                ->where('products.data.0.partner_selling.minimum_selling_price.amount', '2300.00')
                ->missing('products.data.0.base_cost')
                ->where('products.data.1.modes', ['wholesale' => false, 'partner_selling' => true])
                ->where('products.data.1.wholesale', null)
                ->where('products.data.2.modes', ['wholesale' => true, 'partner_selling' => false])
                ->where('products.data.2.partner_selling', null));
    });

    it('drops the way a package does not include and the products that only had it', function () {
        $account = partnerProductsAccount([PackageFeature::WholesaleEnabled->value => '0']);

        $this->actingAs($account->owner)->get(route('catalog.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 2)
                ->where('products.data.0.modes', ['wholesale' => false, 'partner_selling' => true])
                ->where('can.wholesale', false));
    });

    it('says plainly when the package includes neither way', function () {
        $account = partnerProductsAccount([
            PackageFeature::WholesaleEnabled->value => '0',
            PackageFeature::DropshippingEnabled->value => '0',
        ]);

        $this->actingAs($account->owner)->get(route('catalog.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('facility_allowed', false)
                ->has('products.data', 0));
    });

    it('keeps a product offered to other packages away from this account', function () {
        $exclusive = partnerProductsProduct('PP-EXCL', 'Exclusive', $this->category, [
            'package_scope' => PackageScope::SelectedPackages,
            'wholesale_status' => ProductStatus::WholesaleEnabled,
        ]);

        $skus = collect($this->actingAs($this->account->owner)->get(route('catalog.index'))->viewData('page')['props']['products']['data'])->pluck('sku');

        expect($skus)->not->toContain($exclusive->sku);
    });

    it('turns platform staff and the signed-out away', function () {
        $this->get(route('catalog.index'))->assertRedirect();

        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('catalog.index'))->assertRedirect();
    });
});

describe('the unified product page', function () {
    it('shows one product with the terms of both ways and every figure the server gives', function () {
        $this->both->priceTiers()->create(['min_quantity' => 10, 'unit_price' => Money::fromDecimal('1800.00', Currency::BDT)]);
        $this->both->forceFill([
            'min_order_quantity' => 2,
            'maximum_selling_price' => Money::fromDecimal('3000.00', Currency::BDT),
        ])->save();

        $this->actingAs($this->account->owner)->get(route('catalog.show', $this->both->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/show')
                ->where('channel', 'all')
                ->where('modes', ['wholesale' => true, 'partner_selling' => true])
                ->where('product.bpc', 'PP-BOTH')
                ->where('product.package_scope', 'all')
                ->where('product.wholesale_price.amount', '2000.00')
                ->where('product.min_order_quantity', 2)
                ->where('product.quantity_pricing.0.unit_price.amount', '1800.00')
                ->where('product.suggested_selling_price.amount', '2600.00')
                ->where('product.minimum_selling_price.amount', '2300.00')
                ->where('product.maximum_selling_price.amount', '3000.00')
                ->has('websites')
                ->missing('product.base_cost'));
    });

    it('carries only the terms of the way a product is actually offered', function () {
        $this->actingAs($this->account->owner)->get(route('catalog.show', $this->wholesaleOnly->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modes', ['wholesale' => true, 'partner_selling' => false])
                ->where('product.wholesale_price.amount', '2000.00')
                ->missing('product.suggested_selling_price'));

        $this->get(route('catalog.show', $this->sellingOnly->slug))
            ->assertInertia(fn (Assert $page) => $page
                ->where('modes', ['wholesale' => false, 'partner_selling' => true])
                ->missing('product.wholesale_price'));
    });

    it('is a 404 for a product the account may not use, however the address was typed', function () {
        $this->actingAs($this->account->owner)->get(route('catalog.show', $this->neither->slug))->assertNotFound();

        $other = partnerProductsProduct('PP-OTHERPKG', 'Other package', $this->category, [
            'package_scope' => PackageScope::SelectedPackages,
            'wholesale_status' => ProductStatus::WholesaleEnabled,
        ]);

        $this->get(route('catalog.show', $other->slug))->assertNotFound();
    });

    it('does not swallow the channel screens that share its address prefix', function () {
        $this->actingAs($this->account->owner)->get(route('catalog.wholesale.index'))
            ->assertInertia(fn (Assert $page) => $page->component('catalog/browse'));
    });
});

describe('the wallet quick view', function () {
    it('gives the account its own usable balance and nothing else', function () {
        $wallet = app(OpenWallet::class)->handle($this->account);
        $wallet->forceFill([
            'total' => Money::fromDecimal('1000.00', $wallet->currency()),
            'reserved' => Money::fromDecimal('200.00', $wallet->currency()),
        ])->save();

        $response = $this->actingAs($this->account->owner)->getJson(route('wallet.quick-view'))
            ->assertOk()
            ->assertJsonPath('usable.amount', '800.00')
            ->assertJsonMissingPath('reserved')
            ->assertJsonMissingPath('total');

        expect(array_keys($response->json()))->toBe(['usable'])
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('never reads another account\'s wallet', function () {
        $mine = app(OpenWallet::class)->handle($this->account);
        $mine->forceFill(['total' => Money::fromDecimal('100.00', $mine->currency())])->save();

        $other = partnerProductsAccount();
        $theirs = app(OpenWallet::class)->handle($other);
        $theirs->forceFill(['total' => Money::fromDecimal('999999.00', $theirs->currency())])->save();

        $this->actingAs($this->account->owner)->getJson(route('wallet.quick-view'))
            ->assertJsonPath('usable.amount', '100.00');
    });

    it('is not part of any page the Partner loads', function () {
        $wallet = app(OpenWallet::class)->handle($this->account);
        $wallet->forceFill(['total' => Money::fromDecimal('4321.00', $wallet->currency())])->save();

        $page = $this->actingAs($this->account->owner)->get(route('catalog.index'))->getContent();

        expect($page)->not->toContain('4321')->and($page)->not->toContain('4,321');
    });

    it('turns platform staff away and the signed-out', function () {
        $this->getJson(route('wallet.quick-view'))->assertUnauthorized();

        $this->actingAs(testPlatformStaff(PlatformRole::FinanceManager))
            ->getJson(route('wallet.quick-view'))->assertRedirect();
    });
});

it('shares the cart count the server worked out, for the header badge', function () {
    $this->actingAs($this->account->owner)->get(route('catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.allowsWholesale', true)
            ->where('account.wholesaleCartLines', 0));
});
