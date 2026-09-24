<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The central catalogue from a business account's side (P3-10, §10, §12, §13).
 *
 * Two separate screens over the same products. Each shows only what the server
 * says the account may see on that channel, and only that channel's prices —
 * never the base cost, never the other channel's figures. A product the account
 * may not see on a channel is a 404 there, whatever address was typed.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->package = catalogBrowsePackage();
    $this->account = catalogBrowseAccount($this->package);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->cookware = Category::create(['name' => 'Cookware', 'parent_id' => $this->kitchen->id]);
    $this->garden = Category::create(['name' => 'Garden']);
    $this->walton = Brand::create(['name' => 'Walton']);

    // Wholesale only.
    $this->pot = catalogBrowseProduct('FW-POT', 'Steel pot', $this->cookware, [
        'brand_id' => $this->walton->id,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'min_order_quantity' => 6,
    ]);

    // Dropshipping only.
    $this->hose = catalogBrowseProduct('FW-HOSE', 'Garden hose', $this->garden, [
        'dropshipping_status' => ProductStatus::DropshippingEnabled,
        'suggested_selling_price' => Money::fromDecimal('990.00', Currency::BDT),
        'minimum_selling_price' => Money::fromDecimal('900.00', Currency::BDT),
    ]);

    // Wholesale, but offered to a different package.
    $this->exclusive = catalogBrowseProduct('FW-EXCL', 'Exclusive kettle', $this->kitchen, [
        'package_scope' => PackageScope::SelectedPackages,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
    ]);
    $this->exclusive->eligiblePackages()->attach(catalogBrowsePackage()->id);
});

function catalogBrowsePackage(array $features = []): Package
{
    $package = Package::create([
        'slug' => 'browse-'.Str::lower(Str::random(8)),
        'name' => 'Browse package',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    foreach ($features as $feature => $value) {
        $package->features()->create(['feature' => $feature, 'value' => $value]);
    }

    return $package;
}

function catalogBrowseAccount(Package $package, AccountStatus $status = AccountStatus::Active): BusinessAccount
{
    $account = testBusinessAccount($status);

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

function catalogBrowseProduct(string $sku, string $name, Category $category, array $attributes = []): Product
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

describe('two separate catalogues', function () {
    it('lists on the wholesale screen only what is offered for wholesale, with wholesale prices and never the cost', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/browse')
                ->where('channel', 'wholesale')
                ->where('facility_allowed', true)
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'FW-POT')
                ->where('products.data.0.wholesale_price.amount', '2000.00')
                ->where('products.data.0.min_order_quantity', 6)
                ->missing('products.data.0.base_cost')
                ->missing('products.data.0.suggested_selling_price'),
            );
    });

    it('lists on the dropshipping screen only what is offered for dropshipping, with selling guidance and no wholesale figure', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.dropshipping.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('channel', 'dropshipping')
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'FW-HOSE')
                ->where('products.data.0.suggested_selling_price.amount', '990.00')
                ->where('products.data.0.maximum_selling_price', null)
                ->missing('products.data.0.wholesale_price')
                ->missing('products.data.0.base_cost'),
            );
    });

    it('opens a product only on the channel it is offered on, and is a 404 everywhere else', function () {
        ProductPriceTier::create(['product_id' => $this->pot->id, 'min_quantity' => 24, 'unit_price' => Money::fromDecimal('1800.00', Currency::BDT)]);

        $owner = $this->account->owner;

        $this->actingAs($owner)
            ->get(route('catalog.wholesale.show', $this->pot->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/show')
                ->where('product.sku', 'FW-POT')
                ->where('product.quantity_pricing.0.min_quantity', 24)
                ->where('product.quantity_pricing.0.unit_price.amount', '1800.00')
                ->missing('product.base_cost')
                ->missing('product.suggested_selling_price'),
            );

        $this->actingAs($owner)->get(route('catalog.dropshipping.show', $this->pot->slug))->assertNotFound();
        $this->actingAs($owner)->get(route('catalog.wholesale.show', $this->hose->slug))->assertNotFound();
        $this->actingAs($owner)->get(route('catalog.wholesale.show', $this->exclusive->slug))->assertNotFound();
        $this->actingAs($owner)->get(route('catalog.wholesale.show', 'no-such-product'))->assertNotFound();
    });

    it('never lists a product that is not active', function () {
        $this->pot->forceFill(['status' => ProductStatus::Inactive])->save();

        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));

        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.show', $this->pot->slug))
            ->assertNotFound();
    });
});

describe('searching and filtering in the database', function () {
    beforeEach(function () {
        catalogBrowseProduct('FW-PAN', 'Frying pan', $this->cookware, ['wholesale_status' => ProductStatus::WholesaleEnabled]);
        catalogBrowseProduct('FW-RAKE', 'Rake', $this->garden, ['wholesale_status' => ProductStatus::WholesaleEnabled]);
    });

    it('searches by name or SKU', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.index', ['search' => 'fw-pan']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.sku', 'FW-PAN'));
    });

    it('treats a parent category as including its subcategories', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.index', ['category' => $this->kitchen->public_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 2)
                ->where('products.data.0.sku', 'FW-PAN')
                ->where('products.data.1.sku', 'FW-POT')
                ->where('filters.category', $this->kitchen->public_id),
            );
    });

    it('filters by brand', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.index', ['brand' => $this->walton->public_id]))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.sku', 'FW-POT'));
    });
});

describe('what the package allows (§8.1, §10)', function () {
    it('shows nothing on a channel the package does not include, and opens nothing there', function () {
        $package = catalogBrowsePackage([PackageFeature::WholesaleEnabled->value => '0']);
        $account = catalogBrowseAccount($package);

        $this->actingAs($account->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facility_allowed', false)
                ->has('products.data', 0)
                ->where('account.allowsWholesale', false)
                ->where('account.allowsDropshipping', true),
            );

        $this->actingAs($account->owner)
            ->get(route('catalog.wholesale.show', $this->pot->slug))
            ->assertNotFound();

        expect(app(ProductEligibility::class)->refusals($this->pot, $account, SalesChannel::Wholesale))
            ->toBe([ProductEligibility::PACKAGE_WITHOUT_CHANNEL]);
    });

    it('offers both catalogue links to an account whose package includes both', function () {
        $this->actingAs($this->account->owner)
            ->get(route('catalog.dropshipping.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('account.allowsWholesale', true)
                ->where('account.allowsDropshipping', true),
            );
    });

    it('explains a product switched off for the channel', function () {
        expect(app(ProductEligibility::class)->refusals($this->hose, $this->account, SalesChannel::Wholesale))
            ->toBe([ProductEligibility::CHANNEL_DISABLED]);
    });
});

describe('who reaches the business catalogue', function () {
    it('turns platform staff away, since they have no business account', function () {
        // The funnel gate sends somebody without a business account to their
        // own home before the catalogue is reached; nothing is rendered.
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('catalog.wholesale.index'))
            ->assertRedirect();
    });

    it('turns an account that is not yet activated back to onboarding', function () {
        $pending = catalogBrowseAccount($this->package, AccountStatus::KycPending);

        $this->actingAs($pending->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertRedirect();
    });

    it('refuses a business account a product write before validating a single field', function () {
        // An incomplete payload: a 403 rather than "the SKU field is required",
        // because the second would be an answer the partner has no right to.
        $this->actingAs($this->account->owner)
            ->patch(route('admin.catalog.products.update', $this->pot->public_id), ['name' => 'Mine'])
            ->assertForbidden()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->account->owner)
            ->post(route('admin.catalog.brands.store'), [])
            ->assertForbidden();

        expect($this->pot->refresh()->name)->toBe('Steel pot');
    });
});
