<?php

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Testing\TestResponse;

/**
 * Buying a product makes it eligible; the owner then chooses, storefront by
 * storefront, which of what they bought to put on sale (D34). Nothing reaches a
 * storefront because it was bought.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->first = Website::factory()->forAccount($this->account)->active()->create(['name' => 'First shop']);
    $this->second = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Second shop']);

    $this->scopes = [CredentialScope::CatalogRead, CredentialScope::InventoryRead];
    $this->firstApi = storefrontCredential($this->first, $this->scopes);
    $this->secondApi = storefrontCredential($this->second, $this->scopes);

    $this->product = websiteTestProduct();
});

/**
 * @param  array{0: mixed, 1: string}  $credential
 */
function myProductsListing(array $credential): TestResponse
{
    return storefrontCall($credential[0], $credential[1], 'products');
}

function myProductsPublish(Website $website, $product, bool $published, $actingAs = null): TestResponse
{
    return test()->actingAs($actingAs ?? test()->account->owner)
        ->put(route('catalog.mine.websites.update', [$product->slug, $website->public_id]), ['published' => $published]);
}

describe('what a purchase does and does not do', function () {
    it('keeps a bought but unselected product off every storefront', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);

        myProductsListing($this->firstApi)->assertOk()->assertJsonCount(0, 'data');
        myProductsListing($this->secondApi)->assertOk()->assertJsonCount(0, 'data');
        // Not even its stock is told: the storefront does not know the product exists.
        storefrontCall($this->firstApi[0], $this->firstApi[1], 'inventory/'.$this->product->sku)->assertNotFound();
        storefrontCall($this->firstApi[0], $this->firstApi[1], 'products/'.$this->product->public_id)->assertNotFound();
    });

    it('puts it on the storefront the owner chose, and only that one, with the units left', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);

        myProductsPublish($this->first, $this->product, true)->assertRedirect();

        myProductsListing($this->firstApi)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->public_id)
            ->assertJsonPath('data.0.variants.0.availability.quantity', 20);
        myProductsListing($this->secondApi)->assertOk()->assertJsonCount(0, 'data');
        storefrontCall($this->secondApi[0], $this->secondApi[1], 'products/'.$this->product->public_id)->assertNotFound();
    });

    it('takes it off again without losing the selection, the purchase or the stock', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);
        myProductsPublish($this->first, $this->product, true);

        myProductsPublish($this->first, $this->product, false)->assertRedirect();

        $selection = WebsiteProduct::query()->where('website_id', $this->first->id)->sole();

        expect($selection->status)->toBe(WebsiteProductStatus::Unpublished)
            ->and($selection->price)->not->toBeNull();
        myProductsListing($this->firstApi)->assertOk()->assertJsonCount(0, 'data');

        // And back on, as one click.
        myProductsPublish($this->first, $this->product, true)->assertRedirect();
        myProductsListing($this->firstApi)->assertOk()->assertJsonCount(1, 'data');
        expect(WebsiteProduct::query()->where('website_id', $this->first->id)->count())->toBe(1);
    });

    it('stops showing it on a storefront when what was bought no longer counts', function () {
        $order = websiteTestPurchase($this->account, $this->product, quantity: 5);
        myProductsPublish($this->first, $this->product, true);
        myProductsListing($this->firstApi)->assertJsonCount(1, 'data');

        // The purchase is taken back in full: its units leave the owner's stock.
        app(PartnerStock::class)->reverse($this->account, $this->product->id, null, 5);

        myProductsListing($this->firstApi)->assertOk()->assertJsonCount(0, 'data');
        expect(WebsiteProduct::query()->where('website_id', $this->first->id)->count())->toBe(1)
            ->and($order->refresh()->items()->count())->toBe(1);
    });
});

describe('what the owner may and may not publish', function () {
    it('refuses a product that was never bought', function () {
        myProductsPublish($this->first, $this->product, true)->assertSessionHasErrors('website');

        expect(WebsiteProduct::query()->count())->toBe(0);
    });

    it('refuses to publish when no unit is left to sell', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 3);
        $held = app(StockReservations::class)->reserveFromPartner($this->account, $this->product, null, 3, ReservationKind::OnlinePayment, 'MP-1-L1');
        app(StockReservations::class)->commit($held);

        myProductsPublish($this->first, $this->product, true)
            ->assertSessionHasErrors(['website' => 'First shop: '.__('website.refused.nothing_to_sell')]);

        expect(WebsiteProduct::query()->where('status', WebsiteProductStatus::Published)->count())->toBe(0);
    });

    it('is the owner\'s alone: another partner cannot touch these storefronts', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);
        $stranger = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);

        myProductsPublish($this->first, $this->product, true, $stranger->owner)->assertNotFound();

        expect(WebsiteProduct::query()->count())->toBe(0);
    });

    it('does not let an owner put a product on sale that another partner bought', function () {
        $stranger = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
        websiteTestPurchase($stranger, $this->product, quantity: 20);

        myProductsPublish($this->first, $this->product, true)->assertSessionHasErrors('website');

        myProductsListing($this->firstApi)->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('what each variation owns', function () {
    it('sells the exact variation that was bought and no other', function () {
        $medium = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);
        $large = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-PJ-L', 'combination_key' => 'l']);

        websiteTestPurchase($this->account, $this->product, quantity: 7, variant: $medium);
        myProductsPublish($this->first, $this->product, true)->assertRedirect();

        $variants = collect(myProductsListing($this->firstApi)->assertOk()->json('data.0.variants'))->keyBy('sku');

        expect($variants['FW-PJ-M']['availability']['quantity'])->toBe(7)
            ->and($variants['FW-PJ-M']['availability']['in_stock'])->toBeTrue()
            ->and($variants['FW-PJ-L']['availability']['quantity'])->toBe(0)
            ->and($variants['FW-PJ-L']['availability']['in_stock'])->toBeFalse()
            ->and($large->id)->not->toBe($medium->id);
    });
});

describe('the My Products screen', function () {
    it('shows what is owned, held, sold and unavailable, and where it is on sale', function () {
        websiteTestPurchase($this->account, $this->product, quantity: 20);
        websiteTestPurchase($this->account, $this->product, quantity: 5);
        $held = app(StockReservations::class)->reserveFromPartner($this->account, $this->product, null, 4, ReservationKind::OnlinePayment, 'MP-2-L1');
        app(StockReservations::class)->reserveFromPartner($this->account, $this->product, null, 3, ReservationKind::OnlinePayment, 'MP-3-L1');
        app(StockReservations::class)->commit($held);
        myProductsPublish($this->first, $this->product, true);

        $this->actingAs($this->account->owner)
            ->get(route('catalog.mine'))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 1)
                ->where('products.data.0.ownership.totals', [
                    'purchased' => 25, 'available' => 18, 'reserved' => 3, 'sold' => 4, 'unavailable' => 0,
                ])
                ->where('products.data.0.ownership.storefronts.0', ['id' => $this->first->public_id, 'name' => 'First shop', 'state' => 'published'])
                ->where('products.data.0.ownership.storefronts.1', ['id' => $this->second->public_id, 'name' => 'Second shop', 'state' => 'not_selected']));
    });

    it('never shows another partner\'s units or storefronts', function () {
        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);
        Website::factory()->forAccount($other)->active()->create(['name' => 'Their shop']);
        websiteTestPurchase($other, $this->product, quantity: 99);
        websiteTestPurchase($this->account, $this->product, quantity: 2);

        $this->actingAs($this->account->owner)
            ->get(route('catalog.mine'))
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.ownership.totals.purchased', 2)
                ->where('products.data.0.ownership.storefronts', fn ($shops) => collect($shops)->pluck('name')->sort()->values()->all() === ['First shop', 'Second shop']));
    });
});
