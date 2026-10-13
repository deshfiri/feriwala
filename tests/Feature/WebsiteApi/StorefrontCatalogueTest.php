<?php

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * A storefront reading what it sells (contract §5.1, §5.2, P5-22).
 *
 * Only this website's published selections, at this website's price, and never
 * a figure that would let the storefront work out what Feriwala paid.
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
 * A selection on the test website.
 *
 * @param  array<string, mixed>  $attributes
 */
function storefrontSelection(Website $website, array $attributes = [], array $productAttributes = []): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct($productAttributes)->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
        ...$attributes,
    ]);
}

describe('products', function () {
    it('lists this website\'s published products and nothing it has not published', function () {
        $published = storefrontSelection($this->website);
        storefrontSelection($this->website, ['status' => WebsiteProductStatus::Selected, 'published_at' => null]);

        $response = storefrontCall($this->credential, $this->secret, 'products');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->product->public_id)
            ->assertJsonPath('meta.has_more', false);
    });

    it('sells at the website\'s own price, with a promotion shown against the regular price', function () {
        $selection = storefrontSelection($this->website, [
            'price' => Money::fromDecimal('3000.00', Currency::BDT),
            'promotional_price' => Money::fromDecimal('2700.00', Currency::BDT),
            'promo_title' => 'Eid offer',
        ]);

        storefrontCall($this->credential, $this->secret, 'products/'.$selection->product->public_id)
            ->assertOk()
            // Both the flat-Taka `amount` and the frozen contract's legacy
            // `minor_units` compatibility key are carried (§4.1).
            ->assertJsonPath('variants.0.price.amount', '2700.00')
            ->assertJsonPath('variants.0.price.minor_units', 270000)
            ->assertJsonPath('variants.0.compare_at_price.amount', '3000.00')
            ->assertJsonPath('variants.0.compare_at_price.minor_units', 300000)
            ->assertJsonPath('promo_title', 'Eid offer');
    });

    it('never carries the wholesale price, the cost or the margin', function () {
        $selection = storefrontSelection($this->website);

        $body = storefrontCall($this->credential, $this->secret, 'products/'.$selection->product->public_id)
            ->assertOk()
            ->getContent();

        foreach (['wholesale', 'base_cost', 'margin', 'minimum_selling', 'maximum_selling'] as $forbidden) {
            expect($body)->not->toContain($forbidden);
        }

        // 1,500.00 Taka is the product's wholesale price in the fixture.
        expect($body)->not->toContain('1500.00');
    });

    it('answers only what changed since a moment', function () {
        $old = storefrontSelection($this->website);
        $old->product->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
        WebsiteProduct::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(3)]);

        $fresh = storefrontSelection($this->website);

        storefrontCall($this->credential, $this->secret, 'products', [
            'updated_since' => now()->subDay()->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fresh->product->public_id);
    });

    it('pages by cursor within the contract\'s bounds', function () {
        foreach (range(1, 3) as $ignored) {
            storefrontSelection($this->website);
        }

        $first = storefrontCall($this->credential, $this->secret, 'products', ['limit' => 2]);

        $first->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $second = storefrontCall($this->credential, $this->secret, 'products', [
            'limit' => 2,
            'cursor' => $first->json('meta.next_cursor'),
        ]);

        $second->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
    });

    it('places a product in the shop\'s own category where it has one', function () {
        $category = WebsiteCategory::create([
            'website_id' => $this->website->id,
            'name' => 'Eid collection',
            'slug' => 'eid-collection',
            'position' => 1,
        ]);

        $selection = storefrontSelection($this->website, ['website_category_id' => $category->id]);

        storefrontCall($this->credential, $this->secret, 'products/'.$selection->product->public_id)
            ->assertOk()
            ->assertJsonPath('categories.0.slug', 'eid-collection');
    });
});

describe('categories', function () {
    it('lists the shop\'s visible categories in its own order', function () {
        WebsiteCategory::create(['website_id' => $this->website->id, 'name' => 'Second', 'slug' => 'second', 'position' => 2]);
        WebsiteCategory::create(['website_id' => $this->website->id, 'name' => 'First', 'slug' => 'first', 'position' => 1]);
        WebsiteCategory::create(['website_id' => $this->website->id, 'name' => 'Hidden', 'slug' => 'hidden', 'position' => 3, 'is_active' => false]);

        storefrontCall($this->credential, $this->secret, 'categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'first')
            ->assertJsonPath('data.1.slug', 'second');
    });
});

describe('inventory', function () {
    it('answers for SKUs this website sells, and leaves out the ones it does not', function () {
        $sold = storefrontSelection($this->website);
        $notSold = websiteTestProduct();

        $response = storefrontCall($this->credential, $this->secret, 'inventory', [
            'skus' => [$sold->product->sku, $notSold->sku],
        ]);

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $sold->product->sku);

        // Availability only: no warehouse, no reservation.
        expect(array_keys($response->json('data.0')))->toEqualCanonicalizing(['sku', 'in_stock', 'quantity', 'updated_at']);

        storefrontCall($this->credential, $this->secret, 'inventory/'.$notSold->sku)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    });
});

/**
 * The list, the detail and the stock endpoint all agree that `$selection` is
 * not on sale.
 */
function storefrontCatalogueAbsent(mixed $test, WebsiteProduct $selection): void
{
    $product = Product::withTrashed()->findOrFail($selection->product_id);

    storefrontCall($test->credential, $test->secret, 'products')
        ->assertOk()
        ->assertJsonMissing(['id' => $product->public_id]);

    storefrontCall($test->credential, $test->secret, 'products/'.$product->public_id)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    storefrontCall($test->credential, $test->secret, 'inventory/'.$product->sku)->assertNotFound();
}

describe('what is on sale (P2 H9)', function () {
    it('never lists a product that has been trashed', function () {
        $trashed = storefrontSelection($this->website);
        $kept = storefrontSelection($this->website);
        $trashed->product->delete();

        storefrontCatalogueAbsent($this, $trashed);

        storefrontCall($this->credential, $this->secret, 'products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->product->public_id);
    });

    it('never lists a product this website\'s owner may not sell by dropshipping', function (array $product) {
        $ineligible = storefrontSelection($this->website, productAttributes: $product);

        storefrontCatalogueAbsent($this, $ineligible);
    })->with([
        'dropshipping switched off' => [['dropshipping_status' => ProductStatus::DropshippingDisabled]],
        'not active' => [['status' => ProductStatus::Inactive]],
    ]);

    it('never lists a product whose category has been switched off', function () {
        $selection = storefrontSelection($this->website);
        $selection->product->category->forceFill(['is_active' => false])->save();

        storefrontCatalogueAbsent($this, $selection);
    });

    it('never lists a selection that is not published', function () {
        $selected = storefrontSelection($this->website, ['status' => WebsiteProductStatus::Unpublished, 'published_at' => null]);

        storefrontCatalogueAbsent($this, $selected);
    });

    it('never lists another website\'s products, even ones it publishes', function () {
        $other = Website::factory()->forAccount(websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
        ]))->active()->create();
        $theirs = storefrontSelection($other);

        storefrontCatalogueAbsent($this, $theirs);
    });

    it('lists nothing for an owner whose package does not include dropshipping', function () {
        $account = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '0']);
        $website = Website::factory()->forAccount($account)->active()->create();
        [$credential, $secret] = storefrontCredential($website);
        storefrontSelection($website);

        storefrontCall($credential, $secret, 'products')->assertOk()->assertJsonCount(0, 'data');
    });
});
