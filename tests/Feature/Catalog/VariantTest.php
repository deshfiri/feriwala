<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Product variations (P3-4, §11.1, §12).
 *
 * §12 names "unauthorized product variations" among what a regular user may not
 * create, so the refusals come first. After them, the four rules the database
 * holds even when a request never went through the form: one value per
 * attribute, no repeated combination, one SKU and barcode namespace across
 * products and variants, and figures that are never negative.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Cotton Panjabi',
        'sku' => 'FW-1043',
        'barcode' => '8941100500012',
        'category_id' => Category::create(['name' => 'Clothing'])->id,
        'base_cost' => Money::fromDecimal('1500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2490.00', Currency::BDT),
    ]);

    $this->size = catalogVariantAttribute('Size', ['M', 'L']);
    $this->colour = catalogVariantAttribute('Colour', ['Navy', 'Red']);
});

function catalogVariantAttribute(string $name, array $values): ProductAttribute
{
    $attribute = ProductAttribute::create(['name' => $name]);

    foreach ($values as $position => $value) {
        ProductAttributeValue::create([
            'product_attribute_id' => $attribute->id,
            'value' => $value,
            'sort_order' => $position,
        ]);
    }

    return $attribute;
}

function catalogVariantValue(ProductAttribute $attribute, string $value): string
{
    return (string) $attribute->values()->where('value', $value)->value('public_id');
}

describe('only the platform creates variations (§12)', function () {
    it('refuses a business account holder at every variation endpoint', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-1043-M',
                'values' => [catalogVariantValue($this->size, 'M')],
            ])
            ->assertForbidden();

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FW-1043-L',
            'combination_key' => 'x',
        ]);

        $this->actingAs($owner)
            ->patch(route('admin.catalog.products.variants.update', [$this->product->public_id, $variant->public_id]), [
                'sku' => 'FW-1043-L',
                'wholesale_price' => '0.01',
            ])
            ->assertForbidden();
        $this->actingAs($owner)
            ->delete(route('admin.catalog.products.variants.destroy', [$this->product->public_id, $variant->public_id]))
            ->assertForbidden();

        expect(ProductVariant::query()->count())->toBe(1)
            ->and($variant->refresh()->wholesale_price)->toBeNull();
    });

    it('refuses staff who may only read the catalogue', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-1043-M',
                'values' => [catalogVariantValue($this->size, 'M')],
            ])
            ->assertForbidden();

        expect(ProductVariant::query()->count())->toBe(0);
    });
});

describe('building a variation', function () {
    it('creates one from a combination, with the product price applying when none is given', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'fw-1043-m-nvy',
                'values' => [catalogVariantValue($this->size, 'M'), catalogVariantValue($this->colour, 'Navy')],
            ])
            ->assertSessionHasNoErrors();

        $variant = ProductVariant::query()->with('values')->firstOrFail();

        expect($variant->sku)->toBe('FW-1043-M-NVY')
            ->and($variant->values)->toHaveCount(2)
            ->and($variant->wholesale_price)->toBeNull()
            ->and($variant->effectiveWholesalePrice()->toDecimal())->toBe('2490.00');
    });

    it('holds its own price as Money when one is given', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-1043-L',
                'values' => [catalogVariantValue($this->size, 'L')],
                'wholesale_price' => '2590.00',
            ])
            ->assertSessionHasNoErrors();

        $variant = ProductVariant::query()->firstOrFail();

        expect($variant->wholesale_price)->toBeInstanceOf(Money::class)
            ->and($variant->effectiveWholesalePrice()->toDecimal())->toBe('2590.00');
    });

    it('refuses two values of one attribute', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-1043-ML',
                'values' => [catalogVariantValue($this->size, 'M'), catalogVariantValue($this->size, 'L')],
            ])
            ->assertSessionHasErrors('values');

        expect(ProductVariant::query()->count())->toBe(0);
    });

    it('refuses a combination the product already has, whatever order it was chosen in', function () {
        $url = route('admin.catalog.products.variants.store', $this->product->public_id);

        $this->actingAs($this->manager)->post($url, [
            'sku' => 'FW-1043-A',
            'values' => [catalogVariantValue($this->size, 'M'), catalogVariantValue($this->colour, 'Navy')],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post($url, [
            'sku' => 'FW-1043-B',
            'values' => [catalogVariantValue($this->colour, 'Navy'), catalogVariantValue($this->size, 'M')],
        ])->assertSessionHasErrors('values');

        expect(ProductVariant::query()->count())->toBe(1);
    });

    it('holds the combination unique in the database', function () {
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-A', 'combination_key' => '1-3']);

        expect(fn () => ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-B', 'combination_key' => '1-3']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('holds one value per attribute in the database', function () {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-A', 'combination_key' => 'k']);
        $values = $this->size->values;

        expect(fn () => DB::table('product_variant_values')->insert([
            ['product_variant_id' => $variant->id, 'product_attribute_id' => $this->size->id, 'product_attribute_value_id' => $values[0]->id],
            ['product_variant_id' => $variant->id, 'product_attribute_id' => $this->size->id, 'product_attribute_value_id' => $values[1]->id],
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a variation chosen by different attributes from its siblings', function () {
        $url = route('admin.catalog.products.variants.store', $this->product->public_id);

        $this->actingAs($this->manager)->post($url, [
            'sku' => 'FW-1043-A',
            'values' => [catalogVariantValue($this->size, 'M'), catalogVariantValue($this->colour, 'Navy')],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post($url, [
            'sku' => 'FW-1043-B',
            'values' => [catalogVariantValue($this->size, 'L')],
        ])->assertSessionHasErrors('values');
    });
});

describe('one identifier namespace across products and variations', function () {
    it('refuses a variation SKU that a product holds', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'fw-1043',
                'values' => [catalogVariantValue($this->size, 'M')],
            ])
            ->assertSessionHasErrors('sku');
    });

    it('refuses a product SKU that a variation holds', function () {
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-2000', 'combination_key' => 'k']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), [
                'name' => 'Another',
                'sku' => 'FW-2000',
                'category_id' => Category::query()->value('public_id'),
                'base_cost' => '0.01',
                'wholesale_price' => '0.01',
            ])
            ->assertSessionHasErrors('sku');
    });

    it('holds the SKU namespace in the database, from the variation side', function () {
        expect(fn () => ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-1043', 'combination_key' => 'k']))
            ->toThrow(UniqueConstraintViolationException::class, 'SKU FW-1043 is already used in products');
    });

    it('holds the SKU namespace in the database, from the product side', function () {
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-2000', 'combination_key' => 'k']);

        expect(fn () => Product::create([
            'name' => 'Another',
            'sku' => 'FW-2000',
            'category_id' => $this->product->category_id,
        ]))->toThrow(UniqueConstraintViolationException::class, 'SKU FW-2000 is already used in product_variants');
    });

    it('holds the barcode namespace in the database', function () {
        expect(fn () => ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FW-1043-M',
            'barcode' => '8941100500012',
            'combination_key' => 'k',
        ]))->toThrow(UniqueConstraintViolationException::class, 'Barcode 8941100500012 is already used in products');
    });

    it('refuses a negative price override in the database', function () {
        expect(fn () => ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FW-1043-M',
            'combination_key' => 'k',
            'wholesale_price' => Money::fromDecimal('-0.01', Currency::BDT),
        ]))->toThrow(QueryException::class, 'product_variants_wholesale_price_not_negative');
    });
});

describe('editing and removing a variation', function () {
    beforeEach(function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-1043-M',
                'values' => [catalogVariantValue($this->size, 'M')],
            ]);

        $this->variant = ProductVariant::query()->firstOrFail();
    });

    it('changes its figures and availability but never its combination', function () {
        $url = route('admin.catalog.products.variants.update', [$this->product->public_id, $this->variant->public_id]);

        $this->actingAs($this->manager)->patch($url, [
            'sku' => 'FW-1043-M',
            'values' => [catalogVariantValue($this->size, 'L')],
        ])->assertSessionHasErrors('values');

        $this->actingAs($this->manager)->patch($url, [
            'sku' => 'FW-1043-M',
            'wholesale_price' => '2390.00',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->variant->refresh();

        expect($this->variant->is_active)->toBeFalse()
            ->and($this->variant->wholesale_price?->toDecimal())->toBe('2390.00')
            ->and($this->variant->values()->pluck('value')->all())->toBe(['M']);
    });

    it('cannot be reached through another product', function () {
        $other = Product::create([
            'name' => 'Other',
            'sku' => 'FW-9',
            'category_id' => $this->product->category_id,
        ]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.variants.update', [$other->public_id, $this->variant->public_id]), [
                'sku' => 'FW-1043-M',
                'is_active' => false,
            ])
            ->assertNotFound();

        expect($this->variant->refresh()->is_active)->toBeTrue();
    });

    it('is deleted while its product is a draft', function () {
        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.variants.destroy', [$this->product->public_id, $this->variant->public_id]))
            ->assertSessionHasNoErrors();

        expect(ProductVariant::query()->count())->toBe(0)
            ->and(DB::table('product_variant_values')->count())->toBe(0);
    });

    it('is switched off rather than deleted once its product has left draft', function () {
        $this->product->forceFill(['status' => 'active'])->save();

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.variants.destroy', [$this->product->public_id, $this->variant->public_id]))
            ->assertSessionHasErrors('variant');

        expect(ProductVariant::query()->count())->toBe(1);
    });

    it('is shown on the product editor with its combination and the price that applies', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('variants', 1)
                ->where('variants.0.label', 'M')
                ->where('variants.0.sku', 'FW-1043-M')
                ->where('variants.0.overrides_price', false)
                ->where('variants.0.wholesale_price.amount', '2490.00')
                ->has('attributes', 2),
            );
    });
});
