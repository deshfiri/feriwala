<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Shared attributes and their values (P3-4, §11.1, §12).
 *
 * One attribute per name whatever the casing, one value per attribute whatever
 * the casing, and nothing a variation carries is ever removed from under it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
});

function catalogAttributeInUse(ProductAttributeValue $value): ProductVariant
{
    $product = Product::create([
        'name' => 'Panjabi',
        'sku' => 'FW-'.$value->id,
        'category_id' => Category::query()->value('id') ?? Category::create(['name' => 'Clothing'])->id,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'FW-'.$value->id.'-V',
        'combination_key' => (string) $value->id,
    ]);

    DB::table('product_variant_values')->insert([
        'product_variant_id' => $variant->id,
        'product_attribute_id' => $value->product_attribute_id,
        'product_attribute_value_id' => $value->id,
    ]);

    return $variant;
}

describe('only the platform writes attributes (§12)', function () {
    it('refuses a business account holder', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)->get(route('admin.catalog.attributes.index'))->assertForbidden();
        $this->actingAs($owner)
            ->post(route('admin.catalog.attributes.store'), ['name' => 'Size'])
            ->assertForbidden();

        expect(ProductAttribute::query()->count())->toBe(0);
    });

    it('lets staff who may read the catalogue see attributes but not add to them', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $size = ProductAttribute::create(['name' => 'Size']);

        $this->actingAs($viewer)->get(route('admin.catalog.attributes.index'))->assertOk();
        $this->actingAs($viewer)
            ->post(route('admin.catalog.attributes.store'), ['name' => 'Colour'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('admin.catalog.attributes.values.store', $size->public_id), ['value' => 'M'])
            ->assertForbidden();

        expect(ProductAttribute::query()->count())->toBe(1)
            ->and(ProductAttributeValue::query()->count())->toBe(0);
    });
});

describe('names and values', function () {
    it('adds an attribute and its values', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.attributes.store'), ['name' => 'Size'])
            ->assertSessionHasNoErrors();

        $size = ProductAttribute::query()->firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.attributes.values.store', $size->public_id), ['value' => 'M'])
            ->assertSessionHasNoErrors();

        expect($size->values()->pluck('value')->all())->toBe(['M']);
    });

    it('refuses an attribute name that exists in different capitals', function () {
        ProductAttribute::create(['name' => 'Colour']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.attributes.store'), ['name' => 'COLOUR'])
            ->assertSessionHasErrors('name');
    });

    it('holds attribute names unique in the database whatever the casing', function () {
        ProductAttribute::create(['name' => 'Colour']);

        expect(fn () => ProductAttribute::create(['name' => 'colour', 'slug' => 'colour-2']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a value the attribute already has, and allows it on another attribute', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        $trim = ProductAttribute::create(['name' => 'Trim']);
        ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.attributes.values.store', $colour->public_id), ['value' => 'navy'])
            ->assertSessionHasErrors('value');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.attributes.values.store', $trim->public_id), ['value' => 'Navy'])
            ->assertSessionHasNoErrors();
    });

    it('holds values unique per attribute in the database', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy']);

        expect(fn () => ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'NAVY']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('renames a value in use, because correcting a typo is not a change of meaning', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        $value = ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Nvy']);
        catalogAttributeInUse($value);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.attribute-values.update', $value->public_id), ['value' => 'Navy'])
            ->assertSessionHasNoErrors();

        expect($value->refresh()->value)->toBe('Navy');
    });
});

describe('nothing is removed from under a variation', function () {
    it('refuses to remove a value a variation carries, at the action and in the database', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        $value = ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy']);
        catalogAttributeInUse($value);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.attribute-values.destroy', $value->public_id))
            ->assertSessionHasErrors('value');

        expect(fn () => ProductAttributeValue::query()->whereKey($value->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses to remove an attribute any of whose values is in use', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        $value = ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy']);
        catalogAttributeInUse($value);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.attributes.destroy', $colour->public_id))
            ->assertSessionHasErrors('attribute');

        expect(ProductAttribute::query()->count())->toBe(1);
    });

    it('removes an unused attribute together with its unused values', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy']);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.attributes.destroy', $colour->public_id))
            ->assertSessionHasNoErrors();

        expect(ProductAttribute::query()->count())->toBe(0)
            ->and(ProductAttributeValue::query()->count())->toBe(0);
    });

    it('shows how many variations use each value', function () {
        $colour = ProductAttribute::create(['name' => 'Colour']);
        $navy = ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Navy', 'sort_order' => 0]);
        ProductAttributeValue::create(['product_attribute_id' => $colour->id, 'value' => 'Red', 'sort_order' => 1]);
        catalogAttributeInUse($navy);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.attributes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/attributes')
                ->where('attributes.0.uses', 1)
                ->where('attributes.0.values.0.uses', 1)
                ->where('attributes.0.values.1.uses', 0),
            );
    });
});
