<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * Adding a product can put it into a sourcing group in the same step, from a
 * pop-up on the add page -- optional, never blocking, and only for staff who
 * may change sourcing groups.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->group = app(ManageSourcingGroups::class)->create($this->manager, ['code' => 'pants', 'name_en' => 'Pants', 'name_bn' => 'প্যান্ট']);
    Category::create(['name' => 'Clothing']);
});

function productCreatePayload(array $overrides = []): array
{
    return [
        'name' => 'Cotton pants',
        'sku' => 'CP100ABCD',
        'category_id' => Category::query()->value('public_id'),
        'base_cost' => '700.00',
        'wholesale_price' => '900.00',
        ...$overrides,
    ];
}

it('offers the groups on the add page to staff who may use them', function () {
    $this->actingAs($this->manager)->get(route('admin.catalog.products.create'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/catalog/products/form')
            ->where('sourcing.can_select', true)
            ->where('sourcing.can_create', true)
            ->has('sourcing.groups', 1)
            ->where('sourcing.groups.0.id', $this->group->public_id));
});

it('adds the new product to the chosen group in the same step, as its canonical product when first', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => $this->group->public_id]))
        ->assertSessionHasNoErrors();

    $product = Product::query()->where('sku', 'CP100ABCD')->firstOrFail();
    $membership = ProductSourcingGroupProduct::query()->active()->where('product_id', $product->id)->firstOrFail();

    expect($membership->sourcing_group_id)->toBe($this->group->id)
        ->and($membership->is_canonical)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'sourcing_group.product_added')->exists())->toBeTrue();
});

it('creates the product normally when no group is chosen', function () {
    $this->actingAs($this->manager)->post(route('admin.catalog.products.store'), productCreatePayload())->assertSessionHasNoErrors();

    expect(Product::query()->where('sku', 'CP100ABCD')->exists())->toBeTrue()
        ->and(ProductSourcingGroupProduct::query()->count())->toBe(0);
});

it('rejects an unknown group without creating the product', function () {
    $this->actingAs($this->manager)->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => 'nope']))
        ->assertSessionHasErrors('sourcing_group_id');

    expect(Product::query()->where('sku', 'CP100ABCD')->exists())->toBeFalse();
});

it('still creates the product but warns when the group is inactive', function () {
    app(ManageSourcingGroups::class)->setActive($this->manager, $this->group, false, 'Paused.');

    $this->actingAs($this->manager)
        ->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => $this->group->public_id]))
        ->assertSessionHasNoErrors();

    expect(Product::query()->where('sku', 'CP100ABCD')->exists())->toBeTrue()
        ->and(ProductSourcingGroupProduct::query()->count())->toBe(0);
});

it('never stores the group on the product itself', function () {
    $this->actingAs($this->manager)->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => $this->group->public_id]));

    expect(array_key_exists('sourcing_group_id', Product::query()->where('sku', 'CP100ABCD')->firstOrFail()->getAttributes()))->toBeFalse();
});

it('creates the product but does not assign it when the staff member may not change groups', function () {
    $staff = testPlatformStaff(PlatformRole::ProductManager);
    $staff->revokePermissionTo('sourcing_group.edit');
    $staff->roles->each(fn ($role) => $role->revokePermissionTo('sourcing_group.edit'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($staff->fresh())
        ->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => $this->group->public_id]));

    expect(Product::query()->where('sku', 'CP100ABCD')->exists())->toBeTrue()
        ->and(ProductSourcingGroupProduct::query()->count())->toBe(0);

    $this->get(route('admin.catalog.products.create'))
        ->assertInertia(fn (Assert $page) => $page->where('sourcing.can_select', false));
});
