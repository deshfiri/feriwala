<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\SetFeatured;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Related products and featured status (P3-11, §11.1, §13).
 *
 * A relation is ordered, one-directional and never to itself, held in the
 * database. Featuring is a publishing decision. And a partner's product page
 * shows only recommendations the partner could open themselves — a related
 * product must never become a way to see one they were not offered.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Kitchen']);

    $this->cooker = catalogMerchProduct('FW-COOKER', 'Rice cooker');
    $this->steamer = catalogMerchProduct('FW-STEAMER', 'Steamer');
    $this->ladle = catalogMerchProduct('FW-LADLE', 'Ladle');
});

function catalogMerchProduct(string $sku, string $name, array $attributes = []): Product
{
    return Product::create([
        'name' => $name,
        'sku' => $sku,
        'category_id' => Category::query()->value('id'),
        'wholesale_price' => Money::fromDecimal('1000.00', Currency::BDT),
        'status' => ProductStatus::Active,
        'package_scope' => PackageScope::AllPackages,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        ...$attributes,
    ]);
}

function catalogMerchAccount(): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    $package = Package::create([
        'slug' => 'merch-'.Str::lower(Str::random(8)),
        'name' => 'Merch package',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

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

describe('related products', function () {
    it('saves an ordered list, and replaces it on the next save', function () {
        $url = route('admin.catalog.products.related.update', $this->cooker->public_id);

        $this->actingAs($this->manager)
            ->put($url, ['related_ids' => [$this->ladle->public_id, $this->steamer->public_id]])
            ->assertSessionHasNoErrors();

        expect($this->cooker->relatedProducts()->pluck('products.sku')->all())->toBe(['FW-LADLE', 'FW-STEAMER'])
            ->and($this->steamer->relatedProducts()->count())->toBe(0);

        $this->actingAs($this->manager)
            ->put($url, ['related_ids' => [$this->steamer->public_id]])
            ->assertSessionHasNoErrors();

        expect($this->cooker->relatedProducts()->pluck('products.sku')->all())->toBe(['FW-STEAMER']);
    });

    it('refuses a product related to itself, in the form and in the database', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.related.update', $this->cooker->public_id), [
                'related_ids' => [$this->cooker->public_id],
            ])
            ->assertSessionHasErrors('related_ids.0');

        expect(fn () => DB::table('product_related')->insert([
            'product_id' => $this->cooker->id,
            'related_product_id' => $this->cooker->id,
            'position' => 1,
        ]))->toThrow(QueryException::class, 'product_related_not_self');
    });

    it('holds each pairing unique in the database', function () {
        $this->cooker->relatedProducts()->attach($this->steamer->id, ['position' => 1]);

        expect(fn () => DB::table('product_related')->insert([
            'product_id' => $this->cooker->id,
            'related_product_id' => $this->steamer->id,
            'position' => 2,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a business account holder and staff who may only read', function () {
        $url = route('admin.catalog.products.related.update', $this->cooker->public_id);

        $this->actingAs(catalogMerchAccount()->owner)
            ->put($url, ['related_ids' => [$this->steamer->public_id]])
            ->assertForbidden();
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->put($url, ['related_ids' => [$this->steamer->public_id]])
            ->assertForbidden();

        expect($this->cooker->relatedProducts()->count())->toBe(0);
    });

    it('goes with a permanently deleted draft in both directions', function () {
        $draft = catalogMerchProduct('FW-DRAFT', 'Draft', ['status' => ProductStatus::Draft]);
        $draft->relatedProducts()->attach($this->steamer->id, ['position' => 1]);
        $this->cooker->relatedProducts()->attach($draft->id, ['position' => 1]);

        app(ManageProducts::class)->trash($this->manager, $draft, 'Test.');
        app(ManageProducts::class)->permanentlyDelete($this->manager, $draft->refresh());

        expect(DB::table('product_related')->count())->toBe(0);
    });
});

describe('what a partner is shown', function () {
    it('shows only the recommendations the partner could open on the same channel, in order', function () {
        $hidden = catalogMerchProduct('FW-HIDDEN', 'Hidden', ['package_scope' => PackageScope::SelectedPackages]);
        $dropshipOnly = catalogMerchProduct('FW-DROP', 'Dropship only', ['wholesale_status' => ProductStatus::WholesaleDisabled]);

        $this->cooker->relatedProducts()->attach([
            $this->ladle->id => ['position' => 1],
            $hidden->id => ['position' => 2],
            $dropshipOnly->id => ['position' => 3],
            $this->steamer->id => ['position' => 4],
        ]);

        $this->actingAs(catalogMerchAccount()->owner)
            ->get(route('catalog.wholesale.show', $this->cooker->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('related', 2)
                ->where('related.0.sku', 'FW-LADLE')
                ->where('related.1.sku', 'FW-STEAMER')
                ->missing('related.0.base_cost'),
            );
    });

    it('lists featured products first on the partner catalogue, labelled', function () {
        app(SetFeatured::class)->handle($this->manager, $this->steamer, true);

        $this->actingAs(catalogMerchAccount()->owner)
            ->get(route('catalog.wholesale.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.sku', 'FW-STEAMER')
                ->where('products.data.0.is_featured', true)
                ->where('products.data.1.is_featured', false),
            );
    });
});

describe('featuring', function () {
    it('lets a publisher feature a product and stamps when', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.featured.update', $this->cooker->public_id), ['featured' => true])
            ->assertSessionHasNoErrors();

        $this->cooker->refresh();

        expect($this->cooker->is_featured)->toBeTrue()
            ->and($this->cooker->featured_at)->not->toBeNull();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.featured.update', $this->cooker->public_id), ['featured' => false]);

        expect($this->cooker->refresh()->is_featured)->toBeFalse()
            ->and($this->cooker->featured_at)->toBeNull();
    });

    it('refuses somebody who may edit but not publish, at the door and in the action', function () {
        $editor = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($editor)
            ->patch(route('admin.catalog.products.featured.update', $this->cooker->public_id), ['featured' => true])
            ->assertForbidden();

        expect(fn () => app(SetFeatured::class)->handle($editor, $this->cooker, true))
            ->toThrow(AuthorizationException::class);

        expect($this->cooker->refresh()->is_featured)->toBeFalse();
    });

    it('shows the state and finds products to relate only when asked', function () {
        $this->cooker->relatedProducts()->attach($this->steamer->id, ['position' => 1]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', [$this->cooker->public_id, 'related_search' => 'fw-']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('merchandising.is_featured', false)
                ->where('merchandising.related.0.sku', 'FW-STEAMER')
                ->missing('related_matches')
                ->reloadOnly('related_matches', fn (Assert $reload) => $reload
                    ->has('related_matches', 2)
                    ->where('related_matches.0.sku', 'FW-LADLE'),
                ),
            );
    });
});
