<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageBrands;
use App\Domain\Catalog\Actions\ManageCategories;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Policies denying catalogue creation to anybody not authorised (P3-15, §12).
 *
 * One answer, asked six ways: the static policy the controllers use, the
 * permission name, the model policy Laravel's Gate resolves, the form requests,
 * the actions, and the navigation contract the browser receives. A business
 * account member is refused by every one of them — including a partner who has
 * been handed a platform permission or the Super Admin role.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create(['name' => 'Rice cooker', 'sku' => 'FW-RC', 'category_id' => $this->category->id]);
});

/**
 * The people §12 is about, and the ones it is not.
 *
 * @return array<string, Closure(): User>
 */
function productRestrictionIdentities(): array
{
    return [
        'super admin' => fn () => testPlatformStaff(PlatformRole::SuperAdmin),
        'product manager' => fn () => testPlatformStaff(PlatformRole::ProductManager),
        'admin (no publishing)' => fn () => testPlatformStaff(PlatformRole::Admin),
        'inventory manager (view only)' => fn () => testPlatformStaff(PlatformRole::InventoryManager),
        'sms manager (no catalogue)' => fn () => testPlatformStaff(PlatformRole::SmsManager),
        'business owner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
        'business manager' => fn () => User::factory()->staffOf(testBusinessAccount(AccountStatus::Active), AccountRole::Manager)->create(),
        'business owner holding catalogue permissions' => function () {
            $owner = testBusinessAccount(AccountStatus::Active)->owner;
            $owner->givePermissionTo(['catalog.view', 'catalog.create', 'catalog.edit', 'catalog.delete', 'catalog.publish']);

            return $owner;
        },
        'business owner holding the super admin role' => function () {
            $owner = testBusinessAccount(AccountStatus::Active)->owner;
            $owner->assignRole(PlatformRole::SuperAdmin->value);

            return $owner;
        },
    ];
}

describe('one answer however it is asked', function () {
    it('agrees across the policy, the permission name and the Gate for every catalogue model', function (Closure $identity) {
        $user = $identity();

        $abilities = [
            'viewAny' => ['catalog.view', CatalogPolicy::canViewAny($user)],
            'create' => ['catalog.create', CatalogPolicy::canCreate($user)],
            'update' => ['catalog.edit', CatalogPolicy::canEdit($user)],
            'delete' => ['catalog.delete', CatalogPolicy::canDelete($user)],
            'archive' => ['catalog.archive', CatalogPolicy::canArchive($user)],
            'publish' => ['catalog.publish', CatalogPolicy::canPublish($user)],
            'unpublish' => ['catalog.unpublish', CatalogPolicy::canUnpublish($user)],
        ];

        foreach ($abilities as $ability => [$permission, $expected]) {
            expect($user->can($permission))->toBe($expected, "{$permission} disagrees with the policy");

            foreach (CatalogPolicy::MODELS as $model) {
                expect(Gate::forUser($user)->allows($ability, $model))->toBe($expected, "{$ability} on {$model} disagrees with the policy");
            }
        }
    })->with(productRestrictionIdentities());

    it('grants the platform roles exactly what they hold', function () {
        $manager = testPlatformStaff(PlatformRole::ProductManager);
        $admin = testPlatformStaff(PlatformRole::Admin);
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $outsider = testPlatformStaff(PlatformRole::SmsManager);

        expect(CatalogPolicy::canCreate($manager) && CatalogPolicy::canPublish($manager))->toBeTrue()
            ->and(CatalogPolicy::canCreate($admin))->toBeTrue()
            ->and(CatalogPolicy::canPublish($admin))->toBeFalse()
            ->and(CatalogPolicy::canViewAny($viewer))->toBeTrue()
            ->and(CatalogPolicy::canWriteAny($viewer))->toBeFalse()
            ->and(CatalogPolicy::canViewAny($outsider))->toBeFalse();
    });

    it('refuses every catalogue ability to anybody who trades on the platform, whatever they were given', function (string $identity) {
        $user = productRestrictionIdentities()[$identity]();

        expect(CatalogPolicy::isBusinessIdentity($user))->toBeTrue()
            ->and(CatalogPolicy::canViewAny($user))->toBeFalse()
            ->and(CatalogPolicy::canWriteAny($user))->toBeFalse()
            ->and(Gate::forUser($user)->allows('create', Product::class))->toBeFalse()
            ->and(Gate::forUser($user)->allows('update', $this->product))->toBeFalse();
    })->with([
        'business owner holding catalogue permissions',
        'business owner holding the super admin role',
        'business manager',
    ]);

    it('leaves the super admin override in place for everything that is not the catalogue', function () {
        $owner = productRestrictionIdentities()['business owner holding the super admin role']();

        expect($owner->can('wallet.view'))->toBeTrue()
            ->and($owner->can('catalog.view'))->toBeFalse();
    });
});

describe('the actions refuse on their own', function () {
    it('refuses to create a product, category, brand or variation for an unauthorised actor', function (Closure $identity) {
        Storage::fake('public');
        $actor = $identity();

        expect(fn () => app(ManageProducts::class)->create($actor, ['name' => 'Mine', 'sku' => 'MINE-1', 'category_id' => $this->category->public_id]))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageCategories::class)->create($actor, ['name' => 'Mine'], UploadedFile::fake()->image('tile.png')))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageBrands::class)->create($actor, ['name' => 'Mine']))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageVariants::class)->create($actor, $this->product, ['sku' => 'MINE-V', 'values' => []]))
            ->toThrow(AuthorizationException::class);

        expect(Product::query()->count())->toBe(1)
            ->and(Category::query()->count())->toBe(1)
            ->and(Brand::query()->count())->toBe(0)
            ->and(ProductVariant::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'business owner holding catalogue permissions' => fn () => productRestrictionIdentities()['business owner holding catalogue permissions'](),
        'inventory manager (view only)' => fn () => testPlatformStaff(PlatformRole::InventoryManager),
    ]);

    it('refuses edits and deletes to an actor who may only view', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);

        expect(fn () => app(ManageProducts::class)->update($viewer, $this->product, ['name' => 'Renamed']))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageProducts::class)->trash($viewer, $this->product, 'Test.'))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ManageCategories::class)->setActive($viewer, $this->category, false))
            ->toThrow(AuthorizationException::class);

        expect($this->product->refresh()->name)->toBe('Rice cooker')
            ->and($this->category->refresh()->is_active)->toBeTrue();
    });
});

describe('the endpoints refuse before reading the request', function () {
    it('refuses a partner holding catalogue permissions at every create endpoint', function () {
        $owner = productRestrictionIdentities()['business owner holding catalogue permissions']();
        $attribute = ProductAttribute::create(['name' => 'Size']);

        $this->actingAs($owner)->post(route('admin.catalog.products.store'), [])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.catalog.categories.store'), [])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.catalog.brands.store'), [])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.catalog.products.variants.store', $this->product->public_id), [])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.catalog.attributes.store'), [])->assertForbidden();
        $this->actingAs($owner)->post(route('admin.catalog.attributes.values.store', $attribute->public_id), [])->assertForbidden();

        expect(ProductAttributeValue::query()->count())->toBe(0);
    });

    it('refuses the lifecycle and channel switches with a 403 rather than a list of missing fields', function (Closure $identity) {
        $user = $identity();

        $this->actingAs($user)
            ->patch(route('admin.catalog.products.status.update', $this->product->public_id), [])
            ->assertForbidden()
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($user)
            ->patch(route('admin.catalog.products.channels.update', [$this->product->public_id, 'wholesale']), [])
            ->assertForbidden()
            ->assertSessionDoesntHaveErrors();
    })->with([
        'business owner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
        'inventory manager (view only)' => fn () => testPlatformStaff(PlatformRole::InventoryManager),
    ]);

    it('still validates for somebody who may make the change', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->patch(route('admin.catalog.products.status.update', $this->product->public_id), [])
            ->assertSessionHasErrors('status');
    });

    it('gives a media upload the same refusal', function () {
        $this->actingAs(productRestrictionIdentities()['business owner holding catalogue permissions']())
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->image('front.png'),
            ])
            ->assertForbidden();

        expect(ProductMedia::query()->count())->toBe(0);
    });
});

describe('the navigation contract', function () {
    it('does not offer the catalogue to a partner holding a catalogue permission', function () {
        $owner = productRestrictionIdentities()['business owner holding catalogue permissions']();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['catalog.view'] === false));
    });

    it('offers it to staff who may view it, and not to staff who may not', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->get(route('admin.catalog.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['catalog.view'] === true));

        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.catalog.products.index'))
            ->assertForbidden();
    });
});
