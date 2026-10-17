<?php

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Finding products in a storefront's catalogue: search, filters, sorting and
 * facets (contract §5.1, P2 C1, C2, H7).
 *
 * Every answer is narrower than what the website has on sale, never wider.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website);
});

/**
 * A published selection at `$price` Taka, its product named `$name`.
 *
 * @param  array<string, mixed>  $selection
 * @param  array<string, mixed>  $product
 */
function discoverySelection(string $name, string $price, array $selection = [], array $product = [], ?Website $website = null): WebsiteProduct
{
    $website ??= test()->website;

    $selection = WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct(['name' => $name, ...$product])->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price, Currency::BDT),
        'published_at' => now(),
        ...$selection,
    ]);

    websiteTestOwnsSelection($selection, 100);

    return $selection;
}

/**
 * @param  array<string, mixed>  $query
 */
function discoveryList(array $query = [], string $path = 'products'): TestResponse
{
    return storefrontCall(test()->credential, test()->secret, $path, $query);
}

/**
 * The product names on every page of a listing, walked one product per page.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function discoveryWalk(array $query): array
{
    $names = [];
    $cursor = null;

    do {
        $page = discoveryList([...$query, 'limit' => 1, ...($cursor === null ? [] : ['cursor' => $cursor])])->assertOk();
        $names = [...$names, ...array_column($page->json('data'), 'name')];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null && count($names) < 50);

    return $names;
}

/**
 * An active variation of `$product` carrying `$values` (attribute slug => value).
 *
 * @param  array<string, string>  $values
 */
function discoveryVariant(Product $product, string $sku, array $values): ProductVariant
{
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => $sku, 'combination_key' => Str::lower($sku), 'is_active' => true]);

    foreach ($values as $slug => $value) {
        $attribute = ProductAttribute::query()->firstOrCreate(['slug' => $slug], ['name' => Str::title($slug)]);
        $record = ProductAttributeValue::query()->firstOrCreate(['product_attribute_id' => $attribute->id, 'value' => $value]);

        $variant->values()->attach($record->id, ['product_attribute_id' => $attribute->id]);
    }

    return $variant;
}

describe('searching (H7)', function () {
    it('finds by name, BPC and a variation\'s BPC, case-insensitively', function () {
        $kettle = discoverySelection('Electric Kettle', '2600.00');
        discoverySelection('Cotton panjabi', '2600.00');
        $shirt = discoverySelection('Formal shirt', '2600.00');
        discoveryVariant($shirt->product, 'SHIRT-NAVY-M', ['colour' => 'Navy']);

        discoveryList(['q' => 'kettle'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kettle->product->public_id);
        discoveryList(['q' => strtolower($kettle->product->sku)])->assertOk()->assertJsonCount(1, 'data');
        discoveryList(['q' => 'shirt-navy'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shirt->product->public_id);
    });

    it('treats % and _ as the characters they are, not as patterns', function () {
        discoverySelection('Discount 50% pack', '2600.00');
        discoverySelection('Plain pack', '2600.00');
        discoverySelection('Gift_box', '2600.00');
        discoverySelection('Giftsbox', '2600.00');

        discoveryList(['q' => '%'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Discount 50% pack');
        discoveryList(['q' => 'gift_'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Gift_box');
    });

    it('never finds what this website does not have on sale', function () {
        discoverySelection('Kettle on sale', '2600.00');
        discoverySelection('Kettle unpublished', '2600.00', ['status' => WebsiteProductStatus::Selected, 'published_at' => null]);
        discoverySelection('Kettle withdrawn', '2600.00', product: ['dropshipping_status' => ProductStatus::DropshippingDisabled]);
        discoverySelection('Kettle trashed', '2600.00')->product->delete();

        $other = Website::factory()->forAccount(websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']))->active()->create();
        discoverySelection('Kettle elsewhere', '2600.00', website: $other);

        discoveryList(['q' => 'kettle'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kettle on sale');
    });
});

describe('filtering (C1)', function () {
    it('filters on the price a customer is charged now, promotion included', function () {
        discoverySelection('Regular', '3000.00');
        discoverySelection('On promotion', '3000.00', ['promotional_price' => Money::fromDecimal('2100.00', Currency::BDT)]);
        discoverySelection('Promotion not started', '3000.00', [
            'promotional_price' => Money::fromDecimal('2100.00', Currency::BDT),
            'promotion_starts_at' => now()->addDay(),
        ]);

        expect(array_column(discoveryList(['price_max' => '2500'])->assertOk()->json('data'), 'name'))->toBe(['On promotion'])
            ->and(array_column(discoveryList(['price_min' => '2500.00'])->assertOk()->json('data'), 'name'))
            ->toEqualCanonicalizing(['Regular', 'Promotion not started']);
    });

    it('filters by brand, variation attribute, featured and the contract\'s status', function () {
        $brand = Brand::create(['name' => 'Walton']);
        $walton = discoverySelection('Walton kettle', '2600.00', ['is_featured' => true], ['brand_id' => $brand->id]);
        $shirt = discoverySelection('Shirt', '2600.00');
        discoveryVariant($shirt->product, 'SHIRT-NAVY-M', ['colour' => 'Navy', 'size' => 'M']);
        $other = discoverySelection('Other shirt', '2600.00');
        discoveryVariant($other->product, 'OTHER-RED-M', ['colour' => 'Red', 'size' => 'M']);

        discoveryList(['brand' => $brand->slug])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $walton->product->public_id);
        discoveryList(['brand' => $brand->public_id])->assertOk()->assertJsonCount(1, 'data');
        discoveryList(['attributes' => ['colour' => 'Navy']])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Shirt');
        discoveryList(['attributes' => ['size' => 'M']])->assertOk()->assertJsonCount(2, 'data');
        discoveryList(['attributes' => ['colour' => 'Red', 'size' => 'M']])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Other shirt');
        discoveryList(['featured' => 'true'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Walton kettle');
        discoveryList(['featured' => 'false'])->assertOk()->assertJsonCount(2, 'data');
        discoveryList(['status' => 'published'])->assertOk()->assertJsonCount(3, 'data');
    });

    it('finds a product under its catalogue category and that category\'s parents', function () {
        // First, so the fixture's default category is not the one under test.
        discoverySelection('Panjabi', '2600.00');
        $parent = Category::create(['name' => 'Appliances', 'slug' => 'appliances', 'is_active' => true]);
        $child = Category::create(['name' => 'Kettles', 'slug' => 'kettles', 'parent_id' => $parent->id, 'is_active' => true]);
        discoverySelection('Kettle', '2600.00', product: ['category_id' => $child->id]);

        discoveryList(['category' => 'appliances'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kettle');
        discoveryList(['category' => 'kettles'])->assertOk()->assertJsonCount(1, 'data');
        discoveryList(['category' => 'no-such-category'])->assertOk()->assertJsonCount(0, 'data');
    });

    it('filters stock by the figure the product shows, this account\'s allocation included', function () {
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        $stocked = discoverySelection('Stocked', '2600.00');
        $item = websiteTestOwnsSelection($stocked, 3);
        websiteTestSellOut(discoverySelection('Empty', '2600.00'));

        discoveryList(['in_stock' => 'true'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Stocked')
            ->assertJsonPath('data.0.variants.0.availability.in_stock', true);
        discoveryList(['in_stock' => '0'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Empty')
            ->assertJsonPath('data.0.variants.0.availability.in_stock', false);
    });

    it('lists flash deals: a promotion in force now that has an end', function () {
        discoverySelection('Flash deal', '3000.00', [
            'promotional_price' => Money::fromDecimal('2500.00', Currency::BDT),
            'promotion_starts_at' => now()->subHour(),
            'promotion_ends_at' => now()->addHour(),
        ]);
        discoverySelection('Standing promotion', '3000.00', ['promotional_price' => Money::fromDecimal('2500.00', Currency::BDT)]);
        discoverySelection('Deal over', '3000.00', [
            'promotional_price' => Money::fromDecimal('2500.00', Currency::BDT),
            'promotion_ends_at' => now()->subMinute(),
        ]);

        discoveryList(['flash_deal' => 'true'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Flash deal')
            ->assertJsonPath('data.0.variants.0.price.amount', '2500.00');
        discoveryList(['flash_deal' => 'false'])->assertOk()->assertJsonCount(2, 'data');
    });

    it('refuses filters it cannot honour', function (array $query) {
        discoveryList($query)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    })->with([
        'an unknown sort' => [['sort' => 'random']],
        'a status nobody can see' => [['status' => 'unpublished']],
        'a price with too many decimals' => [['price_min' => '10.005']],
        'a price that is not a number' => [['price_max' => 'cheap']],
        'a minimum above the maximum' => [['price_min' => '500', 'price_max' => '100']],
        'a flag that is not one' => [['in_stock' => 'maybe']],
        'too many attributes' => [['attributes' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6']]],
        'a search too long' => [['q' => str_repeat('a', 101)]],
    ]);
});

describe('sorting (C1)', function () {
    it('orders every sort completely, ties broken the same way on every page', function (string $sort, array $expected) {
        discoverySelection('Banana', '2000.00', ['is_featured' => true, 'display_order' => 2, 'published_at' => now()->subDays(3)]);
        discoverySelection('Apple', '3000.00', ['published_at' => now()->subDays(1)]);
        discoverySelection('Cherry', '2000.00', ['is_featured' => true, 'display_order' => 1, 'published_at' => now()->subDays(2)]);
        discoverySelection('Damson', '3500.00', [
            'promotional_price' => Money::fromDecimal('1000.00', Currency::BDT),
            'published_at' => now()->subDays(4),
        ]);

        expect(discoveryWalk(['sort' => $sort]))->toBe($expected);
    })->with([
        'featured' => ['featured', ['Cherry', 'Banana', 'Apple', 'Damson']],
        'newest' => ['newest', ['Apple', 'Cherry', 'Banana', 'Damson']],
        'price, lowest first, at the promotional price' => ['price_asc', ['Damson', 'Banana', 'Cherry', 'Apple']],
        'price, highest first' => ['price_desc', ['Apple', 'Banana', 'Cherry', 'Damson']],
        'name' => ['name', ['Apple', 'Banana', 'Cherry', 'Damson']],
    ]);

    it('keeps the order it always had when no sort is asked for', function () {
        $first = discoverySelection('Zebra', '2600.00');
        $second = discoverySelection('Aardvark', '2600.00');

        expect(array_column(discoveryList()->assertOk()->json('data'), 'id'))
            ->toBe([$first->product->public_id, $second->product->public_id]);
    });

    it('sorts only within the filters', function () {
        discoverySelection('Kettle large', '3000.00');
        discoverySelection('Kettle small', '1000.00');
        discoverySelection('Panjabi', '500.00');

        expect(discoveryWalk(['sort' => 'price_asc', 'q' => 'kettle']))->toBe(['Kettle small', 'Kettle large']);
    });
});

describe('facets (C2)', function () {
    it('describes only what this website has on sale', function () {
        $walton = Brand::create(['name' => 'Walton']);
        $hidden = Brand::create(['name' => 'Hidden']);
        $elsewhere = Brand::create(['name' => 'Elsewhere']);

        $shirt = discoverySelection('Shirt', '2600.00', product: ['brand_id' => $walton->id]);
        discoveryVariant($shirt->product, 'SHIRT-NAVY-M', ['colour' => 'Navy']);
        discoverySelection('Kettle', '1800.00', ['promotional_price' => Money::fromDecimal('1200.00', Currency::BDT)], ['brand_id' => $walton->id]);

        $unpublished = discoverySelection('Unpublished', '9000.00', ['status' => WebsiteProductStatus::Selected, 'published_at' => null], ['brand_id' => $hidden->id]);
        discoveryVariant($unpublished->product, 'UNPUB-GOLD', ['colour' => 'Gold']);

        $other = Website::factory()->forAccount(websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']))->active()->create();
        discoverySelection('Theirs', '50.00', website: $other, product: ['brand_id' => $elsewhere->id]);

        $facets = discoveryList(path: 'products/facets')->assertOk();

        $facets->assertJsonPath('data.brands', [[
            'id' => $walton->public_id, 'slug' => $walton->slug, 'name' => 'Walton', 'products' => 2,
        ]])
            ->assertJsonPath('data.attributes', [[
                'slug' => 'colour', 'name' => 'Colour', 'values' => [['value' => 'Navy', 'products' => 1]],
            ]])
            ->assertJsonPath('data.price_range.minimum.amount', '1200.00')
            ->assertJsonPath('data.price_range.maximum.amount', '2600.00')
            ->assertJsonPath('data.price_range.maximum.minor_units', 260000);

        expect($facets->getContent())->not->toContain('Hidden')->not->toContain('Gold')->not->toContain('Elsewhere');
    });

    it('narrows with the same filters as the list', function () {
        $walton = Brand::create(['name' => 'Walton']);
        discoverySelection('Walton kettle', '2600.00', product: ['brand_id' => $walton->id]);
        discoverySelection('Plain panjabi', '900.00');

        discoveryList(['q' => 'panjabi'], 'products/facets')->assertOk()
            ->assertJsonPath('data.brands', [])
            ->assertJsonPath('data.price_range.minimum.amount', '900.00')
            ->assertJsonPath('data.price_range.maximum.amount', '900.00');

        discoveryList(['q' => 'nothing matches'], 'products/facets')->assertOk()->assertJsonPath('data.price_range', null);
    });

    it('needs the catalogue scope', function () {
        [$credential, $secret] = storefrontCredential($this->website, [CredentialScope::OrdersRead]);

        storefrontCall($credential, $secret, 'products/facets')->assertForbidden();
    });
});
