<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductDeletionRule;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Which product statuses may be deleted: every status, or drafts only.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = testPlatformStaff(PlatformRole::Admin);
    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    Category::create(['name' => 'Electronics']);
});

function deletionSettingProduct(string $sku, ProductStatus $status): Product
{
    return Product::create([
        'name' => 'Product '.$sku,
        'sku' => $sku,
        'category_id' => Category::query()->value('id'),
        'base_cost' => Money::fromDecimal('100.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('150.00', Currency::BDT),
        'status' => $status,
    ]);
}

function deletionSettingScope(string $scope): void
{
    app(SettingsRepository::class)->define(
        ProductDeletionRule::SETTING,
        'catalog',
        SettingType::String,
        ProductDeletionRule::ANY_STATUS,
    );
    app(SettingsRepository::class)->set(ProductDeletionRule::SETTING, $scope, null);
}

describe('the setting', function () {
    it('defaults to every status, so nothing changes until an administrator chooses', function () {
        expect(app(ProductDeletionRule::class)->scope())->toBe(ProductDeletionRule::ANY_STATUS);
    });

    it('lets an administrator switch to drafts only', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.product-deletion-settings.update'), ['scope' => ProductDeletionRule::DRAFTS_ONLY])
            ->assertRedirect();

        expect(app(ProductDeletionRule::class)->scope())->toBe(ProductDeletionRule::DRAFTS_ONLY);
    });

    it('refuses an unknown scope', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.product-deletion-settings.update'), ['scope' => 'anything_goes'])
            ->assertSessionHasErrors('scope');

        expect(app(ProductDeletionRule::class)->scope())->toBe(ProductDeletionRule::ANY_STATUS);
    });

    it('is shown to catalogue staff, who may change it only with catalog.manage_settings', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.product-deletion-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/product-deletion-settings')
                ->where('settings.scope', ProductDeletionRule::ANY_STATUS)
                ->where('can.manage', true));

        $this->actingAs($this->manager)
            ->get(route('admin.product-deletion-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

        $this->actingAs($this->manager)
            ->put(route('admin.product-deletion-settings.update'), ['scope' => ProductDeletionRule::DRAFTS_ONLY])
            ->assertForbidden();

        expect(app(ProductDeletionRule::class)->scope())->toBe(ProductDeletionRule::ANY_STATUS);
    });

    it('is closed to a business account holder', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)->get(route('admin.product-deletion-settings.index'))->assertForbidden();
        $this->actingAs($owner)
            ->put(route('admin.product-deletion-settings.update'), ['scope' => ProductDeletionRule::DRAFTS_ONLY])
            ->assertForbidden();
    });
});

describe('deleting under the setting', function () {
    it('deletes a product in any status while every status is allowed', function () {
        $product = deletionSettingProduct('ACTIVE001', ProductStatus::Active);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.destroy', $product->public_id), ['reason' => 'Test.'])
            ->assertRedirect(route('admin.catalog.products.index'));

        expect($product->refresh()->trashed())->toBeTrue();
    });

    it('refuses a non-draft product but deletes a draft once it is drafts only', function () {
        deletionSettingScope(ProductDeletionRule::DRAFTS_ONLY);

        $active = deletionSettingProduct('ACTIVE001', ProductStatus::Active);
        $archived = deletionSettingProduct('ARCHIVE01', ProductStatus::Archived);
        $draft = deletionSettingProduct('DRAFT0001', ProductStatus::Draft);

        foreach ([$active, $archived] as $product) {
            $this->actingAs($this->manager)
                ->delete(route('admin.catalog.products.destroy', $product->public_id), ['reason' => 'Test.'])
                ->assertSessionHasErrors('product');

            expect($product->refresh()->trashed())->toBeFalse();
        }

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.destroy', $draft->public_id), ['reason' => 'Test.'])
            ->assertRedirect(route('admin.catalog.products.index'));

        expect($draft->refresh()->trashed())->toBeTrue();
    });

    it('tells the editor which products are deletable', function () {
        deletionSettingScope(ProductDeletionRule::DRAFTS_ONLY);

        $active = deletionSettingProduct('ACTIVE001', ProductStatus::Active);
        $draft = deletionSettingProduct('DRAFT0001', ProductStatus::Draft);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $active->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('product.is_deletable', false));

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $draft->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('product.is_deletable', true));
    });
});
