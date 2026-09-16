<?php

use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;

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
        'price_minor' => 260000,
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
            'price_minor' => 300000,
            'promotional_price_minor' => 270000,
            'promo_title' => 'Eid offer',
        ]);

        storefrontCall($this->credential, $this->secret, 'products/'.$selection->product->public_id)
            ->assertOk()
            ->assertJsonPath('variants.0.price.minor_units', 270000)
            ->assertJsonPath('variants.0.compare_at_price.minor_units', 300000)
            ->assertJsonPath('promo_title', 'Eid offer');
    });

    it('never carries the wholesale price, the cost or the margin', function () {
        $selection = storefrontSelection($this->website);

        $body = storefrontCall($this->credential, $this->secret, 'products/'.$selection->product->public_id)
            ->assertOk()
            ->getContent();

        foreach (['wholesale', 'base_cost', 'cost_minor', 'margin', 'minimum_selling', 'maximum_selling'] as $forbidden) {
            expect($body)->not->toContain($forbidden);
        }

        // 150,000 is the product's wholesale price in the fixture.
        expect($body)->not->toContain('150000');
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
