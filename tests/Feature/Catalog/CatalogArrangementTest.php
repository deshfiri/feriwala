<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Arranging the catalogue (P3-14, §11.3): reordering categories and brands,
 * switching them off, and filing products under them.
 *
 * Switching a category or brand off is a visibility decision, not an edit: its
 * products leave every partner's catalogue without a single product row being
 * rewritten, and come back exactly as they were. Every write here is a
 * platform privilege a business account never holds.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->admin = testPlatformStaff(PlatformRole::Admin);

    $this->kitchen = Category::create(['name' => 'Kitchen', 'sort_order' => 0]);
    $this->garden = Category::create(['name' => 'Garden', 'sort_order' => 1]);
    $this->toys = Category::create(['name' => 'Toys', 'sort_order' => 2]);
    $this->cookware = Category::create(['name' => 'Cookware', 'parent_id' => $this->kitchen->id, 'sort_order' => 0]);
    $this->bakeware = Category::create(['name' => 'Bakeware', 'parent_id' => $this->kitchen->id, 'sort_order' => 1]);

    $this->walton = Brand::create(['name' => 'Walton', 'sort_order' => 0]);
    $this->vision = Brand::create(['name' => 'Vision', 'sort_order' => 0]);
    $this->singer = Brand::create(['name' => 'Singer', 'sort_order' => 5]);
});

function catalogArrangeProduct(string $sku, Category $category, array $attributes = []): Product
{
    return Product::create([
        'name' => 'Product '.$sku,
        'sku' => $sku,
        'category_id' => $category->id,
        'base_cost_minor' => 150000,
        'wholesale_price_minor' => 200000,
        'status' => ProductStatus::Active,
        'package_scope' => PackageScope::AllPackages,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        ...$attributes,
    ]);
}

function catalogArrangeAccount(): BusinessAccount
{
    $package = Package::create([
        'slug' => 'arrange-'.Str::lower(Str::random(8)),
        'name' => 'Arrange package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

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
 * @return array<int, string>
 */
function catalogArrangeListed(BusinessAccount $account): array
{
    return collect(test()->actingAs($account->owner)
        ->get(route('catalog.wholesale.index'))
        ->assertOk()
        ->viewData('page')['props']['products']['data'])
        ->pluck('sku')
        ->sort()
        ->values()
        ->all();
}

describe('reordering categories', function () {
    it('puts a branch in the order given, and keeps a sibling the screen did not know about', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.categories.reorder'), [
                'parent_id' => null,
                // Garden is left out, and Cookware belongs to another branch.
                'order' => [$this->toys->public_id, $this->cookware->public_id, $this->kitchen->public_id],
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(Category::query()->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all())->toBe(['Toys', 'Kitchen', 'Garden'])
            ->and(Category::query()->whereNull('parent_id')->orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1, 2])
            ->and($this->cookware->refresh()->sort_order)->toBe(0);
    });

    it('reorders subcategories within their parent without touching the top level', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.catalog.categories.reorder'), [
                'parent_id' => $this->kitchen->public_id,
                'order' => [$this->bakeware->public_id, $this->cookware->public_id],
            ])
            ->assertSessionHasNoErrors();

        expect($this->kitchen->children()->orderBy('sort_order')->pluck('name')->all())->toBe(['Bakeware', 'Cookware'])
            ->and(Category::query()->whereNull('parent_id')->orderBy('sort_order')->pluck('name')->all())->toBe(['Kitchen', 'Garden', 'Toys']);
    });

    it('is refused to a business account and to view-only staff (§12)', function () {
        $payload = ['order' => [$this->toys->public_id, $this->kitchen->public_id]];

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.categories.reorder'), $payload)
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.categories.reorder'), $payload)
            ->assertForbidden();

        expect($this->toys->refresh()->sort_order)->toBe(2);
    });
});

describe('moving brands', function () {
    it('moves a brand one place and renumbers the whole order', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.position', $this->singer->public_id), ['direction' => 'up'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(Brand::query()->orderBy('sort_order')->pluck('name')->all())->toBe(['Walton', 'Singer', 'Vision'])
            ->and(Brand::query()->orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1, 2]);
    });

    it('changes nothing when the first brand moves up or the last moves down', function () {
        $this->actingAs($this->manager)->patch(route('admin.catalog.brands.position', $this->walton->public_id), ['direction' => 'up']);
        $this->actingAs($this->manager)->patch(route('admin.catalog.brands.position', $this->singer->public_id), ['direction' => 'down']);

        expect(Brand::query()->orderBy('sort_order')->orderBy('id')->pluck('name')->all())->toBe(['Walton', 'Vision', 'Singer']);
    });

    it('is refused to a business account and to view-only staff, and needs a direction', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->patch(route('admin.catalog.brands.position', $this->singer->public_id), ['direction' => 'up'])
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->patch(route('admin.catalog.brands.position', $this->singer->public_id), ['direction' => 'up'])
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.position', $this->singer->public_id), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');

        expect($this->singer->refresh()->sort_order)->toBe(5);
    });
});

describe('switching a category or brand off', function () {
    it('takes its products, and its subcategories’ products, off partners’ catalogues until it is back on', function () {
        $account = catalogArrangeAccount();
        $pot = catalogArrangeProduct('FW-POT', $this->cookware);
        catalogArrangeProduct('FW-HOSE', $this->garden);

        expect(catalogArrangeListed($account))->toBe(['FW-HOSE', 'FW-POT']);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.categories.active', $this->kitchen->public_id), ['is_active' => false]);

        expect(catalogArrangeListed($account))->toBe(['FW-HOSE'])
            ->and(app(ProductEligibility::class)->refusals($pot->refresh(), $account))->toContain(ProductEligibility::CATEGORY_SWITCHED_OFF)
            ->and($pot->status)->toBe(ProductStatus::Active);

        $this->actingAs($account->owner)
            ->get(route('catalog.wholesale.show', $pot->slug))
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.categories.active', $this->kitchen->public_id), ['is_active' => true]);

        expect(catalogArrangeListed($account))->toBe(['FW-HOSE', 'FW-POT']);
    });

    it('does the same for a switched-off brand, and never touches a product without one', function () {
        $account = catalogArrangeAccount();
        $kettle = catalogArrangeProduct('FW-KETTLE', $this->kitchen, ['brand_id' => $this->walton->id]);
        catalogArrangeProduct('FW-SPOON', $this->kitchen);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.active', $this->walton->public_id), ['is_active' => false]);

        expect(catalogArrangeListed($account))->toBe(['FW-SPOON'])
            ->and(app(ProductEligibility::class)->refusals($kettle->refresh(), $account))->toBe([ProductEligibility::BRAND_SWITCHED_OFF]);
    });

    it('gives the list and the single-product answer the same result for every arrangement', function () {
        $account = catalogArrangeAccount();
        $this->garden->forceFill(['is_active' => false])->save();
        $this->kitchen->forceFill(['is_active' => false])->save();
        $this->toys->forceFill(['is_active' => true])->save();
        $this->vision->forceFill(['is_active' => false])->save();

        $products = [
            catalogArrangeProduct('FW-1', $this->cookware),
            catalogArrangeProduct('FW-2', $this->garden),
            catalogArrangeProduct('FW-3', $this->toys),
            catalogArrangeProduct('FW-4', $this->toys, ['brand_id' => $this->vision->id]),
            catalogArrangeProduct('FW-5', $this->toys, ['brand_id' => $this->walton->id]),
        ];

        $eligibility = app(ProductEligibility::class);
        $listed = $eligibility->query($account)->pluck('sku')->all();

        foreach ($products as $product) {
            expect(in_array($product->sku, $listed, true))->toBe($eligibility->isEligible($product->refresh(), $account));
        }

        expect($listed)->toEqualCanonicalizing(['FW-3', 'FW-5']);
    });

    it('states on the category screen how many products switching each one off would hide', function () {
        catalogArrangeProduct('FW-1', $this->kitchen);
        catalogArrangeProduct('FW-2', $this->cookware);
        catalogArrangeProduct('FW-3', $this->bakeware);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.name', 'Kitchen')
                ->where('categories.0.products_count', 1)
                ->where('categories.0.products_in_branch', 3),
            );
    });
});

describe('assigning products', function () {
    it('files selected products under a category, counting the ones already there', function () {
        $first = catalogArrangeProduct('FW-1', $this->garden);
        $second = catalogArrangeProduct('FW-2', $this->garden);
        $already = catalogArrangeProduct('FW-3', $this->cookware);

        $this->actingAs($this->admin)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$first->public_id, $second->public_id, $already->public_id],
                'action' => 'category',
                'category' => $this->cookware->public_id,
            ])
            ->assertInertiaFlash('bulk_result.changed', 2)
            ->assertInertiaFlash('bulk_result.unchanged', 1);

        expect($first->refresh()->category_id)->toBe($this->cookware->id)
            ->and($second->refresh()->category_id)->toBe($this->cookware->id);
    });

    it('gives products a brand, and takes it away again', function () {
        $product = catalogArrangeProduct('FW-1', $this->kitchen);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'brand', 'brand' => $this->walton->public_id])
            ->assertInertiaFlash('bulk_result.changed', 1);

        expect($product->refresh()->brand_id)->toBe($this->walton->id);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'brand', 'brand' => null])
            ->assertInertiaFlash('bulk_result.changed', 1);

        expect($product->refresh()->brand_id)->toBeNull();
    });

    it('lets a category be removed once its products are filed elsewhere', function () {
        $product = catalogArrangeProduct('FW-1', $this->toys);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.categories.destroy', $this->toys->public_id))
            ->assertSessionHasErrors('category');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'category', 'category' => $this->garden->public_id]);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.categories.destroy', $this->toys->public_id))
            ->assertSessionHasNoErrors();

        expect(Category::query()->whereKey($this->toys->id)->exists())->toBeFalse();
    });

    it('is refused to anyone who may not edit the catalogue, before any rule runs (§12)', function () {
        $product = catalogArrangeProduct('FW-1', $this->kitchen);

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.products.bulk'), [])
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'category', 'category' => $this->garden->public_id])
            ->assertForbidden();

        expect($product->refresh()->category_id)->toBe($this->kitchen->id);
    });

    it('refuses a category that does not exist, and a brand assignment that names none', function () {
        $product = catalogArrangeProduct('FW-1', $this->kitchen);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'category', 'category' => '01jzzzzzzzzzzzzzzzzzzzzzzz'])
            ->assertSessionHasErrors('category');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'brand'])
            ->assertSessionHasErrors('brand');

        expect($product->refresh()->category_id)->toBe($this->kitchen->id);
    });

    it('offers assignment on the product list only to those who may edit', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.catalog.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bulk.assign', true));

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->get(route('admin.catalog.products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bulk.assign', false));
    });
});
