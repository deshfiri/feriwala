<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Locked catalogue information, held by the database (P3-19, §12).
 *
 * Every write here goes straight to the query builder, around the policy, the
 * action and the request, because that is exactly the write these guards exist
 * for. One refused statement per test: Postgres aborts the surrounding
 * transaction on the first, so a second statement would fail for that reason
 * rather than its own.
 */

/**
 * One of each guarded row, and a second parent for every "moved to another"
 * case, so a refusal is the guard's and never a foreign key's.
 *
 * @return array<string, mixed>
 */
function catalogueGuardFixtures(): array
{
    $category = Category::create(['name' => 'Kitchen']);
    $brand = Brand::create(['name' => 'Walton']);
    $product = Product::create(['name' => 'Rice cooker', 'sku' => 'FW-RC', 'category_id' => $category->id]);
    $otherProduct = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $category->id]);
    $attribute = ProductAttribute::create(['name' => 'Size']);
    $otherAttribute = ProductAttribute::create(['name' => 'Colour']);
    $value = $attribute->values()->create(['value' => 'Large']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'k']);
    $otherVariant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FW-RC-M', 'combination_key' => 'm']);
    $media = ProductMedia::create([
        'product_id' => $product->id,
        'type' => 'image',
        'disk' => 'public',
        'path' => 'catalog/products/x/1.png',
        'mime_type' => 'image/png',
        'size_bytes' => 100,
        'position' => 1,
    ]);
    $tier = ProductPriceTier::create(['product_id' => $product->id, 'min_quantity' => 10, 'unit_price_minor' => 1000]);

    return compact('category', 'brand', 'product', 'otherProduct', 'attribute', 'otherAttribute', 'value', 'variant', 'otherVariant', 'media', 'tier');
}

describe('columns fixed once a row exists', function () {
    it('refuses a raw write that changes one', function (string $row, string $column, Closure $newValue) {
        $fixtures = catalogueGuardFixtures();
        $model = $fixtures[$row];

        expect(fn () => DB::table($model->getTable())->where('id', $model->id)->update([$column => $newValue($fixtures)]))
            ->toThrow(QueryException::class, "{$model->getTable()}.{$column} cannot be changed once written");
    })->with([
        'a product\'s public id' => ['product', 'public_id', fn () => (string) Str::ulid()],
        'a product\'s currency' => ['product', 'currency_code', fn () => 'USD'],
        'a variation\'s public id' => ['variant', 'public_id', fn () => (string) Str::ulid()],
        'a variation\'s product' => ['variant', 'product_id', fn (array $f) => $f['otherProduct']->id],
        'a variation\'s combination' => ['variant', 'combination_key', fn () => 'other'],
        'a variation\'s currency' => ['variant', 'currency_code', fn () => 'USD'],
        'a file\'s public id' => ['media', 'public_id', fn () => (string) Str::ulid()],
        'a file\'s product' => ['media', 'product_id', fn (array $f) => $f['otherProduct']->id],
        'a quantity band\'s product' => ['tier', 'product_id', fn (array $f) => $f['otherProduct']->id],
        'a quantity band\'s variation' => ['tier', 'product_variant_id', fn (array $f) => $f['otherVariant']->id],
        'a quantity band\'s currency' => ['tier', 'currency_code', fn () => 'USD'],
        'a category\'s public id' => ['category', 'public_id', fn () => (string) Str::ulid()],
        'a brand\'s public id' => ['brand', 'public_id', fn () => (string) Str::ulid()],
        'an attribute\'s public id' => ['attribute', 'public_id', fn () => (string) Str::ulid()],
        'a value\'s public id' => ['value', 'public_id', fn () => (string) Str::ulid()],
        'a value\'s attribute' => ['value', 'product_attribute_id', fn (array $f) => $f['otherAttribute']->id],
    ]);

    it('lets everything else change, and a guarded column be written back unchanged', function () {
        $fixtures = catalogueGuardFixtures();
        $product = $fixtures['product'];

        DB::table('products')->where('id', $product->id)->update([
            'name' => 'Rice cooker 2.8L',
            'public_id' => $product->public_id,
            'currency_code' => $product->currency_code,
        ]);
        DB::table('product_media')->where('id', $fixtures['media']->id)->update(['position' => 2, 'product_id' => $product->id]);
        DB::table('product_price_tiers')->where('id', $fixtures['tier']->id)->update(['min_quantity' => 20]);
        DB::table('product_variants')->where('id', $fixtures['variant']->id)->update(['sku' => 'FW-RC-XL', 'combination_key' => 'k']);

        expect($product->refresh()->name)->toBe('Rice cooker 2.8L')
            ->and($fixtures['media']->refresh()->position)->toBe(2)
            ->and($fixtures['tier']->refresh()->min_quantity)->toBe(20)
            ->and($fixtures['variant']->refresh()->sku)->toBe('FW-RC-XL');
    });
});

describe('when a product first went live', function () {
    it('is stamped once', function () {
        $product = catalogueGuardFixtures()['product'];

        DB::table('products')->where('id', $product->id)->update(['published_at' => '2026-09-01 10:00:00']);

        expect($product->refresh()->published_at?->toDateTimeString())->toBe('2026-09-01 10:00:00');
    });

    it('is never moved, or cleared, afterwards', function (?string $replacement) {
        $product = catalogueGuardFixtures()['product'];
        $product->forceFill(['published_at' => '2026-09-01 10:00:00'])->save();

        expect(fn () => DB::table('products')->where('id', $product->id)->update(['published_at' => $replacement]))
            ->toThrow(QueryException::class, 'products.published_at is set once');
    })->with([
        'moved' => ['2026-09-10 10:00:00'],
        'cleared' => [null],
    ]);
});

describe('removing a product', function () {
    it('removes a draft', function () {
        $product = catalogueGuardFixtures()['otherProduct'];

        DB::table('products')->where('id', $product->id)->delete();

        expect(Product::query()->whereKey($product->id)->exists())->toBeFalse();
    });

    it('refuses to remove anything further along than a draft', function (string $status) {
        $product = catalogueGuardFixtures()['otherProduct'];
        DB::table('products')->where('id', $product->id)->update(['status' => $status]);

        expect(fn () => DB::table('products')->where('id', $product->id)->delete())
            ->toThrow(QueryException::class, "A {$status} product cannot be deleted");
    })->with(['pending_review', 'active', 'inactive', 'discontinued', 'archived']);
});
