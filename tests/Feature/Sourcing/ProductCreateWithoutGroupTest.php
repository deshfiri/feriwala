<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Adding a product no longer involves a sourcing group: which Products are the
 * same physical Product is decided afterwards, by linking, and never at
 * creation.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
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

it('no longer offers sourcing groups on the add page', function () {
    $this->actingAs($this->manager)->get(route('admin.catalog.products.create'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/catalog/products/form')
            ->missing('sourcing'));
});

it('creates the product on its own, linked to nothing', function () {
    $this->actingAs($this->manager)->post(route('admin.catalog.products.store'), productCreatePayload())->assertSessionHasNoErrors();

    $product = Product::query()->where('sku', 'CP100ABCD')->firstOrFail();

    $this->actingAs($this->manager)->get(route('admin.catalog.products.edit', $product->public_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_products.direct', [])
            ->where('linked_products.indirect', []));
});

it('ignores a sourcing group field rather than storing it on the product', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.catalog.products.store'), productCreatePayload(['sourcing_group_id' => 'anything']))
        ->assertSessionHasNoErrors();

    expect(array_key_exists('sourcing_group_id', Product::query()->where('sku', 'CP100ABCD')->firstOrFail()->getAttributes()))->toBeFalse();
});
