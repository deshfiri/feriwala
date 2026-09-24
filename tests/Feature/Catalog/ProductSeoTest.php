<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Enums\ItemCondition;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductSeo;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SEO metadata and product schema for partner websites (P3-12, §11.1, §34.3).
 *
 * Everything a partner page renders is built on the server from the catalogue's
 * record. The schema's price is the website's selling price and nothing else:
 * the wholesale price and base cost must never reach a public page's source.
 * Availability is stated only when it is known.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->seo = app(ProductSeo::class);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->cookware = Category::create(['name' => 'Cookware', 'parent_id' => $this->kitchen->id]);

    $this->product = Product::create([
        'name' => 'Walton Rice Cooker 2.8L',
        'sku' => 'FW-RC-28',
        'barcode' => '8941100500012',
        'short_description' => 'A non-stick rice cooker that keeps rice warm for up to twelve hours without drying it out, with a steamer tray for vegetables and fish, a measuring cup, and a cool-touch handle for carrying it to the table.',
        'category_id' => $this->cookware->id,
        'brand_id' => Brand::create(['name' => 'Walton'])->id,
        'base_cost' => Money::fromDecimal('1700.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
    ]);
});

function catalogSeoPayload(Product $product, array $overrides = []): array
{
    return [
        'name' => $product->name,
        'sku' => $product->sku,
        'category_id' => Category::query()->where('id', $product->category_id)->value('public_id'),
        'base_cost' => '1700.00',
        'wholesale_price' => '2100.00',
        ...$overrides,
    ];
}

function catalogSeoImage(Product $product, int $position, string $alt): ProductMedia
{
    return ProductMedia::create([
        'product_id' => $product->id,
        'type' => 'image',
        'disk' => 'public',
        'path' => "catalog/products/{$product->public_id}/{$position}.png",
        'mime_type' => 'image/png',
        'size_bytes' => 100,
        'alt_text' => $alt,
        'position' => $position,
    ]);
}

describe('saving the fields', function () {
    it('saves SEO copy, the MPN, the condition and a sharing image of this product', function () {
        catalogSeoImage($this->product, 1, 'Front');
        $side = catalogSeoImage($this->product, 2, 'Side');

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogSeoPayload($this->product, [
                'meta_title' => 'Walton 2.8L rice cooker',
                'meta_description' => 'Keeps rice warm for twelve hours.',
                'mpn' => 'WRC-28/NS',
                'item_condition' => 'refurbished',
                'social_image_id' => $side->public_id,
            ]))
            ->assertSessionHasNoErrors();

        $this->product->refresh();

        expect($this->product->meta_title)->toBe('Walton 2.8L rice cooker')
            ->and($this->product->mpn)->toBe('WRC-28/NS')
            ->and($this->product->item_condition)->toBe(ItemCondition::Refurbished)
            ->and($this->product->social_media_id)->toBe($side->id);
    });

    it('refuses copy longer than a search engine shows, an unknown condition and an odd MPN', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogSeoPayload($this->product, [
                'meta_title' => str_repeat('a', 71),
                'meta_description' => str_repeat('a', 201),
                'item_condition' => 'mint',
                'mpn' => 'WRC<script>',
            ]))
            ->assertSessionHasErrors(['meta_title', 'meta_description', 'item_condition', 'mpn']);
    });

    it('refuses a sharing image that belongs to another product', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->cookware->id]);
        $foreign = catalogSeoImage($other, 1, 'Other front');

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogSeoPayload($this->product, [
                'social_image_id' => $foreign->public_id,
            ]))
            ->assertSessionHasErrors('social_image_id');

        expect($this->product->refresh()->social_media_id)->toBeNull();
    });

    it('lets go of a sharing image when that file is removed', function () {
        $image = catalogSeoImage($this->product, 1, 'Front');
        $this->product->forceFill(['social_media_id' => $image->id])->save();

        $image->delete();

        expect($this->product->refresh()->social_media_id)->toBeNull();
    });

    it('holds the condition to known values in the database', function () {
        expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['item_condition' => 'mint']))
            ->toThrow(QueryException::class, 'products_item_condition_known');
    });

    it('is not something a business account can write (§12)', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogSeoPayload($this->product, [
                'meta_title' => 'Mine',
            ]))
            ->assertForbidden();

        expect($this->product->refresh()->meta_title)->toBeNull();
    });
});

describe('the metadata a partner page renders', function () {
    it('falls back to the product’s own name and short description, shortened at a word', function () {
        $metadata = $this->seo->metadata($this->product);

        expect($metadata['title'])->toBe('Walton Rice Cooker 2.8L')
            ->and(mb_strlen((string) $metadata['description']))->toBeLessThanOrEqual(ProductSeo::DESCRIPTION_LENGTH)
            ->and($metadata['description'])->toEndWith('…')
            ->and($metadata['description'])->toStartWith('A non-stick rice cooker');
    });

    it('prefers the SEO copy and the chosen sharing image, else the first image', function () {
        catalogSeoImage($this->product, 1, 'Front');

        expect($this->seo->metadata($this->product)['image']['alt'] ?? null)->toBe('Front');

        $side = catalogSeoImage($this->product, 2, 'Side');
        $this->product->forceFill(['meta_title' => 'Rice cooker', 'social_media_id' => $side->id])->save();

        $metadata = $this->seo->metadata($this->product->refresh());

        expect($metadata['title'])->toBe('Rice cooker')
            ->and($metadata['image']['alt'] ?? null)->toBe('Side');
    });
});

describe('the product schema', function () {
    it('describes the product from the catalogue’s own fields', function () {
        $this->product->forceFill(['mpn' => 'WRC-28'])->save();
        catalogSeoImage($this->product, 1, 'Front');

        $schema = $this->seo->schema($this->product->refresh(), '/products/walton-rice-cooker', Money::fromDecimal('2490.00'));

        expect($schema['@type'])->toBe('Product')
            ->and($schema['sku'])->toBe('FW-RC-28')
            ->and($schema['gtin13'])->toBe('8941100500012')
            ->and($schema['mpn'])->toBe('WRC-28')
            ->and($schema['brand'])->toBe(['@type' => 'Brand', 'name' => 'Walton'])
            ->and($schema['itemCondition'])->toBe('https://schema.org/NewCondition')
            ->and($schema['image'])->toHaveCount(1)
            ->and($schema['offers']['price'])->toBe('2490.00')
            ->and($schema['offers']['priceCurrency'])->toBe('BDT');
    });

    it('never carries the wholesale price or the base cost', function () {
        $json = json_encode($this->seo->schema($this->product, '/p', Money::fromDecimal('2490.00')));

        expect($json)->not->toContain('2100')
            ->and($json)->not->toContain('1700')
            ->and($json)->not->toContain('210000')
            ->and($json)->not->toContain('170000');
    });

    it('has no offer without a selling price, and no availability until stock is known', function () {
        expect($this->seo->schema($this->product, '/p'))->not->toHaveKey('offers');

        $unknown = $this->seo->schema($this->product, '/p', Money::fromDecimal('2490.00'));
        $known = $this->seo->schema($this->product, '/p', Money::fromDecimal('2490.00'), inStock: false);

        expect($unknown['offers'])->not->toHaveKey('availability')
            ->and($known['offers']['availability'])->toBe('https://schema.org/OutOfStock');
    });

    it('names a GTIN by its length, and falls back to the generic key', function () {
        $this->product->forceFill(['barcode' => 'ABC-123'])->save();

        expect($this->seo->schema($this->product, '/p'))->toHaveKey('gtin')->not->toHaveKey('gtin13');
    });

    it('builds the breadcrumb trail from the category tree', function () {
        $trail = $this->seo->breadcrumbs(
            $this->product,
            fn (Category $category) => '/categories/'.$category->slug,
            '/products/'.$this->product->slug,
        );

        expect($trail['@type'])->toBe('BreadcrumbList')
            ->and(array_column($trail['itemListElement'], 'name'))->toBe(['Kitchen', 'Cookware', 'Walton Rice Cooker 2.8L'])
            ->and(array_column($trail['itemListElement'], 'position'))->toBe([1, 2, 3]);
    });

    it('previews both on the editor, built by the server', function () {
        $this->product->forceFill(['suggested_selling_price' => Money::fromDecimal('2490.00', Currency::BDT)])->save();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('seo_preview.metadata.title', 'Walton Rice Cooker 2.8L')
                ->where('seo_preview.schema', fn (string $json) => str_contains($json, '"price": "2490.00"')
                    && ! str_contains($json, '2100.00'))
                ->where('product.item_condition', 'new'),
            );
    });
});
